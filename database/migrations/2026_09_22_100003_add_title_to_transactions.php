<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A short, human title for a transaction. Nullable: existing rows and
     * everything the app records by itself (subscription payments, donations,
     * imports) get a label generated at render time instead — see
     * App\Support\TransactionTitle.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('title', 150)->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
