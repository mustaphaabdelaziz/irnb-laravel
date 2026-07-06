<?php

namespace Tests\Unit;

use App\Models\Player;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerFullnameTest extends TestCase
{
    #[Test]
    public function fullname_uses_a_single_ben_and_appends_grandfather_plainly(): void
    {
        $player = new Player([
            'lastname' => 'زيدان',
            'firstname' => 'يوسف',
            'father' => 'أحمد',
            'grandfather' => 'علي',
        ]);

        $this->assertSame('زيدان يوسف بن أحمد علي', $player->fullname);
    }

    #[Test]
    public function fullname_without_grandfather_stops_after_father(): void
    {
        $player = new Player([
            'lastname' => 'زيدان',
            'firstname' => 'يوسف',
            'father' => 'أحمد',
        ]);

        $this->assertSame('زيدان يوسف بن أحمد', $player->fullname);
    }

    #[Test]
    public function fullname_without_father_is_just_last_and_first(): void
    {
        $player = new Player([
            'lastname' => 'زيدان',
            'firstname' => 'يوسف',
        ]);

        $this->assertSame('زيدان يوسف', $player->fullname);
    }

    #[Test]
    public function fullname_is_serialized_so_the_frontend_receives_it(): void
    {
        $player = new Player([
            'lastname' => 'زيدان',
            'firstname' => 'يوسف',
            'father' => 'أحمد',
            'grandfather' => 'علي',
        ]);

        $array = $player->toArray();

        $this->assertArrayHasKey('fullname', $array);
        $this->assertSame('زيدان يوسف بن أحمد علي', $array['fullname']);
    }
}
