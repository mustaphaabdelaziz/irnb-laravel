<?php

namespace Tests\Feature\Registrations;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\RegistrationStatusChange;
use App\Models\User;
use App\Services\Player\RegistrationService;
use App\Services\Player\StatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class StatusHistoryRecordTest extends TestCase
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

    #[Test]
    public function a_line_keeps_both_statuses_the_day_the_note_and_the_author(): void
    {
        $user = User::factory()->admin()->create();
        $registration = $this->football($this->bothActive());
        $registered = PlayerStatus::where('code', 'registered')->value('id');

        $line = app(StatusHistory::class)->record($registration, $registered, Player::leftStatusId(), '2025-06-15', '  moved  ', $user, RegistrationStatusChange::ORIGIN_FORM);

        $this->assertSame($registered, $line->from_status_id);
        $this->assertSame(Player::leftStatusId(), $line->to_status_id);
        $this->assertSame('2025-06-15', $line->changed_on->toDateString());
        $this->assertSame('moved', $line->note);
        $this->assertSame($user->id, $line->user_id);
        $this->assertSame('form', $line->origin);
        $this->assertCount(1, $registration->statusChanges);
    }

    #[Test]
    public function a_blank_note_is_stored_as_none(): void
    {
        $registration = $this->football($this->bothActive());

        $line = app(StatusHistory::class)->record($registration, null, Player::leftStatusId(), '2025-06-15', '   ', null, RegistrationStatusChange::ORIGIN_MANUAL);

        $this->assertNull($line->note);
        $this->assertNull($line->user_id);
    }

    #[Test]
    public function removing_a_registration_added_by_mistake_removes_its_lines(): void
    {
        $player = $this->bothActive();
        $swim = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();
        app(StatusHistory::class)->record($swim, null, Player::leftStatusId(), '2025-06-15', null, null, RegistrationStatusChange::ORIGIN_MANUAL);

        $this->assertNull(app(RegistrationService::class)->removeMistake($swim));

        $this->assertSame(0, RegistrationStatusChange::count());
    }

    #[Test]
    public function the_seeding_migration_records_each_dated_departure_once(): void
    {
        $dated = $this->football($this->leftFootball('Dated'));
        $undated = $this->football($this->leftFootball('Undated'));
        DB::table('branch_player')->where('id', $undated->id)->update(['left_at' => null]);
        RegistrationStatusChange::query()->delete();

        $migration = require database_path('migrations/2026_11_20_300001_seed_registration_departures.php');
        $migration->up();
        $migration->up();

        $lines = RegistrationStatusChange::all();
        $this->assertCount(1, $lines);
        $this->assertSame($dated->id, $lines[0]->branch_registration_id);
        $this->assertNull($lines[0]->from_status_id);
        $this->assertSame(Player::leftStatusId(), $lines[0]->to_status_id);
        $this->assertSame($this->leaveDate, $lines[0]->changed_on->toDateString());
        $this->assertSame('derived', $lines[0]->origin);
    }
}
