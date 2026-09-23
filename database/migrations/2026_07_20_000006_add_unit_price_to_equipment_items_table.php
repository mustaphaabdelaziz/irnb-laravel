<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one unit in this lot cost. The catalog's purchase_price is only a
     * reference value for the type; the real figure varies per batch — 50
     * dossards bought in March at 120 and 50 in January at 135 are two lots
     * with two prices. Asset value sums quantity x unit_price across lots.
     *
     * Until now the price entered when adding an item was never stored at
     * all: it was used to build an expense Transaction and then discarded.
     */
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->decimal('unit_price', 12, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropColumn('unit_price');
        });
    }
};
