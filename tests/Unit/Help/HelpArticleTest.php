<?php

namespace Tests\Unit\Help;

use App\Services\Help\HelpArticle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HelpArticleTest extends TestCase
{
    #[Test]
    public function it_survives_a_round_trip_through_a_plain_array(): void
    {
        $a = new HelpArticle(
            'players', 'add-player', 'Add a player', 'Register one.', 'players',
            ['players.index'], ['add', 'new'], ['players/find-player'],
            [['type' => 'step', 'heading' => 'Open', 'figures' => []]],
        );

        $copy = HelpArticle::fromArray($a->toArray());

        $this->assertEquals($a->toArray(), $copy->toArray());
        $this->assertSame($a->summary(), $copy->summary());
        $this->assertSame(1, $copy->stepCount());
    }

    #[Test]
    public function a_null_module_round_trips(): void
    {
        $a = new HelpArticle('t', 's', 'T', 'S', null, [], [], [], []);

        $this->assertNull(HelpArticle::fromArray($a->toArray())->module);
    }
}
