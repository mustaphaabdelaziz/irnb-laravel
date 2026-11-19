<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Units of the lot that were out on loan when the count sheet was made.
     * They are not expected on the shelf; the line only notes them.
     */
    public function up(): void
    {
        Schema::table('inventory_session_items', function (Blueprint $table) {
            $table->unsignedInteger('on_loan_quantity')->default(0)->after('expected_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_session_items', function (Blueprint $table) {
            $table->dropColumn('on_loan_quantity');
        });
    }
};
