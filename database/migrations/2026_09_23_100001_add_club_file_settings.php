<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Two club settings, seeded so an existing install has them without
     * anyone visiting the settings page: the month a season starts (September
     * here) and how many paper files fit in one drawer.
     *
     * Reference data goes in a migration because the desktop build runs
     * migrate on boot and never runs seeders.
     */
    public function up(): void
    {
        $row = DB::table('website_configs')->orderBy('id')->first();

        if ($row === null) {
            return; // A fresh install seeds these through WebsiteConfig::singleton().
        }

        $settings = json_decode((string) $row->settings, true) ?: [];
        $settings['seasonStartMonth'] ??= 9;
        $settings['fileDrawerSize'] ??= 100;

        DB::table('website_configs')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
    }

    public function down(): void
    {
        $row = DB::table('website_configs')->orderBy('id')->first();

        if ($row === null) {
            return;
        }

        $settings = json_decode((string) $row->settings, true) ?: [];
        unset($settings['seasonStartMonth'], $settings['fileDrawerSize']);

        DB::table('website_configs')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
    }
};
