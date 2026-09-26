<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\Player\AcademicResultsSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AcademicResultsPrintTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    /** @param array<string, float> $grades period => grade */
    private function student(string $lastname, ?Category $category, array $grades = [], string $level = 'secondary', int $year = 2025, array $attributes = []): Player
    {
        $player = Player::create(array_merge([
            'membership_id' => '5'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'S',
            'lastname' => $lastname,
            'is_student' => true,
            'category_id' => $category?->id,
            'outstanding_debt' => 0,
        ], $attributes));

        if ($grades) {
            $schoolYear = $player->academicYears()->create(['academic_year' => $year, 'education_level' => $level]);
            foreach ($grades as $period => $gpa) {
                $schoolYear->records()->create(['period' => $period, 'gpa' => $gpa]);
            }
        }

        return $player;
    }

    /** @return array<int, array<int, string>> lastnames per section, in order */
    private function sheet(int $year, ?int $categoryId = null): array
    {
        return AcademicResultsSheet::build($year, $categoryId)
            ->map(fn (array $section) => $section['rows']->map(fn ($row) => $row['player']->lastname)->all())
            ->all();
    }

    #[Test]
    public function sections_follow_category_names_with_uncategorised_last(): void
    {
        $u17 = Category::create(['name' => 'U17']);
        $u13 = Category::create(['name' => 'U13']);
        $this->student('Alpha', null, ['T1' => 12]);
        $this->student('Bravo', $u17, ['T1' => 12]);
        $this->student('Charlie', $u13, ['T1' => 12]);

        $sections = AcademicResultsSheet::build(2025);

        $this->assertSame(['U13', 'U17', null], $sections->map(fn ($s) => $s['category']?->name)->all());
    }

    #[Test]
    public function students_rank_by_year_average_on_20_with_ungraded_last(): void
    {
        $cat = Category::create(['name' => 'U15']);
        $this->student('Mid', $cat, ['T1' => 12, 'T2' => 14]);            // 13/20
        $this->student('Primary', $cat, ['T1' => 8, 'T2' => 7], 'primary'); // 7.5/10 → 15/20
        $this->student('Low', $cat, ['T1' => 9]);                          // 9/20
        $this->student('Zed', $cat);                                        // no grades
        $this->student('Adam', $cat, ['T1' => 18], year: 2024);             // other year only → ungraded now

        $this->assertSame([['Primary', 'Mid', 'Low', 'Adam', 'Zed']], $this->sheet(2025));

        $row = AcademicResultsSheet::build(2025)->first()['rows']->first();
        $this->assertSame(15.0, $row['on20']);
    }

    #[Test]
    public function only_active_students_of_the_chosen_category_are_listed(): void
    {
        $cat = Category::create(['name' => 'U15']);
        $other = Category::create(['name' => 'U17']);
        $this->student('Kept', $cat, ['T1' => 12]);
        $this->student('Worker', $cat, ['T1' => 12], attributes: ['is_student' => false]);
        $this->student('Archived', $cat, ['T1' => 12], attributes: ['archived' => true]);
        $this->student('Elsewhere', $other, ['T1' => 12]);

        $this->assertSame([['Kept']], $this->sheet(2025, $cat->id));
    }

    #[Test]
    public function the_pdf_prints_for_one_category_or_all(): void
    {
        $cat = Category::create(['name' => 'U15']);
        $this->student('Mid', $cat, ['T1' => 12, 'T2' => 14]);
        $this->student('Zed', null);
        $admin = $this->admin();

        $one = $this->actingAs($admin)->get(route('players.academic-results', ['category_id' => $cat->id, 'school_year' => 2025]));
        $one->assertOk();
        $this->assertSame('application/pdf', $one->headers->get('content-type'));

        $this->actingAs($admin)->get(route('players.academic-results'))->assertOk();

        $this->actingAs($admin)->get(route('players.academic-results', ['category_id' => 999999]))
            ->assertSessionHasErrors('category_id');
    }

    #[Test]
    public function printing_results_needs_only_players_view(): void
    {
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(),
            'role_id' => Role::factory()->create(['permissions' => ['players' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)->get(route('players.academic-results'))->assertOk();
    }
}
