<?php

use App\Models\EquipmentCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Short code used to build equipment item serials
     * ({CLUB}-{YYYY}-{CODE}-{NNNNN}). Backfilled from the category name so
     * generation never hits a null code; admins can refine in Settings.
     */
    public function up(): void
    {
        Schema::table('equipment_categories', function (Blueprint $table) {
            $table->string('code')->nullable()->after('name');
        });

        foreach (DB::table('equipment_categories')->get() as $category) {
            DB::table('equipment_categories')
                ->where('id', $category->id)
                ->update(['code' => EquipmentCategory::deriveCode($category->name)]);
        }
    }

    public function down(): void
    {
        Schema::table('equipment_categories', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
