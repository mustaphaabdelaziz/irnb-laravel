<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner decision (2026-10-07): the paper-folder file number is no longer
     * used. Drop the column and the "files per drawer" setting that located it.
     *
     * Guarded piece by piece: the desktop build runs `migrate` on every boot
     * and SQLite does not roll back DDL, so a re-run after a partial run must
     * not throw.
     */
    public function up(): void
    {
        if (Schema::hasIndex('players', ['file_number'], 'unique')) {
            Schema::table('players', function (Blueprint $table) {
                $table->dropUnique(['file_number']);
            });
        }

        if (Schema::hasColumn('players', 'file_number')) {
            Schema::table('players', function (Blueprint $table) {
                $table->dropColumn('file_number');
            });
        }

        $row = DB::table('website_configs')->orderBy('id')->first();

        if ($row !== null) {
            $settings = json_decode((string) $row->settings, true) ?: [];
            unset($settings['fileDrawerSize']);
            DB::table('website_configs')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
        }
    }

    /** Restores the empty column only; the dropped numbers are gone. */
    public function down(): void
    {
        if (! Schema::hasColumn('players', 'file_number')) {
            Schema::table('players', function (Blueprint $table) {
                $table->unsignedInteger('file_number')->nullable()->unique()->after('membership_id');
            });
        }
    }
};
