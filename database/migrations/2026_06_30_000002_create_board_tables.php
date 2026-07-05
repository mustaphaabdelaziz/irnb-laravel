<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->enum('role', ['president', 'vice_president', 'secretary', 'treasurer', 'member'])->default('member')->index();
            $table->string('photo_url')->nullable();
            $table->string('photo_filename')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->date('term_start')->nullable();
            $table->date('term_end')->nullable();
            $table->enum('status', ['active', 'former'])->default('active')->index();
            $table->text('bio')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('board_meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->enum('type', ['ordinary', 'extraordinary', 'general_assembly'])->default('ordinary')->index();
            $table->dateTime('meeting_date')->index();
            $table->string('location')->nullable();
            $table->json('agenda')->nullable();
            $table->enum('status', ['scheduled', 'held', 'cancelled'])->default('scheduled')->index();
            $table->unsignedSmallInteger('quorum_required')->nullable();
            $table->longText('minutes')->nullable();
            $table->json('decisions')->nullable();
            $table->string('attachment_url')->nullable();
            $table->string('attachment_filename')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('meeting_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_meeting_id')->constrained('board_meetings')->cascadeOnDelete();
            $table->foreignId('board_member_id')->constrained('board_members')->cascadeOnDelete();
            $table->enum('status', ['present', 'absent', 'excused'])->default('present');
            $table->timestamps();

            $table->unique(['board_meeting_id', 'board_member_id']);
        });

        Schema::create('board_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('board_member_id')->nullable()->constrained('board_members')->nullOnDelete();
            $table->foreignId('board_meeting_id')->nullable()->constrained('board_meetings')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->enum('status', ['not_started', 'in_progress', 'completed', 'cancelled'])->default('not_started')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_tasks');
        Schema::dropIfExists('meeting_attendances');
        Schema::dropIfExists('board_meetings');
        Schema::dropIfExists('board_members');
    }
};
