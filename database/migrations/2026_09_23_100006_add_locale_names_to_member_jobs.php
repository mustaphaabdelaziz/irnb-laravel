<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jobs get a name per language, like categories and player statuses.
     *
     * The names already in the table were seeded in French, so they become the
     * French column; `name` stays as the fallback HasLocalizedName reads.
     *
     * Guarded: the desktop build runs `migrate` on every boot. If the backfill
     * below ever threw after this DDL had already committed, SQLite won't roll
     * back the column adds even though the migration itself stays unrecorded —
     * every later boot would re-run up() and die on "duplicate column name"
     * before the app ever starts again.
     */
    public function up(): void
    {
        Schema::table('member_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('member_jobs', 'name_ar')) {
                $table->string('name_ar')->nullable()->after('name');
            }
            if (! Schema::hasColumn('member_jobs', 'name_fr')) {
                $table->string('name_fr')->nullable()->after('name_ar');
            }
            if (! Schema::hasColumn('member_jobs', 'name_en')) {
                $table->string('name_en')->nullable()->after('name_fr');
            }
        });

        DB::table('member_jobs')->whereNull('name_fr')->update(['name_fr' => DB::raw('name')]);
    }

    public function down(): void
    {
        Schema::table('member_jobs', function (Blueprint $table) {
            $columns = array_filter(
                ['name_ar', 'name_fr', 'name_en'],
                fn (string $column) => Schema::hasColumn('member_jobs', $column)
            );

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
