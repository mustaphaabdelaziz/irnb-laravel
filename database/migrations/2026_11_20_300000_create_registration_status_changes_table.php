<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line per status change of a branch registration: the old and new status,
 * the day it took effect, a note and who recorded it. The registration row keeps
 * the current status; these lines are its past. Constraint names are explicit,
 * as in the other registration migrations (MySQL names are global).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('registration_status_changes')) {
            return;
        }

        Schema::create('registration_status_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_registration_id');
            $table->unsignedBigInteger('from_status_id')->nullable();
            $table->unsignedBigInteger('to_status_id')->nullable();
            $table->date('changed_on');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            // form | manual | derived
            $table->string('origin', 10);
            $table->timestamps();

            $table->foreign('branch_registration_id', 'reg_status_reg_fk')->references('id')->on('branch_player')->cascadeOnDelete();
            $table->foreign('from_status_id', 'reg_status_from_fk')->references('id')->on('player_statuses')->nullOnDelete();
            $table->foreign('to_status_id', 'reg_status_to_fk')->references('id')->on('player_statuses')->nullOnDelete();
            $table->foreign('user_id', 'reg_status_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_registration_id', 'changed_on'], 'reg_status_reg_date_index');
            $table->index(['to_status_id', 'changed_on'], 'reg_status_to_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_status_changes');
    }
};
