<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerAcademicRecordTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makePlayer(bool $student = true): Player
    {
        return Player::create([
            'membership_id' => '8'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'S'.$this->seq,
            'lastname' => 'Test',
            'is_student' => $student,
            'outstanding_debt' => 0,
        ]);
    }

    private function record(Player $player, int $year, string $period, float $gpa): PlayerAcademicRecord
    {
        return $player->academicRecords()->create([
            'academic_year' => $year,
            'period' => $period,
            'gpa' => $gpa,
        ]);
    }

    #[Test]
    public function records_order_chronologically_with_t3_last_in_a_year(): void
    {
        $player = $this->makePlayer();
        $this->record($player, 2025, 'T3', 13);
        $this->record($player, 2024, 'T2', 11);
        $this->record($player, 2025, 'T1', 12);
        $this->record($player, 2024, 'T1', 9.5);
        $this->record($player, 2025, 'T2', 10);

        $order = $player->academicRecords()->chronological()->get()
            ->map(fn ($r) => $r->academic_year.'-'.$r->period)->all();

        // T1 < T2 within 2024; T1 < T2 < T3 within 2025; and the later year
        // (2025) sorts after 2024 even where 2024 holds the higher-rank period.
        $this->assertSame(['2024-T1', '2024-T2', '2025-T1', '2025-T2', '2025-T3'], $order);
    }

    #[Test]
    public function latest_gpa_sql_picks_the_last_record_in_chronological_order(): void
    {
        $a = $this->makePlayer();
        $this->record($a, 2024, 'T2', 15);
        $this->record($a, 2025, 'T1', 8.25);   // later year wins over higher rank
        $b = $this->makePlayer();
        $this->record($b, 2025, 'T2', 9);
        $this->record($b, 2025, 'T3', 10.5);   // T3 after T2 within the same year
        $c = $this->makePlayer();                 // no records

        $latest = DB::table('players')
            ->selectRaw('players.id, ('.PlayerAcademicRecord::latestGpaSql().') as latest_gpa')
            ->pluck('latest_gpa', 'id');

        $this->assertEquals(8.25, (float) $latest[$a->id]);
        $this->assertEquals(10.5, (float) $latest[$b->id]);
        $this->assertNull($latest[$c->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'academic_year' => 2025,
            'period' => 'T1',
            'gpa' => 12.5,
            'remark' => 'Good start',
        ], $overrides);
    }

    #[Test]
    public function admin_adds_updates_and_deletes_a_record(): void
    {
        $player = $this->makePlayer();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.academic_record_added');

        $record = PlayerAcademicRecord::firstOrFail();
        $this->assertSame(2025, $record->academic_year);
        $this->assertSame('T1', $record->period);
        $this->assertEquals(12.5, (float) $record->gpa);

        $this->actingAs($admin)
            ->put(route('players.academic-records.update', [$player, $record]), $this->payload(['gpa' => 14, 'remark' => null]))
            ->assertSessionHas('success', 'flash.academic_record_updated');
        $this->assertEquals(14.0, (float) $record->fresh()->gpa);
        $this->assertNull($record->fresh()->remark);

        $this->actingAs($admin)
            ->delete(route('players.academic-records.destroy', [$player, $record]))
            ->assertSessionHas('success', 'flash.academic_record_deleted');
        $this->assertDatabaseCount('player_academic_records', 0);
    }

    #[Test]
    public function gpa_must_be_between_0_and_20_and_period_known(): void
    {
        $player = $this->makePlayer();

        // 'S1' used to be a valid semester period; now that semesters are
        // removed it must be rejected just like any other unknown value.
        $this->actingAs($this->admin())
            ->post(route('players.academic-records.store', $player), $this->payload(['gpa' => 20.5, 'period' => 'S1']))
            ->assertSessionHasErrors(['gpa', 'period']);

        $this->assertDatabaseCount('player_academic_records', 0);
    }

    #[Test]
    public function the_same_year_and_period_cannot_be_recorded_twice(): void
    {
        $player = $this->makePlayer();
        $admin = $this->admin();
        $this->record($player, 2025, 'T1', 11);
        $other = $this->record($player, 2025, 'T2', 12);

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload())
            ->assertSessionHasErrors('period');

        // Updating a record onto another record's slot is also refused…
        $this->actingAs($admin)
            ->put(route('players.academic-records.update', [$player, $other]), $this->payload())
            ->assertSessionHasErrors('period');

        // …but saving a record onto its own slot is fine.
        $this->actingAs($admin)
            ->put(route('players.academic-records.update', [$player, $other]), $this->payload(['period' => 'T2']))
            ->assertSessionHasNoErrors();

        // Another player may use the same slot.
        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $this->makePlayer()), $this->payload())
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function records_cannot_be_added_to_a_worker(): void
    {
        $worker = $this->makePlayer(student: false);

        $this->actingAs($this->admin())
            ->post(route('players.academic-records.store', $worker), $this->payload())
            ->assertSessionHasErrors('student');

        $this->assertDatabaseCount('player_academic_records', 0);
    }

    #[Test]
    public function a_record_of_another_player_is_not_found(): void
    {
        $owner = $this->makePlayer();
        $record = $this->record($owner, 2025, 'T1', 11);
        $stranger = $this->makePlayer();

        $this->actingAs($this->admin())
            ->delete(route('players.academic-records.destroy', [$stranger, $record]))
            ->assertNotFound();

        $this->assertDatabaseCount('player_academic_records', 1);
    }

    #[Test]
    public function adding_a_record_needs_players_add(): void
    {
        $viewer = User::factory()->create([
            'privileges' => ['user'],
            'approved' => true,
            'email_verified_at' => now(),
            'role_id' => Role::factory()->create(['permissions' => ['players' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)
            ->post(route('players.academic-records.store', $this->makePlayer()), $this->payload())
            ->assertForbidden();
    }

    #[Test]
    public function profile_shows_records_in_order_for_students_only(): void
    {
        $student = $this->makePlayer();
        $this->record($student, 2025, 'T2', 13);
        $this->record($student, 2025, 'T1', 11);
        $worker = $this->makePlayer(student: false);
        $this->record($worker, 2025, 'T1', 11); // kept from when they studied

        $this->actingAs($this->admin())->get(route('players.show', $student))
            ->assertInertia(fn (Assert $page) => $page
                ->where('player.academic_records.0.period', 'T1')
                ->where('player.academic_records.1.period', 'T2'));

        $this->actingAs($this->admin())->get(route('players.show', $worker))
            ->assertInertia(fn (Assert $page) => $page->missing('player.academic_records'));
    }

    #[Test]
    public function school_info_is_saved_from_the_player_form(): void
    {
        $player = $this->makePlayer();

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => $player->firstname,
            'lastname' => $player->lastname,
            'is_student' => true,
            'education_level' => 'licence',
            'institution' => 'Université de Béjaïa',
            'field_of_study' => 'L2 Informatique',
        ])->assertSessionHasNoErrors();

        $player->refresh();
        $this->assertSame('licence', $player->education_level);
        $this->assertSame('Université de Béjaïa', $player->institution);
        $this->assertSame('L2 Informatique', $player->field_of_study);

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => $player->firstname,
            'lastname' => $player->lastname,
            'education_level' => 'kindergarten',
        ])->assertSessionHasErrors('education_level');
    }
}
