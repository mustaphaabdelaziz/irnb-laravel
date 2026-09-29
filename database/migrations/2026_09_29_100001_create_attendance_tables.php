<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Player attendance. Dates are 'Y-m-d' and times 'H:i' strings so the
     * unique slot key and string comparisons behave the same on sqlite
     * (desktop) and MySQL (web).
     */
    public function up(): void
    {
        Schema::create('training_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // ISO: 1 = Monday … 7 = Sunday
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('valid_from', 10);
            $table->string('valid_to', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('club_closures', function (Blueprint $table) {
            $table->id();
            $table->string('start_date', 10);
            $table->string('end_date', 10);
            $table->string('reason', 100);
            $table->timestamps();
        });

        Schema::create('preseason_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season_start_year');
            $table->unsignedSmallInteger('target_count');
            $table->timestamps();
            $table->unique(['category_id', 'season_start_year']);
        });

        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('training_schedules')->nullOnDelete();
            $table->string('date', 10);
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('kind', 16);
            $table->string('state', 16);
            $table->string('cancel_reason', 255)->nullable();
            $table->string('moved_from', 10)->nullable();
            $table->string('coach', 100)->nullable();
            $table->string('theme', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['category_id', 'date', 'start_time']);
            $table->index(['category_id', 'state', 'date']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->string('reason', 16)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['training_session_id', 'player_id']);
            $table->index(['player_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('training_sessions');
        Schema::dropIfExists('preseason_targets');
        Schema::dropIfExists('club_closures');
        Schema::dropIfExists('training_schedules');
    }
};
