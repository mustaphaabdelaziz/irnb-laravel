<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\Branch;
use App\Models\Category;
use App\Models\PlayerStatus;
use App\Models\User;
use App\Services\LiteraryReport\Sections\MembersSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MembersSectionTest extends TestCase
{
    use CompetitionFixtures;
    use RefreshDatabase;

    private int $playerSeq = 0;

    private function build(): array
    {
        return app(MembersSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    #[Test]
    public function active_members_follow_the_owner_rule(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $this->player($u15, ['join_year' => 2024]);                                   // counted
        $this->player($u15, ['join_year' => null, 'gender' => 'Female']);             // counted (no join year)
        $this->player($u15, ['join_year' => 2027]);                                   // joins after the period
        $this->player($u15, ['archived' => true]);                                    // archived
        $leftAfter = $this->player($u15, ['join_year' => 2020]);                      // left after the period end → counted
        $leftAfter->forceFill(['left_at' => '2026-10-01'])->saveQuietly();
        $leftDuring = $this->player($u15, ['join_year' => 2020]);                     // left during the period → not counted
        $leftDuring->forceFill(['left_at' => '2026-03-01'])->saveQuietly();
        $leftStatus = PlayerStatus::where('code', 'left')->first();
        if ($leftStatus) {                                                            // left status without a date → not counted
            $this->player($u15)->forceFill(['status_id' => $leftStatus->id, 'left_at' => null])->saveQuietly();
        }

        $data = $this->build();

        $this->assertSame(3, $data['total']);
        $this->assertSame(1, $data['female']);
        $this->assertSame(2, $data['male']);
        $this->assertSame(1, $data['left']);
    }

    #[Test]
    public function a_player_in_two_branches_is_counted_in_each_but_once_in_the_total(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $football = Branch::create(['name' => 'Football']);
        $swimming = Branch::create(['name' => 'Swimming']);
        $both = $this->player($u15);
        $both->branches()->attach([$football->id, $swimming->id]);
        $this->player($u15)->branches()->attach($football->id);
        $this->player($u15); // no branch

        $data = $this->build();

        $this->assertSame(3, $data['total']);
        $this->assertTrue($data['multi_branch']);
        $byBranch = collect($data['rows'])->mapWithKeys(fn ($r) => [($r['branch'] ?? '-').'/'.$r['category'] => $r['total']])->all();
        $this->assertSame(['Football/U15' => 2, 'Swimming/U15' => 1, '-/U15' => 1], $byBranch);
    }

    #[Test]
    public function joins_coaches_and_an_empty_club(): void
    {
        $this->assertSame(0, $this->build()['total']);

        $u15 = Category::create(['name' => 'U15']);
        $this->player($u15, ['join_year' => 2025]);
        $this->player($u15, ['join_year' => 2026]);
        $this->player($u15, ['join_year' => 2024]);
        $team = $this->team($u15, ['name' => 'U15 A']);
        $coach = User::factory()->create(['name' => 'Coach Karim']);
        $this->assignCoach($team, $coach, 2025);
        $this->assignCoach($team, User::factory()->create(['name' => 'Old Coach']), 2023);

        $data = $this->build();

        $this->assertSame(2, $data['joined']);
        $this->assertSame([['name' => 'Coach Karim', 'team' => 'U15 A', 'role' => 'head']], $data['coaches']);
    }
}
