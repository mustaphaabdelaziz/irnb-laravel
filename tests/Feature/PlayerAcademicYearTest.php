<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerAcademicYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerAcademicYearTest extends TestCase
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
            'membership_id' => '7'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'Y'.$this->seq,
            'lastname' => 'Test',
            'is_student' => $student,
            'outstanding_debt' => 0,
        ]);
    }

    private function year(Player $player, int $year, string $level = 'secondary'): PlayerAcademicYear
    {
        return $player->academicYears()->create(['academic_year' => $year, 'education_level' => $level]);
    }

    #[Test]
    public function school_info_of_a_year_is_updated(): void
    {
        $player = $this->makePlayer();
        $year = $this->year($player, 2025, 'primary');
        $year->records()->create(['period' => 'T1', 'gpa' => 9]);

        // primary → secondary: every /10 grade fits in /20.
        $this->actingAs($this->admin())
            ->put(route('players.academic-years.update', [$player, $year]), [
                'education_level' => 'secondary',
                'institution' => 'Lycée El Khansa',
                'field_of_study' => 'Sciences',
                'academic_year' => 1999, // the year itself cannot be changed
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'flash.academic_year_updated');

        $year->refresh();
        $this->assertSame('secondary', $year->education_level);
        $this->assertSame('Lycée El Khansa', $year->institution);
        $this->assertSame('Sciences', $year->field_of_study);
        $this->assertSame(2025, $year->academic_year);
    }

    #[Test]
    public function the_level_cannot_shrink_the_scale_below_an_existing_grade(): void
    {
        $player = $this->makePlayer();
        $admin = $this->admin();
        $year = $this->year($player, 2025, 'secondary');
        $t1 = $year->records()->create(['period' => 'T1', 'gpa' => 12]);
        $year->records()->create(['period' => 'T2', 'gpa' => 8]);

        $this->actingAs($admin)
            ->put(route('players.academic-years.update', [$player, $year]), ['education_level' => 'primary'])
            ->assertSessionHasErrors('education_level');
        $this->assertSame('secondary', $year->fresh()->education_level);

        $t1->update(['gpa' => 10]);
        $this->actingAs($admin)
            ->put(route('players.academic-years.update', [$player, $year]), ['education_level' => 'primary'])
            ->assertSessionHasNoErrors();
        $this->assertSame('primary', $year->fresh()->education_level);

        $this->actingAs($admin)
            ->put(route('players.academic-years.update', [$player, $year]), ['education_level' => 'kindergarten'])
            ->assertSessionHasErrors('education_level');
    }

    #[Test]
    public function deleting_a_year_deletes_its_trimesters(): void
    {
        $player = $this->makePlayer();
        $year = $this->year($player, 2025);
        $year->records()->create(['period' => 'T1', 'gpa' => 12]);
        $year->records()->create(['period' => 'T2', 'gpa' => 13]);
        $other = $this->year($player, 2024);
        $other->records()->create(['period' => 'T1', 'gpa' => 14]);

        $this->actingAs($this->admin())
            ->delete(route('players.academic-years.destroy', [$player, $year]))
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.academic_year_deleted');

        $this->assertDatabaseMissing('player_academic_years', ['id' => $year->id]);
        $this->assertDatabaseCount('player_academic_years', 1);
        $this->assertDatabaseCount('player_academic_records', 1);
    }

    #[Test]
    public function a_worker_year_cannot_be_updated(): void
    {
        $worker = $this->makePlayer(student: false);
        $year = $this->year($worker, 2025);

        $this->actingAs($this->admin())
            ->put(route('players.academic-years.update', [$worker, $year]), ['education_level' => 'licence'])
            ->assertSessionHasErrors('student');

        $this->assertSame('secondary', $year->fresh()->education_level);
    }

    #[Test]
    public function a_year_of_another_player_is_not_found(): void
    {
        $year = $this->year($this->makePlayer(), 2025);
        $stranger = $this->makePlayer();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('players.academic-years.update', [$stranger, $year]), ['education_level' => 'licence'])
            ->assertNotFound();
        $this->actingAs($admin)
            ->delete(route('players.academic-years.destroy', [$stranger, $year]))
            ->assertNotFound();

        $this->assertDatabaseHas('player_academic_years', ['id' => $year->id, 'education_level' => 'secondary']);
    }
}
