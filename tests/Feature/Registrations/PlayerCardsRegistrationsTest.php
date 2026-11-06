<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\User;
use App\Services\Pdf\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class PlayerCardsRegistrationsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private string $html = '';

    private string $filename = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
        $this->mock(PdfService::class, fn (MockInterface $mock) => $mock->shouldReceive('stream')
            ->andReturnUsing(function (string $html, string $filename) {
                $this->html = $html;
                $this->filename = $filename;

                return response('pdf');
            }));
    }

    private function twoBranchPlayer(): Player
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001']);

        return $player;
    }

    #[Test]
    public function the_card_shows_the_chosen_branchs_id_and_category(): void
    {
        $player = $this->twoBranchPlayer();

        $this->get(route('players.card', ['player' => $player->id, 'branch' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('SW-2020-00001', $this->html);
        $this->assertStringContainsString('U15', $this->html);
        $this->assertStringContainsString('SW-2020-00001', $this->filename);
    }

    #[Test]
    public function the_card_with_no_branch_shows_main_values(): void
    {
        $player = $this->twoBranchPlayer();

        $this->get(route('players.card', $player))->assertOk();

        $this->assertStringContainsString('Senior', $this->html);
        $this->assertStringNotContainsString('SW-2020-00001', $this->html);
    }

    #[Test]
    public function the_card_for_a_branch_the_player_never_joined_shows_main_values(): void
    {
        $player = $this->noRegistration();

        $this->get(route('players.card', ['player' => $player->id, 'branch' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('Senior', $this->html);
    }

    #[Test]
    public function the_academic_report_follows_the_branch(): void
    {
        $player = $this->twoBranchPlayer();
        $player->update(['is_student' => true]);

        $this->get(route('players.academic-report', ['player' => $player->id, 'branch' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('SW-2020-00001', $this->html);
        $this->assertStringContainsString('U15', $this->html);
    }

    #[Test]
    public function the_attendance_report_follows_the_branch_being_viewed(): void
    {
        $player = $this->twoBranchPlayer();

        $this->get(route('attendance.players.report', ['player' => $player->id, 'branch_id' => $this->swim->id]))->assertOk();

        $this->assertStringContainsString('SW-2020-00001', $this->html);
        $this->assertStringContainsString('U15', $this->html);
    }

    #[Test]
    public function the_player_page_names_the_main_branch(): void
    {
        $player = $this->leftFootball();

        $this->get(route('players.show', $player))
            ->assertInertia(fn (Assert $page) => $page->where('mainBranchId', $this->swim->id));
    }
}
