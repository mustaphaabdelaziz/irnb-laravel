<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\CountryState;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every select filter of the players list takes several values (OR within a
 * filter, AND across filters), old scalar links keep working, and "Left the
 * club" players stay hidden until a status is picked.
 */
class PlayerMultiSelectFiltersTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private ?User $admin = null;

    private function player(string $lastname, array $attributes = []): Player
    {
        return Player::create([
            'membership_id' => '8'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'P'.$this->seq,
            'lastname' => $lastname,
            'is_student' => false,
            'outstanding_debt' => 0,
            ...$attributes,
        ]);
    }

    private function props(array $query = []): array
    {
        $this->admin ??= User::factory()->admin()->create(['email_verified_at' => now()]);

        return $this->actingAs($this->admin)
            ->get(route('players.index', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    /** @return list<string> */
    private function names(array $query = []): array
    {
        return collect($this->props($query)['players']['data'])->pluck('lastname')->sort()->values()->all();
    }

    private function playerStatus(string $code): PlayerStatus
    {
        return PlayerStatus::where('code', $code)->firstOrFail();
    }

    #[Test]
    public function several_categories_show_players_of_any_of_them(): void
    {
        $u13 = Category::create(['name' => 'U13']);
        $u15 = Category::create(['name' => 'U15']);
        $u17 = Category::create(['name' => 'U17']);
        $this->player('A', ['category_id' => $u13->id]);
        $this->player('B', ['category_id' => $u15->id]);
        $this->player('C', ['category_id' => $u17->id]);

        $this->assertSame(['A', 'B'], $this->names(['category_id' => [$u13->id, $u15->id]]));
        // Old links and dashboard drill-downs send one scalar value.
        $this->assertSame(['C'], $this->names(['category_id' => $u17->id]));
    }

    #[Test]
    public function the_filters_echo_returns_lists(): void
    {
        $u13 = Category::create(['name' => 'U13']);

        $filters = $this->props(['category_id' => $u13->id, 'academic' => ['at_risk', 'none'], 'search' => 'x'])['filters'];

        $this->assertSame([(string) $u13->id], $filters['category_id']);
        $this->assertSame(['at_risk', 'none'], $filters['academic']);
        $this->assertSame('x', $filters['search']);
    }

    #[Test]
    public function filters_are_anded_across_and_ored_within(): void
    {
        $u13 = Category::create(['name' => 'U13']);
        $u15 = Category::create(['name' => 'U15']);
        $this->player('Ali', ['category_id' => $u13->id, 'health_blood_group_rhesus' => 'A+']);
        $this->player('Ali', ['category_id' => $u15->id, 'health_blood_group_rhesus' => 'B-']);
        $this->player('Saadi', ['category_id' => $u15->id, 'health_blood_group_rhesus' => 'A+']);
        $this->player('Khelifi', ['category_id' => $u13->id, 'health_blood_group_rhesus' => 'B+']);

        $this->assertSame(['Ali', 'Saadi'], $this->names([
            'category_id' => [$u13->id, $u15->id],
            'blood_group' => ['A+', 'O-'],
            'lastname' => ['Ali', 'Saadi'],
        ]));
        $this->assertSame(['Ali', 'Ali'], $this->names(['lastname' => ['Ali']]));
    }

    #[Test]
    public function several_branches_and_positions(): void
    {
        $football = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swim']);
        $judo = Branch::create(['name' => 'Judo']);
        $gk = Position::create(['abbreviation' => 'GK', 'name' => 'Goalkeeper']);
        $st = Position::create(['abbreviation' => 'ST', 'name' => 'Striker']);
        $cb = Position::create(['abbreviation' => 'CB', 'name' => 'Centre back']);

        $this->player('A', ['position_id' => $gk->id])->branches()->attach($football->id);
        $b = $this->player('B', ['position_id' => $cb->id]);
        $b->branches()->attach($swim->id);
        $b->otherPositions()->attach($st->id);
        $this->player('C', ['position_id' => $cb->id])->branches()->attach($judo->id);

        $this->assertSame(['A', 'B'], $this->names(['branch_id' => [$football->id, $swim->id]]));
        $this->assertSame(['A', 'B'], $this->names(['position_id' => [$gk->id, $st->id]]));
    }

    #[Test]
    public function wilaya_none_mixes_with_ids(): void
    {
        [$first, $second] = CountryState::query()->whereNotNull('code')->orderBy('code')->take(2)->get();
        $this->player('First', ['wilaya_id' => $first->id]);
        $this->player('Second', ['wilaya_id' => $second->id]);
        $this->player('Nowhere');

        $this->assertSame(['First', 'Nowhere'], $this->names(['wilaya_id' => ['none', $first->id]]));
        $this->assertSame(['Nowhere'], $this->names(['wilaya_id' => 'none']));
    }

    #[Test]
    public function age_buckets_mix(): void
    {
        $this->player('Kid', ['birthdate' => now()->subYears(8)->toDateString()]);
        $this->player('Teen', ['birthdate' => now()->subYears(15)->toDateString()]);
        $this->player('Adult', ['birthdate' => now()->subYears(25)->toDateString()]);
        $this->player('Unknown');

        $this->assertSame(['Kid', 'Unknown'], $this->names(['age' => ['u10', 'unknown']]));
        $this->assertSame(['Teen'], $this->names(['age' => '10-19']));
    }

    #[Test]
    public function academic_values_mix_and_stay_students_only(): void
    {
        $atRisk = $this->player('AtRisk', ['is_student' => true]);
        $atRisk->academicYears()->create(['academic_year' => 2025, 'education_level' => 'secondary'])
            ->records()->create(['period' => 'T1', 'gpa' => 8]);
        $good = $this->player('Good', ['is_student' => true]);
        $good->academicYears()->create(['academic_year' => 2025, 'education_level' => 'secondary'])
            ->records()->create(['period' => 'T1', 'gpa' => 15]);
        $this->player('Blank', ['is_student' => true]);
        $this->player('Worker');

        $this->assertSame(['AtRisk', 'Blank'], $this->names(['academic' => ['at_risk', 'none']]));
        $this->assertSame(['AtRisk', 'Blank', 'Good'], $this->names(['academic' => ['at_risk', 'good', 'none', 'bogus']]));
        // Unknown values alone filter nothing, as before.
        $this->assertCount(4, $this->names(['academic' => ['bogus']]));
    }

    #[Test]
    public function left_the_club_players_are_hidden_until_a_status_is_picked(): void
    {
        $registered = $this->playerStatus('registered');
        $left = $this->playerStatus('left');
        $this->player('Member', ['status_id' => $registered->id]);
        $this->player('Gone', ['status_id' => $left->id]);
        $this->player('NoStatus');

        $this->assertSame(['Member', 'NoStatus'], $this->names());
        $this->assertSame(['Gone'], $this->names(['status' => [$left->id]]));
        $this->assertSame(['Gone', 'Member'], $this->names(['status' => [$left->id, $registered->id]]));
        $this->assertSame(['Member'], $this->names(['status' => $registered->id]));
    }

    #[Test]
    public function charts_follow_the_default_but_the_status_chart_still_offers_left(): void
    {
        $registered = $this->playerStatus('registered');
        $left = $this->playerStatus('left');
        $this->player('Member', ['status_id' => $registered->id, 'birthdate' => '2010-01-01']);
        $this->player('Gone', ['status_id' => $left->id, 'birthdate' => '2010-01-01']);
        $this->player('Gone', ['status_id' => $left->id]);

        $props = $this->props();

        $this->assertSame(1, collect($props['categoryStats'])->sum('count'));
        $this->assertSame(1, collect($props['ageStats'])->sum('count'));
        $this->assertSame(1, collect($props['familyStats'])->sum('count'));
        $statusCounts = collect($props['statusStats'])->pluck('count', 'status_id');
        $this->assertSame(2, $statusCounts[$left->id]);
        $this->assertSame(1, $statusCounts[$registered->id]);

        // Picking Left: the other charts describe the left players.
        $this->assertSame(2, collect($this->props(['status' => [$left->id]])['categoryStats'])->sum('count'));
    }

    #[Test]
    public function the_export_honours_arrays_and_the_left_default(): void
    {
        $u13 = Category::create(['name' => 'U13']);
        $u15 = Category::create(['name' => 'U15']);
        $u17 = Category::create(['name' => 'U17']);
        $left = $this->playerStatus('left');
        $this->player('A', ['membership_id' => '9000000001', 'category_id' => $u13->id]);
        $this->player('B', ['membership_id' => '9000000002', 'category_id' => $u15->id]);
        $this->player('C', ['membership_id' => '9000000003', 'category_id' => $u17->id]);
        $this->player('D', ['membership_id' => '9000000004', 'category_id' => $u13->id, 'status_id' => $left->id]);

        $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
        $content = $this->get(route('players.export', ['format' => 'csv', 'category_id' => [$u13->id, $u15->id]]))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('9000000001', $content);
        $this->assertStringContainsString('9000000002', $content);
        $this->assertStringNotContainsString('9000000003', $content);
        $this->assertStringNotContainsString('9000000004', $content);

        $withLeft = $this->get(route('players.export', ['format' => 'csv', 'status' => [$left->id]]))->streamedContent();
        $this->assertStringContainsString('9000000004', $withLeft);
        $this->assertStringNotContainsString('9000000001', $withLeft);
    }

    #[Test]
    public function documents_and_certificate_values_mix(): void
    {
        $this->player('Pictured', ['picture_url' => '/media/players/x.jpg']);
        $this->player('Plain');

        $photo = DocumentType::where('code', 'photo')->firstOrFail();

        // missing-photo OR nonsense: the nonsense value adds nothing.
        $this->assertSame(['Plain'], $this->names(['documents' => ['missing-'.$photo->id, 'nonsense']]));
        $this->assertSame(['Pictured', 'Plain'], $this->names(['documents' => ['missing', 'missing-'.$photo->id]]));
        $this->assertSame([], $this->names(['certificate' => ['excellence', 'honor_roll']]));
    }

    #[Test]
    public function junk_values_are_ignored(): void
    {
        $u13 = Category::create(['name' => 'U13']);
        $this->player('A', ['category_id' => $u13->id]);
        $this->player('B');

        $this->assertSame(['A'], $this->names(['category_id' => [$u13->id, 'abc', '', '-3']]));
        $this->assertSame(['A', 'B'], $this->names(['category_id' => ['', 'abc']]));
    }
}
