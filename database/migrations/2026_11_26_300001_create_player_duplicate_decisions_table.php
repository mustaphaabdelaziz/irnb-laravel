<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisions about possible duplicate players: "not duplicate" (dismissed,
 * remembered forever) and "merged". Pairs themselves are computed live.
 * A deleted player blanks its side; the snapshot keeps the row readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_duplicate_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_a_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('player_b_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('status', 16)->index();
            $table->json('player_a_snapshot');
            $table->json('player_b_snapshot');
            $table->foreignId('kept_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->json('reasons')->nullable();
            $table->smallInteger('score')->default(0);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->unique(['player_a_id', 'player_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_duplicate_decisions');
    }
};
