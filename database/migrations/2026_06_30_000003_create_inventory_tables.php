<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A physical stock-take / audit of equipment, done periodically.
        Schema::create('inventory_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->enum('type', ['monthly', 'quarterly', 'yearly', 'ad_hoc'])->default('ad_hoc');
            $table->date('session_date');
            $table->enum('status', ['in_progress', 'completed', 'cancelled'])->default('in_progress')->index();
            $table->foreignId('conducted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_expected')->default(0);
            $table->unsignedInteger('total_found')->default(0);
            $table->unsignedInteger('total_missing')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // One line per equipment item being counted in a session.
        Schema::create('inventory_session_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_session_id')->constrained('inventory_sessions')->cascadeOnDelete();
            $table->foreignId('equipment_item_id')->constrained('equipment_items')->cascadeOnDelete();
            $table->string('expected_status');
            $table->string('expected_condition')->nullable();
            $table->string('expected_location')->nullable();
            $table->boolean('counted')->default(false);
            $table->boolean('found')->nullable();
            $table->string('actual_condition')->nullable();
            $table->string('actual_location')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['inventory_session_id', 'equipment_item_id']);
            $table->index(['inventory_session_id', 'counted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_session_items');
        Schema::dropIfExists('inventory_sessions');
    }
};
