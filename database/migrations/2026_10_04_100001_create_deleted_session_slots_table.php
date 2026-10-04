<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slots of regular sessions deleted for good: SessionGenerator never
 * recreates a session there from the weekly schedule. 'Y-m-d' date and
 * 'H:i' start, like training_sessions. A new table only, guarded, so a
 * second run (every desktop boot) does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deleted_session_slots')) {
            return;
        }

        Schema::create('deleted_session_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('date', 10);
            $table->string('start_time', 5);
            $table->timestamp('created_at')->nullable();
            $table->unique(['category_id', 'date', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_session_slots');
    }
};
