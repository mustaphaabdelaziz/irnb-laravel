<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            // Start year of the school year: 2025 means "2025/2026".
            $table->unsignedSmallInteger('academic_year');
            $table->string('education_level', 20);
            $table->string('institution')->nullable();
            $table->string('field_of_study')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'academic_year']);
        });

        Schema::create('player_academic_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('period', 10);
            $table->decimal('gpa', 4, 2);
            $table->string('certificate', 20)->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->unique(['player_academic_year_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_academic_records');
        Schema::dropIfExists('player_academic_years');
    }
};
