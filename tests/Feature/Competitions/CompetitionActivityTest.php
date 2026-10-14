<?php

namespace Tests\Feature\Competitions;

use App\Models\ActivityLog;
use App\Services\Activity\ActivityAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class CompetitionActivityTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function competition_writes_are_recorded_in_their_own_area(): void
    {
        $team = $this->team();
        $player = $this->player($team->category);
        $this->onRoster($team, 2026, [$player]);
        $game = $this->game($team);

        $this->actingAs($this->admin())->put(route('games.call-up.update', $game), ['players' => [['player_id' => $player->id, 'called_up' => true]]])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::where('action', ActivityAction::CALL_UP_SAVED)->sole();
        $this->assertSame($game->id, (int) $log->subject_id);
        $this->assertContains(ActivityAction::CALL_UP_SAVED, ActivityAction::AREAS['competitions']);
        $this->assertContains(ActivityAction::GAME_CREATED, ActivityAction::ALL);
    }
}
