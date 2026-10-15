<?php

namespace Tests\Unit;

use App\Services\Competitions\DefaultFormation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DefaultFormationTest extends TestCase
{
    #[Test]
    public function it_gives_one_numbered_spot_per_player_on_the_field_inside_the_pitch(): void
    {
        foreach ([1, 5, 6, 7, 11, 15] as $n) {
            $slots = DefaultFormation::slots($n);

            $this->assertCount($n, $slots, "players on field: {$n}");
            $this->assertSame(array_map('strval', range(1, $n)), array_column($slots, 'label'));
            $this->assertSame(['label' => '1', 'x' => 50, 'y' => 8], $slots[0]);
            foreach ($slots as $slot) {
                $this->assertIsInt($slot['x']);
                $this->assertIsInt($slot['y']);
                $this->assertGreaterThanOrEqual(10, $slot['x']);
                $this->assertLessThanOrEqual(90, $slot['x']);
                $this->assertGreaterThanOrEqual(8, $slot['y']);
                $this->assertLessThanOrEqual(85, $slot['y']);
            }
            $spots = array_map(fn (array $s) => $s['x'].','.$s['y'], $slots);
            $this->assertSame($spots, array_unique($spots), "no two spots overlap ({$n})");
        }
    }

    #[Test]
    public function eleven_players_spread_over_three_rows_of_up_to_four(): void
    {
        $rows = array_count_values(array_column(array_slice(DefaultFormation::slots(11), 1), 'y'));

        $this->assertSame([30 => 4, 55 => 3, 80 => 3], $rows);
        $this->assertSame([], DefaultFormation::slots(0));
    }
}
