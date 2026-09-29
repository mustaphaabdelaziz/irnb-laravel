<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every session is linked to each category taking part: one row for an
     * ordinary session, several for a joint pre-season session.
     * `training_sessions.category_id` stays the primary category, so the P1
     * unique slot key and the generator keep working unchanged.
     */
    public function up(): void
    {
        if (! Schema::hasTable('training_session_category')) {
            Schema::create('training_session_category', function (Blueprint $table) {
                $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
                $table->foreignId('category_id')->constrained()->cascadeOnDelete();
                $table->primary(['training_session_id', 'category_id']);
                $table->index(['category_id', 'training_session_id']);
            });
        }

        // Backfill: each session gets its primary category. Only missing rows
        // are inserted, so a second run (every desktop boot) adds nothing.
        DB::table('training_session_category')->insertOrIgnoreUsing(
            ['training_session_id', 'category_id'],
            DB::table('training_sessions as s')
                ->select('s.id', 's.category_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('training_session_category as p')
                    ->whereColumn('p.training_session_id', 's.id')
                    ->whereColumn('p.category_id', 's.category_id')),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('training_session_category');
    }
};
