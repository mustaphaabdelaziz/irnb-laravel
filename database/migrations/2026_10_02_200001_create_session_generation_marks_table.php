<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per category and month whose planned sessions were generated from
 * the current schedules, closures and sessions (see GenerationMarks). The
 * calendar skips generating a marked month, so opening it again writes
 * nothing; any change to those inputs deletes the affected marks and bumps
 * the single version counter, so a generation that read its inputs before
 * the change does not mark its month.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('session_generation_marks')) {
            Schema::create('session_generation_marks', function (Blueprint $table) {
                $table->foreignId('category_id')->constrained()->cascadeOnDelete();
                $table->string('month', 7); // Y-m
                $table->timestamp('created_at')->nullable();
                $table->primary(['category_id', 'month']);
            });
        }

        if (! Schema::hasTable('session_generation_version')) {
            Schema::create('session_generation_version', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('version')->default(0);
            });
            DB::table('session_generation_version')->insert(['id' => 1, 'version' => 0]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('session_generation_version');
        Schema::dropIfExists('session_generation_marks');
    }
};
