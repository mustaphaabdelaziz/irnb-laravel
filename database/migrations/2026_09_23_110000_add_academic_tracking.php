<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('education_level', 20)->nullable()->after('is_student');
            $table->string('institution')->nullable()->after('education_level');
            $table->string('field_of_study')->nullable()->after('institution');
        });

        Schema::create('player_academic_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            // Start year of the school year: 2025 means "2025/2026".
            $table->unsignedSmallInteger('academic_year');
            $table->string('period', 10);
            $table->decimal('gpa', 4, 2);
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'academic_year', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_academic_records');

        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['education_level', 'institution', 'field_of_study']);
        });
    }
};
