<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Theme" becomes "Title / goal" (e.g. "Running 7.2 km in 40 min"), widened
     * from 100 to 150 characters. Each step is guarded so a half-applied run,
     * or a second run on a desktop boot, finishes cleanly.
     *
     * The widening runs only where a VARCHAR length is enforced (MySQL). On
     * sqlite the length is not enforced, and ->change() rebuilds the table:
     * inside the migration transaction `PRAGMA foreign_keys=OFF` is a no-op,
     * so the rebuild's DROP TABLE would cascade-delete every attendance mark.
     * The rename alone is a native ALTER TABLE RENAME COLUMN and is safe.
     */
    public function up(): void
    {
        if (Schema::hasColumn('training_sessions', 'theme') && ! Schema::hasColumn('training_sessions', 'title')) {
            Schema::table('training_sessions', function (Blueprint $table) {
                $table->renameColumn('theme', 'title');
            });
        }

        if (Schema::hasColumn('training_sessions', 'title') && Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('training_sessions', function (Blueprint $table) {
                $table->string('title', 150)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // The column keeps its 150 width: shrinking it could cut saved titles.
        if (Schema::hasColumn('training_sessions', 'title') && ! Schema::hasColumn('training_sessions', 'theme')) {
            Schema::table('training_sessions', function (Blueprint $table) {
                $table->renameColumn('title', 'theme');
            });
        }
    }
};
