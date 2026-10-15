<?php

namespace Tests\Feature\Competitions;

use App\Models\DocumentType;
use App\Models\Role;
use App\Models\Suspension;
use App\Models\User;
use App\Services\Competitions\PlayerFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class PlayerFlagsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function flags_cover_licence_documents_debt_and_suspension(): void
    {
        $team = $this->team();
        DocumentType::create(['code' => 'medical', 'name' => 'Medical', 'validity' => 'none', 'is_required' => false, 'required_for_matches' => true, 'is_active' => true]);
        $p = $this->player($team->category);
        $p->update(['outstanding_debt' => 1500]);
        $ok = $this->player($team->category, ['licence_number' => 'L-1']);
        Suspension::create(['player_id' => $p->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'dates', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(5)->toDateString(), 'reason' => 'Discipline']);

        $flags = app(PlayerFlags::class)->for([$p->id, $ok->id], $this->admin());

        $this->assertTrue($flags[$p->id]['no_licence']);
        $this->assertTrue($flags[$p->id]['documents_missing']);
        $this->assertSame(['Medical'], $flags[$p->id]['documents']);
        $this->assertTrue($flags[$p->id]['unpaid']);
        $this->assertSame(1500.0, $flags[$p->id]['debt']);
        $this->assertTrue($flags[$p->id]['suspended']);
        $this->assertSame('Discipline', $flags[$p->id]['suspension']['reason']);
        $this->assertFalse($flags[$ok->id]['no_licence']);
        $this->assertFalse($flags[$ok->id]['unpaid']);
        $this->assertFalse($flags[$ok->id]['suspended']);
    }

    #[Test]
    public function the_debt_amount_is_hidden_without_finance_view_and_the_call_up_carries_flags(): void
    {
        $team = $this->team();
        $p = $this->player($team->category);
        $p->update(['outstanding_debt' => 900]);
        $this->onRoster($team, 2026, [$p]);
        $game = $this->game($team);
        $viewer = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['games' => ['view']]])->id]);

        $this->assertNull(app(PlayerFlags::class)->for([$p->id], $viewer)[$p->id]['debt']);

        $this->actingAs($viewer)->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.0.flags.unpaid', true)
                ->where('candidates.0.flags.debt', null));
    }
}
