<?php

namespace Tests\Feature\Registrations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/** A player's first registration copies their category only when this branch may hold it. */
class FirstRegistrationCategoryTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    #[Test]
    public function the_members_dialog_leaves_a_foreign_category_off_the_first_registration(): void
    {
        $player = $this->noRegistration();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('branches.players.sync', $this->swim), ['player_ids' => [$player->id], 'join_date' => '2024-01-15'])
            ->assertSessionHasNoErrors();

        $row = $player->registrations()->sole();
        $this->assertSame($this->swim->id, $row->branch_id);
        $this->assertNull($row->category_id);
        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function bulk_branches_leaves_a_foreign_category_off_the_first_registration(): void
    {
        $player = $this->noRegistration();

        $this->actingAs(User::factory()->admin()->create())->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'branches',
            'value' => [$this->swim->id], 'mode' => 'attach', 'join_date' => '2024-01-15',
        ])->assertRedirect();

        $this->assertNull($player->registrations()->where('branch_id', $this->swim->id)->sole()->category_id);
        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function the_branch_that_owns_the_category_still_gets_it(): void
    {
        $player = $this->noRegistration();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('branches.players.sync', $this->foot), ['player_ids' => [$player->id], 'join_date' => '2024-01-15'])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->senior->id, $player->registrations()->sole()->category_id);
    }
}
