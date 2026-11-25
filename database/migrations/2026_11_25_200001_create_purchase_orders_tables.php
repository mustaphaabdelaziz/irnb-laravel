<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders (سند طلب / bon de commande): draft → sent → received, or
 * cancelled. Numbered NN/YYYY per calendar year (own sequence); the one legacy
 * order imported from the old Access app keeps its text reference and no
 * number. Orders are never deleted. Lines are an equipment catalog item or
 * free text; prices are optional until a payment order is made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('number')->nullable();
            $table->string('legacy_reference', 50)->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->string('legacy_source', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('order_date');
            // Required by the form; null only on the legacy order.
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requested_by', 200)->nullable();
            $table->string('delivery_mode', 200)->nullable();
            $table->string('payment_mode', 20)->nullable();
            $table->text('observation')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'number']);
            $table->index('status');
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_catalog_id')->nullable()->constrained('equipment_catalogs')->nullOnDelete();
            $table->string('designation', 255);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2)->nullable();
            // quantity × unit_price, computed server-side; null while unpriced.
            $table->decimal('amount', 14, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
