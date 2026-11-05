<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class ObligationReportsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
    }

    private function owes(Player $player): void
    {
        Subscription::create([
            'name' => 'Club', 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ])->assignTo($player);
    }

    /** @return list<array<string, mixed>> */
    private function rows(array $query = []): array
    {
        $rows = [];
        $this->get(route('reports.view', ['finance', 'debtors', ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $rows = $page->toArray()['props']['rows']['data'];
            });

        return $rows;
    }

    #[Test]
    public function branch_and_category_filters_hold_on_one_registration(): void
    {
        $this->owes($this->bothActive());

        $this->assertCount(0, $this->rows(['branch_id' => [$this->swim->id], 'category_id' => [$this->senior->id]]));
        $this->assertCount(1, $this->rows(['branch_id' => [$this->swim->id], 'category_id' => [$this->u15->id]]));
    }

    #[Test]
    public function no_branch_or_several_show_the_main_registration(): void
    {
        $player = $this->bothActive();
        $this->owes($player);
        $footId = $player->registrationIn($this->foot->id)->membership_id;

        foreach ([[], ['branch_id' => [$this->foot->id, $this->swim->id]]] as $query) {
            $rows = $this->rows($query);
            $this->assertCount(1, $rows);
            $this->assertSame('Senior', $rows[0]['category']);
            $this->assertSame($footId, $rows[0]['membership_id']);
        }
    }

    #[Test]
    public function one_branch_shows_that_registration(): void
    {
        $player = $this->bothActive();
        $this->owes($player);

        $rows = $this->rows(['branch_id' => [$this->swim->id]]);

        $this->assertCount(1, $rows);
        $this->assertSame('U15', $rows[0]['category']);
        $this->assertSame($player->registrationIn($this->swim->id)->membership_id, $rows[0]['membership_id']);
    }

    #[Test]
    public function a_debt_stays_listed_under_a_branch_the_player_left(): void
    {
        $player = $this->leftFootball();
        $this->owes($player);

        $rows = $this->rows(['branch_id' => [$this->foot->id]]);

        $this->assertCount(1, $rows);
        $this->assertSame('Senior', $rows[0]['category']);
    }

    #[Test]
    public function left_everywhere_still_appears_with_no_filter(): void
    {
        $this->owes($this->leftBoth());

        $this->assertCount(1, $this->rows());
    }

    #[Test]
    public function no_registration_matches_on_its_own_category_and_no_branch(): void
    {
        $player = $this->noRegistration();
        $player->update(['membership_id' => '2020FLAT1']);
        $this->owes($player);

        $this->assertSame('2020FLAT1', $this->rows()[0]['membership_id']);
        $this->assertCount(1, $this->rows(['category_id' => [$this->senior->id]]));
        $this->assertCount(0, $this->rows(['category_id' => [$this->u15->id]]));
        $this->assertCount(0, $this->rows(['branch_id' => [$this->foot->id]]));
    }
}
