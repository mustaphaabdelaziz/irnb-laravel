<?php

namespace Tests\Unit;

use App\Services\Competitions\MinutesCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MinutesCalculatorTest extends TestCase
{
    private function sub(int $minute, int $in, int $out): array
    {
        return ['type' => 'substitution', 'minute' => $minute, 'player_id' => $in, 'related_player_id' => $out, 'ends_play' => false];
    }

    private function red(int $minute, int $player): array
    {
        return ['type' => 'sanction', 'minute' => $minute, 'player_id' => $player, 'related_player_id' => null, 'ends_play' => true];
    }

    #[Test]
    public function starters_play_the_whole_match_without_events(): void
    {
        $result = MinutesCalculator::compute([1, 2], [], 90);

        $this->assertSame([1 => 90, 2 => 90], $result['minutes']);
        $this->assertSame([1, 2], $result['appeared']);
    }

    #[Test]
    public function substitutions_split_minutes_and_a_double_change_chains(): void
    {
        $result = MinutesCalculator::compute([1, 2], [$this->sub(80, 4, 3), $this->sub(60, 3, 1)], 90);

        $this->assertSame(60, $result['minutes'][1]);
        $this->assertSame(90, $result['minutes'][2]);
        $this->assertSame(20, $result['minutes'][3]);
        $this->assertSame(10, $result['minutes'][4]);
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], $result['appeared']);
    }

    #[Test]
    public function a_send_off_stops_the_clock_and_extra_time_counts(): void
    {
        $result = MinutesCalculator::compute([1, 2], [$this->red(70, 2)], 120);

        $this->assertSame(120, $result['minutes'][1]);
        $this->assertSame(70, $result['minutes'][2]);
    }

    #[Test]
    public function events_without_a_minute_or_beyond_the_end_are_handled(): void
    {
        $result = MinutesCalculator::compute([1], [
            ['type' => 'goal', 'minute' => null, 'player_id' => 1, 'related_player_id' => null, 'ends_play' => false],
            $this->sub(95, 5, 1),
        ], 90);

        $this->assertSame(90, $result['minutes'][1]);
        $this->assertSame(0, $result['minutes'][5]);
        $this->assertContains(5, $result['appeared']);
    }
}
