<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\Player;
use App\Models\User;

trait AttendanceFixtures
{
    private int $playerSeq = 0;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function category(string $name = 'U15'): Category
    {
        return Category::create(['name' => $name]);
    }

    private function player(Category $category, array $extra = []): Player
    {
        $n = ++$this->playerSeq;

        return Player::create([
            'membership_id' => '8'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
            'firstname' => 'P'.$n,
            'lastname' => 'Test'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'category_id' => $category->id,
            'outstanding_debt' => 0,
        ] + $extra);
    }
}
