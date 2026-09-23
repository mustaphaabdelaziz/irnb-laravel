<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A count line was a yes/no tick against one physical object. A lot holds
     * many units, so the line records how many were expected and how many
     * were actually found. Serialized lines are simply expected 1.
     */
    public function up(): void
    {
        Schema::table('inventory_session_items', function (Blueprint $table) {
            $table->unsignedInteger('expected_quantity')->default(1)->after('expected_location');
            $table->unsignedInteger('found_quantity')->nullable()->after('found');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_session_items', function (Blueprint $table) {
            $table->dropColumn(['expected_quantity', 'found_quantity']);
        });
    }
};
