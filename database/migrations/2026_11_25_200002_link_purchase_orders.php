<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders ↔ payment orders and equipment.
 *
 * payment_orders.purchase_order_id exists since P1 without a key. On SQLite a
 * key on an existing column means rebuilding the table; outside a transaction
 * Laravel can switch foreign_keys off for that rebuild, so nothing cascades.
 * equipment_items is never rebuilt on SQLite (its history, branch links and
 * stock-take lines would cascade away): the new column is added with a plain
 * ALTER TABLE … REFERENCES. Every step checks its own work, so a run that
 * failed half way finishes on the next run, and a second run changes nothing.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! $this->hasForeignKey('payment_orders', 'purchase_order_id')) {
            Schema::table('payment_orders', fn (Blueprint $t) => $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete());
        }
        if ($this->isSqlite() && ! Schema::hasIndex('payment_orders', 'payment_orders_purchase_order_id_index')) {
            Schema::table('payment_orders', fn (Blueprint $t) => $t->index('purchase_order_id'));
        }

        if (! Schema::hasColumn('equipment_items', 'purchase_order_id')) {
            if ($this->isSqlite()) {
                DB::statement('ALTER TABLE equipment_items ADD COLUMN purchase_order_id INTEGER NULL REFERENCES purchase_orders(id) ON DELETE SET NULL');
            } else {
                Schema::table('equipment_items', fn (Blueprint $t) => $t->foreignId('purchase_order_id')->nullable()->after('purchase_transaction_id')->constrained('purchase_orders')->nullOnDelete());
            }
        }
        if ($this->isSqlite() && ! Schema::hasIndex('equipment_items', 'equipment_items_purchase_order_id_index')) {
            Schema::table('equipment_items', fn (Blueprint $t) => $t->index('purchase_order_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('equipment_items', 'purchase_order_id')) {
            if ($this->isSqlite() && Schema::hasIndex('equipment_items', 'equipment_items_purchase_order_id_index')) {
                Schema::table('equipment_items', fn (Blueprint $t) => $t->dropIndex('equipment_items_purchase_order_id_index'));
            }
            Schema::table('equipment_items', function (Blueprint $table) {
                $table->dropForeign(['purchase_order_id']);
                $table->dropColumn('purchase_order_id');
            });
        }
        if ($this->hasForeignKey('payment_orders', 'purchase_order_id')) {
            Schema::table('payment_orders', fn (Blueprint $t) => $t->dropForeign(['purchase_order_id']));
        }
        if ($this->isSqlite() && Schema::hasIndex('payment_orders', 'payment_orders_purchase_order_id_index')) {
            Schema::table('payment_orders', fn (Blueprint $t) => $t->dropIndex('payment_orders_purchase_order_id_index'));
        }
    }

    private function isSqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(fn (array $fk) => $fk['columns'] === [$column]);
    }
};
