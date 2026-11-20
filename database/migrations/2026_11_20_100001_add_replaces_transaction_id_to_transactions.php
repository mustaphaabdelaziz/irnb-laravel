<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A corrected player payment is archived and recorded again. The new row
     * points at the one it replaces, so each page can link to the other.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('replaces_transaction_id')->nullable()->after('payment_group')
                ->constrained('transactions')->nullOnDelete();

            // SQLite does not index a foreign key column; the page and every
            // transaction delete look replacements up by it.
            $table->index('replaces_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // The key before its index (MySQL needs an index for a key), the
            // index before the column (SQLite cannot drop an indexed column).
            $table->dropForeign(['replaces_transaction_id']);
            $table->dropIndex(['replaces_transaction_id']);
            $table->dropColumn('replaces_transaction_id');
        });
    }
};
