<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_session_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_session_id')->constrained('inventory_sessions')->cascadeOnDelete();
            $table->morphs('participant');
            $table->timestamps();

            $table->unique(
                ['inventory_session_id', 'participant_type', 'participant_id'],
                'inv_session_participant_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_session_participants');
    }
};
