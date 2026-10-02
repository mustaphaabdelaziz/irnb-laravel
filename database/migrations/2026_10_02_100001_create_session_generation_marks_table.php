<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per category and month whose planned sessions were generated from
 * the current schedules, closures and sessions (see GenerationMarks). The
 * calendar skips generating a marked month, so opening it again writes
 * nothing; any change to those inputs deletes the affected marks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('session_generation_marks')) {
            return;
        }

        Schema::create('session_generation_marks', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7); // Y-m
            $table->timestamp('created_at')->nullable();
            $table->primary(['category_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_generation_marks');
    }
};
