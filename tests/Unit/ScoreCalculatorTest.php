<?php

namespace Tests\Unit;

use App\Services\Competitions\ScoreCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScoreCalculatorTest extends TestCase
{
    #[Test]
    public function goals_and_points_are_summed_over_filled_rows(): void
    {
        $this->assertSame([3, 1], ScoreCalculator::derive([[1, 0], [2, 1]], 'goals'));
        $this->assertSame([2, 2], ScoreCalculator::derive([[2, 2], [null, null], null], 'points'));
    }

    #[Test]
    public function sets_count_who_won_each_set(): void
    {
        $this->assertSame([3, 1], ScoreCalculator::derive([[25, 20], [22, 25], [25, 18], [25, 23], [null, null]], 'sets'));
    }

    #[Test]
    public function no_filled_row_means_no_derived_score(): void
    {
        $this->assertNull(ScoreCalculator::derive([[null, null], [1, null]], 'goals'));
        $this->assertNull(ScoreCalculator::derive([], 'goals'));
    }
}
