<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each mark keeps the category the player was in when it was recorded
     * (see MarkRecorder and AttendanceStats's class doc): a later roster move
     * must never rewrite which category a past mark belongs to, and a joint
     * (multi-category) session must attribute the mark to exactly one
     * category instead of the player's *current* one.
     *
     * Adding a nullable column is a plain ALTER TABLE ADD COLUMN on sqlite,
     * not a table rebuild (unlike ->change()/dropColumn(), see the theme
     * rename migration), so it is safe with existing rows and foreign keys.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('attendances', 'category_id')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->foreignId('category_id')->nullable()->after('player_id')
                    ->constrained()->nullOnDelete();
            });
        }

        $this->backfillCategoryId();
    }

    /**
     * Every existing mark takes the player's current category — the best
     * available guess for history recorded before this column existed. Only
     * rows still null are touched, so a second run (every desktop boot) adds
     * nothing. One update per distinct category (not per row, not a
     * cross-table UPDATE...JOIN, which sqlite does not support through the
     * query builder), so this stays a handful of queries regardless of
     * roster size.
     */
    public function backfillCategoryId(): void
    {
        $categoryIds = DB::table('players')->whereNotNull('category_id')->distinct()->pluck('category_id');

        foreach ($categoryIds as $categoryId) {
            DB::table('attendances')
                ->whereNull('category_id')
                ->whereIn('player_id', DB::table('players')->select('id')->where('category_id', $categoryId))
                ->update(['category_id' => $categoryId]);
        }
    }

    /**
     * `attendances` has no child tables, so a sqlite rebuild here would not
     * risk any cascade-delete (unlike training_sessions, see the theme
     * rename migration's down()). Even so, down() only undoes the DDL on
     * MySQL, where dropping a column is a plain, cheap ALTER: this migration
     * is meant to run forward on every deploy/boot, and down() is not part
     * of that path, so there is no reason to pay for a sqlite table rebuild
     * (and its doctrine/dbal dependency) just to support a rollback nobody
     * runs on desktop.
     */
    public function down(): void
    {
        if (Schema::hasColumn('attendances', 'category_id') && Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropConstrainedForeignId('category_id');
            });
        }
    }
};
