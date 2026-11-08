<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Literary report in the layout of the club's own 2025 report (2026-11-08).
 * Activity types act as the report's chapters. Sports participations carry an
 * organizer and a result, and a free date label can replace the computed date.
 * One photo per activity goes to the photo appendix. Each report gets a note,
 * a details option and two signers.
 */
return new class extends Migration
{
    /** The model report's chapters, in print order. */
    private const CHAPTERS = ['تنظيمات النادي', 'المشاركات الرياضية', 'الحملات التطوعية', 'التكوينات', 'إعلانات النادي'];

    private const NEW_SECTIONS = ['signatures', 'photos'];

    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('organizer', 150)->nullable();
            $table->string('result', 150)->nullable();
            $table->string('date_label', 100)->nullable();
        });

        Schema::table('literary_reports', function (Blueprint $table) {
            $table->text('note')->nullable();
            $table->boolean('include_details')->default(false);
            $table->foreignId('signer_1_board_member_id')->nullable()->constrained('board_members')->nullOnDelete();
            $table->foreignId('signer_2_board_member_id')->nullable()->constrained('board_members')->nullOnDelete();
        });

        $this->addChapterTypes();
        $this->keepOneReportPhoto();
        $this->appendSections();
    }

    public function down(): void
    {
        DB::table('literary_reports')->orderBy('id')->get(['id', 'sections'])->each(function ($report) {
            $sections = array_values(array_diff(json_decode((string) $report->sections, true) ?: [], self::NEW_SECTIONS));
            DB::table('literary_reports')->where('id', $report->id)->update(['sections' => json_encode($sections)]);
        });

        // Chapter types still in use stay; the others go, and the old types move back up.
        $used = DB::table('activities')->distinct()->pluck('activity_type_id')->all();
        DB::table('activity_types')->whereIn('name', self::CHAPTERS)->whereNotIn('id', $used)->delete();
        DB::table('activity_types')->where('sort_order', '>', count(self::CHAPTERS))->decrement('sort_order', count(self::CHAPTERS));

        Schema::table('literary_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signer_2_board_member_id');
            $table->dropConstrainedForeignId('signer_1_board_member_id');
            $table->dropColumn(['note', 'include_details']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['organizer', 'result', 'date_label']);
        });
    }

    /** Chapters take sort orders 1–5 (an existing type of the same name is reused); every other type moves down by 5. */
    private function addChapterTypes(): void
    {
        $now = now();
        DB::table('activity_types')->increment('sort_order', count(self::CHAPTERS));

        foreach (self::CHAPTERS as $i => $name) {
            $id = DB::table('activity_types')->where('name', $name)->value('id');
            if ($id !== null) {
                DB::table('activity_types')->where('id', $id)->update(['sort_order' => $i + 1, 'updated_at' => $now]);
            } else {
                DB::table('activity_types')->insert(['name' => $name, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    /** One report photo per activity: the first flagged one by (sort_order, id) keeps the mark. */
    private function keepOneReportPhoto(): void
    {
        $extra = DB::table('activity_photos')->where('in_report', true)
            ->orderBy('activity_id')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'activity_id'])
            ->groupBy('activity_id')
            ->flatMap(fn ($photos) => $photos->slice(1)->pluck('id'))
            ->all();

        foreach (array_chunk($extra, 500) as $ids) {
            DB::table('activity_photos')->whereIn('id', $ids)->update(['in_report' => false]);
        }
    }

    /** Existing reports print the new signatures and photo appendix without a manual edit. */
    private function appendSections(): void
    {
        DB::table('literary_reports')->orderBy('id')->get(['id', 'sections'])->each(function ($report) {
            $sections = json_decode((string) $report->sections, true) ?: [];
            $sections = array_values(array_unique(array_merge($sections, self::NEW_SECTIONS)));
            DB::table('literary_reports')->where('id', $report->id)->update(['sections' => json_encode($sections)]);
        });
    }
};
