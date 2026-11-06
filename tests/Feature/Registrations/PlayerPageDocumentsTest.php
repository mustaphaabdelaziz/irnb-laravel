<?php

namespace Tests\Feature\Registrations;

use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class PlayerPageDocumentsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        DocumentType::query()->update(['is_active' => false]);
        DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'none', 'is_required' => true, 'is_active' => true]);
    }

    #[Test]
    public function a_two_branch_player_gets_a_tab_per_registration(): void
    {
        $player = $this->leftFootball();

        $this->actingAs($this->admin)->get(route('players.show', $player))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.tabs', 2)
                ->where('documents.tabs.0.branch_name', 'Swimming')
                ->where('documents.tabs.0.left', false)
                ->where('documents.tabs.0.missing_count', 1)
                ->where('documents.tabs.1.branch_name', 'Football')
                ->where('documents.tabs.1.left', true)
                ->where('documents.tabs.1.missing_count', 0)
                ->where('documents.tabs.1.items.0.reason', 'left')
                ->where('documents.registration_id', $player->registrationIn($this->swim->id)->id));
    }

    #[Test]
    public function a_one_branch_player_has_one_tab(): void
    {
        $player = $this->noRegistration();
        app(\App\Services\Player\RegistrationService::class)->register($player, ['branch_id' => $this->foot->id]);

        $this->actingAs($this->admin)->get(route('players.show', $player))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('documents.tabs', 1)->where('documents.missing_count', 1));
    }

    #[Test]
    public function a_player_with_no_registration_has_no_tabs_and_the_same_checklist(): void
    {
        $this->actingAs($this->admin)->get(route('players.show', $this->noRegistration()))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.tabs', 0)
                ->has('documents.items', 1)
                ->where('documents.registration_id', null)
                ->where('documents.left', false));
    }

    #[Test]
    public function left_everywhere_shows_every_registration_as_left(): void
    {
        $this->actingAs($this->admin)->get(route('players.show', $this->leftBoth()))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.tabs.0.left', true)
                ->where('documents.tabs.1.left', true)
                ->where('documents.missing_count', 0));
    }
}
