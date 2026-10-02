<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance codes the owner adds beside the six built-in statuses. A mark
 * stores the row's `key` (c_<id>) in attendances.status, so renaming a code
 * never rewrites history; `behaviour` says how the mark counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_custom_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique();
            $table->string('code', 10);
            $table->string('color', 7);
            $table->string('label_ar', 40)->nullable();
            $table->string('label_fr', 40)->nullable();
            $table->string('label_en', 40)->nullable();
            $table->string('behaviour', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_custom_statuses');
    }
};
