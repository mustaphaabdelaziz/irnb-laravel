<?php

use App\Support\FinanceCategoryTranslations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->string('name_ar')->nullable()->after('name');
            $table->string('name_fr')->nullable()->after('name_ar');
            $table->string('name_en')->nullable()->after('name_fr');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropColumn(['name_ar', 'name_fr', 'name_en']);
        });
    }

    /**
     * Fill empty ar/fr names for categories whose base name is a known one.
     * Matched by name, never by code, so renamed built-ins keep their own
     * label; name_en is left to the base-name fallback. Only null columns are
     * written, so re-running is safe and user-entered translations survive.
     */
    public function backfill(): void
    {
        foreach (DB::table('finance_categories')->get() as $row) {
            $stock = FinanceCategoryTranslations::for((string) $row->name);
            if (! $stock) {
                continue;
            }

            $updates = array_filter($stock, fn ($value, $column) => $row->{$column} === null, ARRAY_FILTER_USE_BOTH);
            if ($updates) {
                DB::table('finance_categories')->where('id', $row->id)->update($updates);
            }
        }
    }
};
