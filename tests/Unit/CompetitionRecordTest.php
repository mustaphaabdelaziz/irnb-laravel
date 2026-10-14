<?php

namespace Tests\Unit;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Services\Competitions\CompetitionRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CompetitionRecordTest extends TestCase
{
    private function game(string $date, ?int $us, ?int $them, GameStatus $status = GameStatus::Played): Game
    {
        return (new Game)->forceFill(['date' => $date, 'our_score' => $us, 'their_score' => $them, 'status' => $status]);
    }

    #[Test]
    public function it_counts_played_games_only_and_keeps_the_last_five_results_in_date_order(): void
    {
        $record = CompetitionRecord::fromGames([
            $this->game('2026-10-10', 2, 1),
            $this->game('2026-09-01', 0, 0),
            $this->game('2026-09-08', 1, 3),
            $this->game('2026-09-15', 4, 0),
            $this->game('2026-09-22', 1, 1),
            $this->game('2026-09-29', 2, 0),
            $this->game('2026-10-17', null, null, GameStatus::Scheduled),
        ]);

        $this->assertSame(6, $record['played']);
        $this->assertSame([3, 2, 1], [$record['wins'], $record['draws'], $record['losses']]);
        $this->assertSame([10, 5], [$record['goals_for'], $record['goals_against']]);
        $this->assertSame(['L', 'W', 'D', 'W', 'W'], $record['form']);
    }
}
