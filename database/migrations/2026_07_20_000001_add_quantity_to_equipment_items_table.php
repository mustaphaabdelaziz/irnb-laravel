<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A row in equipment_items stops meaning "one physical object" and starts
     * meaning a LOT: N identical units sharing a catalog, condition, purchase
     * date and price. A serialized item is the lot of size 1, so every
     * existing row is already correct at quantity = 1.
     *
     * `received_via` is a plain string, not an enum: SQLite implements enum()
     * as a varchar plus a check constraint, and that constraint can never be
     * altered afterwards. Allowed values are enforced in ReceiveStockRequest.
     */
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->after('catalog_id');
            $table->string('received_via')->default('purchase')->after('purchase_date');
        });

        // Serials belong to individually-tracked lots only. A lot of 100
        // dossards has no serial, so the column becomes nullable. SQLite
        // permits many NULLs under a unique index, which is exactly right.
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->string('unique_identifier')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'received_via']);
        });

        Schema::table('equipment_items', function (Blueprint $table) {
            $table->string('unique_identifier')->nullable(false)->change();
        });
    }
};
