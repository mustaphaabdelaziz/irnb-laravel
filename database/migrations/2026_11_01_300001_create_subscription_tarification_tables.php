<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_tarifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sessions_per_week')->nullable(); // Blank = the schedule's count.
            $table->unsignedTinyInteger('weeks')->default(40);
            $table->unsignedInteger('expected_players')->nullable(); // Blank = the auto count.
            $table->unsignedTinyInteger('expected_payment_rate')->default(100); // Percent.
            $table->timestamps();
        });

        Schema::create('subscription_cost_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('unit', 20);
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_cost_lines');
        Schema::dropIfExists('subscription_tarifications');
    }
};
