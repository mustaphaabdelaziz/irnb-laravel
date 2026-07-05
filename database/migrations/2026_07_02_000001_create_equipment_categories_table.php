<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Managed lookup for equipment/material categories. Replaces the list that
     * was hardcoded in the catalog Vue pages. `equipment_catalogs.category`
     * stays a string (matched by name) so no data migration is needed.
     */
    public function up(): void
    {
        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Seed with the previously hardcoded list plus any category names
        // already used by existing catalogs.
        $defaults = [
            'Balls', 'Goals & Nets', 'Training Equipment', 'Apparel',
            'Protective Gear', 'Accessories', 'Maintenance', 'Other',
        ];
        $existing = DB::table('equipment_catalogs')->whereNotNull('category')->distinct()->pluck('category')->all();

        $now = now();
        foreach (array_unique(array_merge($defaults, $existing)) as $name) {
            DB::table('equipment_categories')->insertOrIgnore([
                'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_categories');
    }
};
