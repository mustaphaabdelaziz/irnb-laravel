<?php

namespace Tests\Feature\Competitions;

use App\Models\DocumentType;
use App\Models\PlayerDocument;
use App\Models\Role;
use App\Models\User;
use App\Support\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class TeamDocumentStatsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function the_team_page_counts_the_documents_of_its_season_roster(): void
    {
        $team = $this->team();
        $season = Season::current()->startYear;
        [$a, $b, $notOnRoster, $lastSeason] = [
            $this->player($team->category), $this->player($team->category),
            $this->player($team->category), $this->player($team->category),
        ];
        $this->onRoster($team, $season, [$a, $b]);
        $this->onRoster($team, $season - 1, [$lastSeason]);
        PlayerDocument::create([
            'player_id' => $a->id,
            'document_type_id' => DocumentType::where('code', 'birth_certificate')->value('id'),
            'state' => PlayerDocument::RECEIVED,
            'received_at' => now()->toDateString(),
        ]);

        // Fixture players have no birth date, so all four required types apply.
        $this->actingAs($this->admin())->get(route('teams.show', $team))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentStats.players_total', 2)
                ->where('documentStats.required', 8)
                ->where('documentStats.received', 1)
                ->where('documentStats.missing', 7)
                ->where('documentStats.players_complete', 0)
                ->has('documentStats.by_type', 4));
    }

    #[Test]
    public function without_documents_view_the_team_page_sends_no_document_stats(): void
    {
        $team = $this->team();
        $viewer = User::factory()->create(['role_id' => Role::factory()->create([
            'permissions' => ['teams' => ['view'], 'competitions' => ['view']],
        ])->id]);

        $this->actingAs($viewer)->get(route('teams.show', $team))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('documentStats', null));
    }
}
