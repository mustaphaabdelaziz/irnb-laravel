<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\InjuryNote;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceInjuryNotesTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function mark(Category $category, string $date, Player $player, AttendanceStatus $status, ?string $reason = null): void
    {
        $training = TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'category_id' => $player->category_id, 'status' => $status, 'reason' => $reason,
        ]);
    }

    /** Spells: 2026-10-02 → 03 (closed), 2026-10-12 (open). */
    private function seedPlayer(): Player
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $this->mark($u15, '2026-10-02', $player, AttendanceStatus::NotTraining, 'injury');
        $this->mark($u15, '2026-10-03', $player, AttendanceStatus::AbsentExcused, 'injury');
        $this->mark($u15, '2026-10-05', $player, AttendanceStatus::Present);
        $this->mark($u15, '2026-10-12', $player, AttendanceStatus::NotTraining, 'injury');

        return $player;
    }

    #[Test]
    public function a_detail_is_added_to_a_spell_and_saving_again_updates_it(): void
    {
        $player = $this->seedPlayer();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('attendance.injury-notes.store', $player), [
                'start_date' => '2026-10-02', 'body_part' => 'Cheville', 'description' => "Entorse\nrepos 2 semaines", 'returned_on' => '2026-10-05',
            ])
            ->assertCreated()
            ->assertJsonPath('note.start_date', '2026-10-02')
            ->assertJsonPath('note.body_part', 'Cheville')
            ->assertJsonPath('note.returned_on', '2026-10-05');
        $this->assertSame($admin->id, InjuryNote::sole()->created_by);

        $this->actingAs($this->admin())
            ->postJson(route('attendance.injury-notes.store', $player), ['start_date' => '2026-10-02', 'body_part' => 'Genou'])
            ->assertOk()
            ->assertJsonPath('note.body_part', 'Genou');
        $note = InjuryNote::sole();
        $this->assertNull($note->description);
        $this->assertNull($note->returned_on);
        $this->assertSame($admin->id, $note->created_by);   // kept from the creation
    }

    #[Test]
    public function a_detail_needs_a_spell_start_and_a_return_after_it(): void
    {
        $player = $this->seedPlayer();

        $this->actingAs($this->admin())
            ->postJson(route('attendance.injury-notes.store', $player), ['start_date' => '2026-10-03', 'body_part' => 'Cheville'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date' => 'att.injury.error.no_spell']);

        $this->actingAs($this->admin())
            ->postJson(route('attendance.injury-notes.store', $player), ['start_date' => '2026-10-12', 'returned_on' => '2026-10-01', 'body_part' => str_repeat('x', 61)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['returned_on', 'body_part']);

        $this->assertSame(0, InjuryNote::count());
    }

    #[Test]
    public function an_unmatched_detail_can_be_edited_and_deleted(): void
    {
        $player = $this->seedPlayer();
        $note = InjuryNote::create(['player_id' => $player->id, 'start_date' => '2026-10-04', 'body_part' => 'Genou']);   // opens no spell
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson(route('attendance.injury-notes.update', $note), ['body_part' => 'Dos', 'description' => 'Contracture', 'returned_on' => '2026-10-10'])
            ->assertOk()
            ->assertJsonPath('note.body_part', 'Dos')
            ->assertJsonPath('note.returned_on', '2026-10-10');

        $this->actingAs($admin)
            ->putJson(route('attendance.injury-notes.update', $note), ['returned_on' => '2026-10-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['returned_on']);

        $this->actingAs($admin)->deleteJson(route('attendance.injury-notes.destroy', $note))->assertNoContent();
        $this->assertSame(0, InjuryNote::count());
    }

    #[Test]
    public function the_profile_card_carries_the_injuries_of_its_period(): void
    {
        $player = $this->seedPlayer();
        InjuryNote::create(['player_id' => $player->id, 'start_date' => '2026-10-02', 'body_part' => 'Cheville']);
        InjuryNote::create(['player_id' => $player->id, 'start_date' => '2026-10-04', 'body_part' => 'Genou']);

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $player))
            ->assertOk()
            ->assertJsonCount(2, 'injuries.spells')
            ->assertJsonPath('injuries.spells.0.start', '2026-10-12')
            ->assertJsonPath('injuries.spells.0.open', true)
            ->assertJsonPath('injuries.spells.0.note', null)
            ->assertJsonPath('injuries.spells.1.start', '2026-10-02')
            ->assertJsonPath('injuries.spells.1.end', '2026-10-03')
            ->assertJsonPath('injuries.spells.1.sessions', 2)
            ->assertJsonPath('injuries.spells.1.note.body_part', 'Cheville')
            ->assertJsonCount(1, 'injuries.unmatched')
            ->assertJsonPath('injuries.unmatched.0.start_date', '2026-10-04');
    }

    #[Test]
    public function view_only_users_see_the_injuries_but_cannot_change_details(): void
    {
        $player = $this->seedPlayer();
        $note = InjuryNote::create(['player_id' => $player->id, 'start_date' => '2026-10-02', 'body_part' => 'Cheville']);
        $viewer = $this->userWith(['attendance' => ['view']]);
        $adder = $this->userWith(['attendance' => ['view', 'add', 'delete']]);   // add/delete are not enough: details need edit

        $this->actingAs($viewer)->getJson(route('attendance.players.show', $player))->assertOk()->assertJsonPath('injuries.spells.1.note.body_part', 'Cheville');
        $this->actingAs($viewer)->postJson(route('attendance.injury-notes.store', $player), ['start_date' => '2026-10-12'])->assertForbidden();
        $this->actingAs($adder)->postJson(route('attendance.injury-notes.store', $player), ['start_date' => '2026-10-12'])->assertForbidden();
        $this->actingAs($viewer)->putJson(route('attendance.injury-notes.update', $note), ['body_part' => 'Dos'])->assertForbidden();
        $this->actingAs($adder)->deleteJson(route('attendance.injury-notes.destroy', $note))->assertForbidden();
        $this->assertSame('Cheville', $note->fresh()->body_part);
    }
}
