<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a paper expense file carries (old Access app, owner 2026-11-25): the
 * supplier, their invoice, the cheque date (number stays in payment_reference)
 * and the board meeting that approved it — whose minutes get a PV number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('payment_reference')->constrained('suppliers')->nullOnDelete();
            $table->string('invoice_number', 100)->nullable()->after('supplier_id');
            $table->date('invoice_date')->nullable()->after('invoice_number');
            $table->date('cheque_date')->nullable()->after('invoice_date');
            $table->foreignId('board_meeting_id')->nullable()->after('cheque_date')->constrained('board_meetings')->nullOnDelete();
        });

        Schema::table('board_meetings', function (Blueprint $table) {
            $table->string('pv_number', 50)->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('board_meeting_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['invoice_number', 'invoice_date', 'cheque_date']);
        });

        Schema::table('board_meetings', function (Blueprint $table) {
            $table->dropColumn('pv_number');
        });
    }
};
