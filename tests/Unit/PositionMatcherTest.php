<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Position;
use App\Support\Import\PositionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PositionMatcherTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_shared_abbreviation_resolves_to_the_players_sport_then_no_sport_then_the_oldest(): void
    {
        $football = Branch::create(['name' => 'Football']);
        $handball = Branch::create(['name' => 'Handball']);
        $footballGk = Position::create(['branch_id' => $football->id, 'abbreviation' => 'GK', 'name' => 'Goalkeeper']);
        $handballGk = Position::create(['branch_id' => $handball->id, 'abbreviation' => 'GK', 'name' => 'Gardien']);

        $this->assertSame($handballGk->id, PositionMatcher::match(Position::all(), 'gk', [$handball->id]));
        $this->assertSame($footballGk->id, PositionMatcher::match(Position::all(), 'GK', [$football->id]));
        $this->assertSame($footballGk->id, PositionMatcher::match(Position::all(), 'GK'));
        $this->assertSame($handballGk->id, PositionMatcher::match(Position::all(), 'Gardien'));
        $this->assertNull(PositionMatcher::match(Position::all(), ''));
        $this->assertNull(PositionMatcher::match(Position::all(), 'ST'));

        $anySport = Position::create(['branch_id' => null, 'abbreviation' => 'GK', 'name' => 'Keeper']);
        $this->assertSame($anySport->id, PositionMatcher::match(Position::all(), 'GK'));
        $this->assertSame($handballGk->id, PositionMatcher::match(Position::all(), 'GK', [$handball->id]));
    }
}
