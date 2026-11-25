<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment orders (الأمر بالدفع): draft → approved → paid (the expense is then
 * created and linked) or cancelled. Numbered NN/YYYY per calendar year; legacy
 * rows imported from the old Access app keep their text reference and no
 * number. Orders are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('number')->nullable();
            $table->string('legacy_reference', 50)->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->string('legacy_source', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('order_date');
            $table->string('subject', 150);
            $table->decimal('amount', 12, 2);
            $table->foreignId('finance_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_method', 20)->nullable();
            $table->string('beneficiary_type', 20)->nullable();
            $table->unsignedBigInteger('beneficiary_id')->nullable();
            $table->string('beneficiary_name', 200)->nullable();
            $table->string('beneficiary_bank', 150)->nullable();
            $table->string('beneficiary_rib', 30)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->date('payment_date')->nullable();
            $table->string('invoice_number', 100)->nullable();
            $table->date('invoice_date')->nullable();
            $table->foreignId('board_meeting_id')->nullable()->constrained()->nullOnDelete();
            // FK added with purchase orders (P2).
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->text('observation')->nullable();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['year', 'number']);
            $table->index(['beneficiary_type', 'beneficiary_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
