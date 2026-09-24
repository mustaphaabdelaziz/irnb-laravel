<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerAcademicYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerAcademicFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function player(string $name, bool $student = true): Player
    {
        return Player::create([
            'membership_id' => '7'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => $name,
            'lastname' => 'Test',
            'is_student' => $student,
            'outstanding_debt' => 0,
        ]);
    }

    private function year(Player $player, int $academicYear, string $level = 'secondary'): PlayerAcademicYear
    {
        return $player->academicYears()->create(['academic_year' => $academicYear, 'education_level' => $level]);
    }

    /** @return array<int, string> firstnames the list returns for the given query */
    private function listed(array $query): array
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $names = [];

        $this->actingAs($admin)->get(route('players.index', $query))
            ->assertInertia(function (Assert $page) use (&$names, $query) {
                $names = collect($page->toArray()['props']['players']['data'])->pluck('firstname')->sort()->values()->all();

                foreach ($query as $key => $value) {
                    $page->where("filters.{$key}", $value);
                }
            });

        return $names;
    }

    #[Test]
    public function buckets_use_the_latest_grade_of_students_only_converted_to_20(): void
    {
        // /10 (primary) boundary: 4/10 -> 8/20 at risk, 5/10 -> 10/20 good.
        $primaryAtRisk = $this->player('PrimaryAtRisk');
        $this->year($primaryAtRisk, 2025, 'primary')->records()->create(['period' => 'T1', 'gpa' => 4]);

        $primaryGood = $this->player('PrimaryGood');
        $this->year($primaryGood, 2025, 'primary')->records()->create(['period' => 'T1', 'gpa' => 5]);

        // /20 boundary: 9.99 at risk, 10 good.
        $secondaryAtRisk = $this->player('SecondaryAtRisk');
        $this->year($secondaryAtRisk, 2025)->records()->create(['period' => 'T1', 'gpa' => 9.99]);

        $secondaryGood = $this->player('SecondaryGood');
        $this->year($secondaryGood, 2025)->records()->create(['period' => 'T1', 'gpa' => 10]);

        $this->player('Blank'); // student, no record at all

        $worker = $this->player('Worker', student: false);
        $this->year($worker, 2025)->records()->create(['period' => 'T1', 'gpa' => 2]); // never listed

        $this->assertSame(['PrimaryAtRisk', 'SecondaryAtRisk'], $this->listed(['academic' => 'at_risk']));
        $this->assertSame(['PrimaryGood', 'SecondaryGood'], $this->listed(['academic' => 'good']));
        $this->assertSame(['Blank'], $this->listed(['academic' => 'none']));
    }

    #[Test]
    public function certificate_filter_matches_only_the_current_school_years_holder(): void
    {
        // 2026-05-14 falls before the September season start, so the current
        // school year is 2025 (2025/2026).
        $this->travelTo('2026-05-14');

        $currentHolder = $this->player('CurrentHolder');
        $this->year($currentHolder, 2025)->records()->create(['period' => 'T1', 'gpa' => 18, 'certificate' => 'excellence']);

        $pastHolder = $this->player('PastHolder');
        $this->year($pastHolder, 2024)->records()->create(['period' => 'T1', 'gpa' => 18, 'certificate' => 'excellence']);

        $otherCertificate = $this->player('OtherCertificate');
        $this->year($otherCertificate, 2025)->records()->create(['period' => 'T1', 'gpa' => 13, 'certificate' => 'honor_roll']);

        $worker = $this->player('WorkerHolder', student: false);
        $this->year($worker, 2025)->records()->create(['period' => 'T1', 'gpa' => 18, 'certificate' => 'excellence']);

        $this->assertSame(['CurrentHolder'], $this->listed(['certificate' => 'excellence']));
    }

    #[Test]
    public function an_unrecognised_certificate_value_is_ignored(): void
    {
        $this->player('Someone');

        $this->assertSame(['Someone'], $this->listed(['certificate' => 'not-a-real-certificate']));
    }
}
