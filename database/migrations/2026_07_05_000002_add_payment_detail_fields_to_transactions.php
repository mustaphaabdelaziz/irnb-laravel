<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('payment_ccp_key')->nullable()->after('payment_account');
            $table->string('payment_bank_name')->nullable()->after('payment_ccp_key');
            $table->string('payment_holder')->nullable()->after('payment_bank_name');
            $table->string('payment_reference')->nullable()->after('payment_holder');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['payment_ccp_key', 'payment_bank_name', 'payment_holder', 'payment_reference']);
        });
    }
};
