<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\TrainingSession;
use App\Services\Attendance\Roster;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

/**
 * Which player statuses make a session's expected roster: the session's own
 * set, else the settings' default set, else the status coded `registered`.
 */
class AttendanceRosterStatusTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function statusId(string $code): int
    {
        return (int) PlayerStatus::where('code', $code)->value('id');
    }

    private function makeSession(Category $category, array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'category_id' => $category->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    /** @return list<int> */
    private function rosterIds(TrainingSession $session): array
    {
        return app(Roster::class)->forSession($session->fresh())->pluck('id')->all();
    }

    #[Test]
    public function by_default_the_roster_is_the_registered_players(): void
    {
        $u15 = $this->category();
        $registered = $this->player($u15, ['status_id' => $this->statusId('registered')]);
        $noStatus = $this->player($u15);   // older records: counted as registered
        $this->player($u15, ['status_id' => $this->statusId('paused')]);
        $this->player($u15, ['status_id' => $this->statusId('retired')]);
        $leavesLater = $this->player($u15, ['status_id' => Player::leftStatusId(), 'left_at' => '2026-10-20']);

        $this->assertSame([$registered->id, $noStatus->id, $leavesLater->id], $this->rosterIds($this->makeSession($u15)));
    }

    #[Test]
    public function the_settings_default_set_replaces_registered_and_is_saved_as_a_whole(): void
    {
        $u15 = $this->category();
        $this->player($u15, ['status_id' => $this->statusId('registered')]);
        $paused = $this->player($u15, ['status_id' => $this->statusId('paused')]);
        $sanctioned = $this->player($u15, ['status_id' => $this->statusId('sanctioned')]);
        $admin = $this->admin();

        $payload = AttendanceSettings::DEFAULTS;
        $payload['roster_status_ids'] = [$this->statusId('registered'), $this->statusId('paused')];
        $this->actingAs($admin)->put(route('attendance.settings.update'), $payload)->assertSessionHasNoErrors();
        // A shorter list replaces the stored one (no index-by-index merge).
        $payload['roster_status_ids'] = [$this->statusId('paused'), $this->statusId('sanctioned')];
        $this->actingAs($admin)->put(route('attendance.settings.update'), $payload)->assertSessionHasNoErrors();

        $this->assertSame([$this->statusId('paused'), $this->statusId('sanctioned')], AttendanceSettings::get()['roster_status_ids']);
        $this->assertSame([$paused->id, $sanctioned->id], $this->rosterIds($this->makeSession($u15)));

        $payload['roster_status_ids'] = [];
        $this->actingAs($admin)->put(route('attendance.settings.update'), $payload)->assertSessionHasErrors('roster_status_ids');
        $payload['roster_status_ids'] = [999999];
        $this->actingAs($admin)->put(route('attendance.settings.update'), $payload)->assertSessionHasErrors('roster_status_ids.0');
    }

    #[Test]
    public function a_session_added_by_hand_keeps_its_own_status_set(): void
    {
        $u15 = $this->category();
        $this->player($u15, ['status_id' => $this->statusId('registered')]);
        $paused = $this->player($u15, ['status_id' => $this->statusId('paused')]);

        $this->actingAs($this->admin())->post(route('attendance.sessions.store'), [
            'category_id' => $u15->id, 'kind' => 'extra', 'date' => '2026-10-07', 'start_time' => '10:00', 'end_time' => '11:00',
            'roster_status_ids' => [$this->statusId('paused')],
        ])->assertSessionHasNoErrors();

        $session = TrainingSession::sole();
        $this->assertSame([$this->statusId('paused')], $session->roster_status_ids);
        $this->assertSame([$paused->id], $this->rosterIds($session));

        $this->actingAs($this->admin())->post(route('attendance.sessions.store'), [
            'category_id' => $u15->id, 'kind' => 'extra', 'date' => '2026-10-08', 'start_time' => '10:00', 'end_time' => '11:00',
            'roster_status_ids' => [999999],
        ])->assertSessionHasErrors('roster_status_ids.0');
    }

    #[Test]
    public function the_grid_only_accepts_players_of_the_sessions_status_set(): void
    {
        $u15 = $this->category();
        $registered = $this->player($u15, ['status_id' => $this->statusId('registered')]);
        $paused = $this->player($u15, ['status_id' => $this->statusId('paused')]);
        $session = $this->makeSession($u15);

        $this->actingAs($this->admin())->get(route('attendance.grid', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $registered->id));

        $this->actingAs($this->admin())->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $session->id, 'codes' => [$registered->id => '', $paused->id => '']]],
        ])->assertSessionHasErrors("columns.0.codes.{$paused->id}");
    }

    #[Test]
    public function the_session_page_offers_any_active_player_by_full_name_with_their_status(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $this->player($u15, ['status_id' => $this->statusId('registered')]);
        $paused = $this->player($u17, ['status_id' => $this->statusId('paused'), 'nickname' => 'Zizou', 'father' => 'Ali']);
        $leavesLater = $this->player($u17, ['status_id' => Player::leftStatusId(), 'left_at' => '2026-10-20']);
        $this->player($u17, ['status_id' => Player::leftStatusId(), 'left_at' => '2026-10-01']);
        $this->player($u17, ['archived' => true]);
        $session = $this->makeSession($u15);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page->has('candidates', 2)
                ->where('candidates.0.id', $paused->id)
                ->where('candidates.0.name', $paused->fresh()->fullname)
                ->where('candidates.0.status', PlayerStatus::find($this->statusId('paused'))->localized_name)
                ->where('candidates.0.category', 'U17')
                ->where('candidates.1.id', $leavesLater->id));
    }

    #[Test]
    public function the_calendar_and_settings_pages_get_the_statuses_and_the_default_set(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();
        $registered = $this->statusId('registered');

        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']))
            // Optional props: left out of the page load, fetched when the add-session dialog opens.
            ->assertInertia(fn (Assert $page) => $page->missing('playerStatuses')->missing('rosterStatusIds')
                ->reloadOnly(['playerStatuses', 'rosterStatusIds'], fn (Assert $reload) => $reload
                    ->where('rosterStatusIds', [$registered])
                    ->where('playerStatuses.0.id', PlayerStatus::orderBy('sort_order')->orderBy('id')->value('id'))));

        $this->actingAs($admin)->get(route('attendance.settings'))
            ->assertInertia(fn (Assert $page) => $page->where('settings.roster_status_ids', [$registered])
                ->has('playerStatuses'));
    }

    #[Test]
    public function a_left_player_without_a_leave_date_is_neither_expected_nor_offered(): void
    {
        $u15 = $this->category();
        $stays = $this->player($u15, ['status_id' => $this->statusId('registered')]);
        $legacy = $this->player($u15);
        // Older data: status "left" but no leave date (Player::booted() would stamp one).
        DB::table('players')->where('id', $legacy->id)->update(['status_id' => Player::leftStatusId(), 'left_at' => null]);
        $session = $this->makeSession($u15);

        $this->assertSame([$stays->id], $this->rosterIds($session));
        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page->has('candidates', 0));
    }
}
