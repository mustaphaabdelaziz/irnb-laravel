<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Models\PlayerAcademicYear;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    private function year(Player $player, int $year, string $level = 'secondary'): PlayerAcademicYear
    {
        return $player->academicYears()->create(['academic_year' => $year, 'education_level' => $level]);
    }

    private function record(PlayerAcademicYear $year, string $period, float $gpa): PlayerAcademicRecord
    {
        return $year->records()->create(['period' => $period, 'gpa' => $gpa]);
    }

    /** A grade for 2025 T1, with school info so a new year can be created. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'academic_year' => 2025,
            'period' => 'T1',
            'gpa' => 12.5,
            'certificate' => null,
            'remark' => 'Good start',
            'education_level' => 'secondary',
            'institution' => 'Lycée El Khansa',
            'field_of_study' => 'Sciences',
        ], $overrides);
    }

    #[Test]
    public function the_first_grade_of_a_new_year_creates_the_year_with_its_school_info(): void
    {
        $player = $this->makePlayer();

        $this->actingAs($this->admin())
            ->post(route('players.academic-records.store', $player), $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'flash.academic_record_added');

        $year = PlayerAcademicYear::sole();
        $this->assertSame($player->id, $year->player_id);
        $this->assertSame(2025, $year->academic_year);
        $this->assertSame('secondary', $year->education_level);
        $this->assertSame('Lycée El Khansa', $year->institution);
        $this->assertSame('Sciences', $year->field_of_study);

        $record = PlayerAcademicRecord::sole();
        $this->assertSame($year->id, $record->player_academic_year_id);
        $this->assertSame('T1', $record->period);
        $this->assertEquals(12.5, (float) $record->gpa);
        $this->assertSame('Good start', $record->remark);
    }

    #[Test]
    public function a_later_grade_of_the_same_year_reuses_the_year_without_school_info(): void
    {
        $player = $this->makePlayer();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('players.academic-records.store', $player), $this->payload());

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), [
                'academic_year' => 2025, 'period' => 'T2', 'gpa' => 13,
            ])
            ->assertSessionHasNoErrors();

        // Different school info sent for an existing year is ignored.
        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload([
                'period' => 'T3', 'education_level' => 'licence', 'institution' => 'Other',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('player_academic_years', 1);
        $this->assertDatabaseCount('player_academic_records', 3);
        $year = PlayerAcademicYear::sole();
        $this->assertSame('secondary', $year->education_level);
        $this->assertSame('Lycée El Khansa', $year->institution);
    }

    #[Test]
    public function a_new_year_needs_an_education_level(): void
    {
        $player = $this->makePlayer();
        $admin = $this->admin();
        $this->year($player, 2024); // another year does not count

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload(['education_level' => null]))
            ->assertSessionHasErrors('education_level');

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload(['education_level' => 'kindergarten']))
            ->assertSessionHasErrors('education_level');

        $this->assertDatabaseCount('player_academic_years', 1);
        $this->assertDatabaseCount('player_academic_records', 0);
    }

    #[Test]
    public function the_grade_is_capped_by_the_scale_of_the_year(): void
    {
        $player = $this->makePlayer();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload(['gpa' => 20.5]))
            ->assertSessionHasErrors('gpa');

        // A new primary year is out of 10.
        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload(['gpa' => 10.5, 'education_level' => 'primary']))
            ->assertSessionHasErrors('gpa');
        $this->assertDatabaseCount('player_academic_years', 0);

        // An existing primary year is out of 10 whatever level is sent.
        $primary = $this->year($player, 2018, 'primary');
        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload(['academic_year' => 2018, 'gpa' => 10.5]))
            ->assertSessionHasErrors('gpa');
        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload(['academic_year' => 2018, 'gpa' => 10]))
            ->assertSessionHasNoErrors();

        $record = PlayerAcademicRecord::sole();
        $this->assertSame($primary->id, $record->player_academic_year_id);

        // Updates are capped by the record's own year too.
        $this->actingAs($admin)
            ->put(route('players.academic-records.update', [$player, $record]), ['period' => 'T1', 'gpa' => 11])
            ->assertSessionHasErrors('gpa');
    }

    #[Test]
    public function a_trimester_is_recorded_once_per_year(): void
    {
        $player = $this->makePlayer();
        $admin = $this->admin();
        $year = $this->year($player, 2025);
        $this->record($year, 'T1', 11);
        $t2 = $this->record($year, 'T2', 12);

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload())
            ->assertSessionHasErrors('period');

        $this->actingAs($admin)
            ->put(route('players.academic-records.update', [$player, $t2]), ['period' => 'T1', 'gpa' => 12])
            ->assertSessionHasErrors('period');

        // Saving onto its own slot is fine.
        $this->actingAs($admin)
            ->put(route('players.academic-records.update', [$player, $t2]), ['period' => 'T2', 'gpa' => 12])
            ->assertSessionHasNoErrors();

        // The same trimester of another year is fine.
        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload(['academic_year' => 2024]))
            ->assertSessionHasNoErrors();

        // An unknown period is refused.
        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload(['period' => 'S1']))
            ->assertSessionHasErrors('period');
    }

    #[Test]
    public function the_certificate_is_one_of_the_four_or_none(): void
    {
        $player = $this->makePlayer();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload(['certificate' => 'gold_star']))
            ->assertSessionHasErrors('certificate');

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload(['certificate' => 'encouragement']))
            ->assertSessionHasNoErrors();
        $this->assertSame('encouragement', PlayerAcademicRecord::sole()->certificate);

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload(['period' => 'T2', 'certificate' => null]))
            ->assertSessionHasNoErrors();
        $this->assertNull(PlayerAcademicRecord::where('period', 'T2')->sole()->certificate);
    }

    #[Test]
    public function a_grade_is_updated_and_deleted_while_its_year_stays(): void
    {
        $player = $this->makePlayer();
        $admin = $this->admin();
        $year = $this->year($player, 2025);
        $record = $this->record($year, 'T1', 11);

        $this->actingAs($admin)
            ->put(route('players.academic-records.update', [$player, $record]), [
                'period' => 'T1', 'gpa' => 14, 'certificate' => 'encouragement', 'remark' => 'Better',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'flash.academic_record_updated');

        $record->refresh();
        $this->assertEquals(14.0, (float) $record->gpa);
        $this->assertSame('encouragement', $record->certificate);
        $this->assertSame('Better', $record->remark);
        $this->assertSame($year->id, $record->player_academic_year_id);

        $this->actingAs($admin)
            ->delete(route('players.academic-records.destroy', [$player, $record]))
            ->assertSessionHas('success', 'flash.academic_record_deleted');

        $this->assertDatabaseCount('player_academic_records', 0);
        $this->assertDatabaseHas('player_academic_years', ['id' => $year->id]);
    }

    #[Test]
    public function grades_cannot_be_added_to_a_worker(): void
    {
        $worker = $this->makePlayer(student: false);

        $this->actingAs($this->admin())
            ->post(route('players.academic-records.store', $worker), $this->payload())
            ->assertSessionHasErrors('student');

        $this->assertDatabaseCount('player_academic_years', 0);
        $this->assertDatabaseCount('player_academic_records', 0);
    }

    #[Test]
    public function a_grade_of_another_player_is_not_found(): void
    {
        $owner = $this->makePlayer();
        $record = $this->record($this->year($owner, 2025), 'T1', 11);
        $stranger = $this->makePlayer();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('players.academic-records.update', [$stranger, $record]), ['period' => 'T1', 'gpa' => 12])
            ->assertNotFound();
        $this->actingAs($admin)
            ->delete(route('players.academic-records.destroy', [$stranger, $record]))
            ->assertNotFound();

        $this->assertDatabaseCount('player_academic_records', 1);
        $this->assertEquals(11.0, (float) $record->fresh()->gpa);
    }

    #[Test]
    public function adding_a_grade_needs_players_add(): void
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
    public function profile_shows_school_years_with_their_grades_for_students_only(): void
    {
        $student = $this->makePlayer();
        $year = $this->year($student, 2025);
        $this->record($year, 'T2', 13);
        $this->record($year, 'T1', 11);
        $worker = $this->makePlayer(student: false);
        $this->record($this->year($worker, 2025), 'T1', 11); // kept from when they studied

        $this->actingAs($this->admin())->get(route('players.show', $student))
            ->assertInertia(fn (Assert $page) => $page
                ->where('player.academic_years.0.records.0.period', 'T1')
                ->where('player.academic_years.0.records.1.period', 'T2')
                ->where('player.academic_years.0.average', 12)
                ->where('certificateThresholds.20.excellence', 16)
                ->missing('player.academic_records'));

        $this->actingAs($this->admin())->get(route('players.show', $worker))
            ->assertInertia(fn (Assert $page) => $page->missing('player.academic_years'));
    }

    #[Test]
    public function school_fields_sent_with_the_player_form_are_ignored(): void
    {
        $player = $this->makePlayer();

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => $player->firstname,
            'lastname' => $player->lastname,
            'is_student' => true,
            'education_level' => 'kindergarten',
            'institution' => 'Université de Béjaïa',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(Schema::hasColumn('players', 'education_level'));
        $this->assertDatabaseCount('player_academic_years', 0);
    }
}
