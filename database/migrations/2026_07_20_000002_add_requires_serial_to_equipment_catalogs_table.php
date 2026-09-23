<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_catalogs', function (Blueprint $table) {
            $table->boolean('requires_serial')->default(false)->after('category');
        });

        // Existing catalogs already hold serialized items with generated
        // serials; defaulting them to count-tracking would contradict their
        // own data. Only catalogs created from now on get the bulk-friendly
        // default, chosen because most club equipment is counted, not serialized.
        DB::table('equipment_catalogs')->update(['requires_serial' => true]);

        // item_count was never maintained by any live code path — only the
        // legacy Mongo importer wrote it — so it silently drifted from the
        // truth. Counts derive from SUM(quantity) now.
        Schema::table('equipment_catalogs', function (Blueprint $table) {
            $table->dropColumn('item_count');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_catalogs', function (Blueprint $table) {
            $table->dropColumn('requires_serial');
            $table->unsignedInteger('item_count')->default(0);
        });
    }
};
