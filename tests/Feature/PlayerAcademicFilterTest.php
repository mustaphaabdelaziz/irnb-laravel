<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerAcademicFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function player(string $name, bool $student = true, array $gpas = []): Player
    {
        $player = Player::create([
            'membership_id' => '7'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => $name,
            'lastname' => 'Test',
            'is_student' => $student,
            'outstanding_debt' => 0,
        ]);

        foreach ($gpas as [$year, $period, $gpa]) {
            $player->academicRecords()->create(['academic_year' => $year, 'period' => $period, 'gpa' => $gpa]);
        }

        return $player;
    }

    /** @return array<int, string> firstnames the list returns for ?academic=$bucket */
    private function listed(string $bucket): array
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $names = [];

        $this->actingAs($admin)->get(route('players.index', ['academic' => $bucket]))
            ->assertInertia(function (Assert $page) use (&$names, $bucket) {
                $names = collect($page->toArray()['props']['players']['data'])->pluck('firstname')->sort()->values()->all();
                $page->where('filters.academic', $bucket);
            });

        return $names;
    }

    #[Test]
    public function buckets_use_the_latest_gpa_of_students_only(): void
    {
        $this->player('Recovered', gpas: [[2024, 'S1', 8], [2024, 'S2', 12]]);   // latest 12 → good
        $this->player('Slipping', gpas: [[2024, 'S2', 15], [2025, 'S1', 9.99]]); // latest 9.99 → at risk
        $this->player('Borderline', gpas: [[2025, 'ANNUAL', 10]]);               // exactly 10 → good
        $this->player('Blank');                                                   // student, no GPA
        $this->player('Worker', student: false, gpas: [[2025, 'S1', 5]]);       // never listed

        $this->assertSame(['Slipping'], $this->listed('at_risk'));
        $this->assertSame(['Borderline', 'Recovered'], $this->listed('good'));
        $this->assertSame(['Blank'], $this->listed('none'));
    }
}
