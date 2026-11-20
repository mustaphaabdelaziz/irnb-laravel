<?php

namespace Tests\Feature\Registrations;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\RegistrationStatusChange;
use App\Services\Player\RegistrationService;
use App\Services\Player\StatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class StatusHistorySafetyNetTest extends TestCase
{
    use RefreshDatabase;
    use RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function football(Player $player): BranchRegistration
    {
        return $player->registrations()->where('branch_id', $this->foot->id)->firstOrFail();
    }

    private function registeredId(): int
    {
        return (int) PlayerStatus::where('code', 'registered')->value('id');
    }

    #[Test]
    public function a_bulk_return_keeps_the_departure_as_a_derived_line(): void
    {
        $player = $this->leftFootball();
        $this->assertSame(0, RegistrationStatusChange::count());

        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id]);

        $lines = $this->football($player)->statusChanges;
        $this->assertCount(1, $lines);
        $this->assertNull($lines[0]->from_status_id);
        $this->assertSame(Player::leftStatusId(), $lines[0]->to_status_id);
        $this->assertSame($this->leaveDate, $lines[0]->changed_on->toDateString());
        $this->assertSame('derived', $lines[0]->origin);
        $this->assertNull($lines[0]->user_id);
        $this->assertNull($this->football($player)->left_at);
    }

    #[Test]
    public function a_departure_already_recorded_is_not_written_twice(): void
    {
        $player = $this->leftFootball();
        app(StatusHistory::class)->record($this->football($player), $this->registeredId(), Player::leftStatusId(), $this->leaveDate, 'moved', null, RegistrationStatusChange::ORIGIN_FORM);

        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id]);

        $this->assertSame(1, RegistrationStatusChange::count());
        $this->assertSame('form', RegistrationStatusChange::first()->origin);
    }

    #[Test]
    public function an_undated_departure_leaves_no_line(): void
    {
        $player = $this->leftFootball();
        DB::table('branch_player')->where('id', $this->football($player)->id)->update(['left_at' => null]);

        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id]);

        $this->assertSame(0, RegistrationStatusChange::count());
    }

    #[Test]
    public function a_bulk_status_change_out_of_left_keeps_the_departure(): void
    {
        $player = $this->leftFootball();

        app(RegistrationService::class)->setField($player, 'status_id', $this->registeredId(), $this->foot->id);

        $this->assertSame(1, RegistrationStatusChange::count());
        $this->assertSame('derived', RegistrationStatusChange::first()->origin);
    }

    #[Test]
    public function correcting_the_leave_date_moves_its_line(): void
    {
        $player = $this->leftFootball();
        $registration = $this->football($player);
        app(StatusHistory::class)->record($registration, $this->registeredId(), Player::leftStatusId(), $this->leaveDate, null, null, RegistrationStatusChange::ORIGIN_FORM);

        $registration->left_at = '2026-03-10';
        $registration->save();

        $this->assertSame('2026-03-10', RegistrationStatusChange::first()->changed_on->toDateString());
    }
}
