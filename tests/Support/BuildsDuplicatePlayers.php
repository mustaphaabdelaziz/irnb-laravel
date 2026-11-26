<?php

namespace Tests\Support;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

/** Small rows for duplicate / merge tests, inserted with the query builder (no hooks). */
trait BuildsDuplicatePlayers
{
    private int $dupSeq = 0;

    protected function player(array $attributes = []): Player
    {
        $this->dupSeq++;

        return Player::create(array_merge([
            'membership_id' => 'T'.str_pad((string) $this->dupSeq, 6, '0', STR_PAD_LEFT),
            'firstname' => 'Test'.$this->dupSeq,
        ], $attributes))->fresh();
    }

    protected function branch(string $name = 'Football'): int
    {
        $this->dupSeq++;

        return DB::table('branches')->insertGetId([
            'name' => $name, 'code' => 'B'.$this->dupSeq, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function registration(Player $player, int $branchId, array $extra = []): int
    {
        return DB::table('branch_player')->insertGetId(array_merge([
            'branch_id' => $branchId, 'player_id' => $player->id,
            'membership_id' => 'M'.$player->id.'-'.$branchId,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    protected function category(int $branchId): int
    {
        $this->dupSeq++;

        return DB::table('categories')->insertGetId([
            'name' => 'U13-'.$this->dupSeq, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function trainingSession(int $categoryId, string $date = '2026-10-01'): int
    {
        return DB::table('training_sessions')->insertGetId([
            'category_id' => $categoryId, 'date' => $date, 'start_time' => '17:00', 'end_time' => '18:30',
            'kind' => 'training', 'state' => 'held', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function documentType(): int
    {
        $this->dupSeq++;

        return DB::table('document_types')->insertGetId([
            'code' => 'doc'.$this->dupSeq, 'name' => 'Photo '.$this->dupSeq, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
