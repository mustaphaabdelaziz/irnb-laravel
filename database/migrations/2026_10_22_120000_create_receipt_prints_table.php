<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per printed receipt (thermal ticket or A4). The code printed under
     * the QR finds the row again; the snapshot is what the paper says, so a later
     * check can tell an edited, cancelled or deleted payment from the printed one.
     * Rows outlive their transaction on purpose (transaction_number keeps its id).
     */
    public function up(): void
    {
        Schema::create('receipt_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('transaction_number')->index();
            $table->string('code', 12)->unique();
            $table->unsignedInteger('copy_number');
            $table->string('format', 10);
            $table->foreignId('printed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_prints');
    }
};
