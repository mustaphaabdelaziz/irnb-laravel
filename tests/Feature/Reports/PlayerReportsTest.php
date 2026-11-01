<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** R2 player reports: demographics, joins & leavers, birthdays, documents. */
class PlayerReportsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** @return array<string, mixed> */
    private function report(string $key, array $query = []): array
    {
        $props = [];
        $this->actingAs($this->admin())->get(route('reports.view', ['players', $key, ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function player(string $id, array $extra = []): Player
    {
        return Player::create($extra + ['membership_id' => $id, 'firstname' => 'P'.$id, 'lastname' => 'L'.$id]);
    }

    #[Test]
    public function demographics_count_players_per_category_and_gender(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $u17 = Category::create(['name' => 'U17']);
        $this->player('1', ['category_id' => $u15->id, 'gender' => 'Male']);
        $this->player('2', ['category_id' => $u15->id, 'gender' => 'Female']);
        $this->player('3', ['category_id' => $u17->id, 'gender' => 'Male']);
        $this->player('4', ['category_id' => $u17->id, 'gender' => 'Male', 'archived' => true]);

        $props = $this->report('demographics');
        $byCategory = collect($props['rows']['data'])->keyBy('category');

        $this->assertEquals(['total' => 2, 'boys' => 1, 'girls' => 1], collect($byCategory['U15'])->only(['total', 'boys', 'girls'])->all());
        $this->assertEquals(1, $byCategory['U17']['total']);
        $this->assertEquals(['total' => 3, 'boys' => 2, 'girls' => 1, 'unknown' => 0], $props['totals']);
    }

    #[Test]
    public function movement_lists_joins_by_join_year_and_leavers_by_date(): void
    {
        $this->player('1', ['join_year' => 2025]);
        $this->player('2', ['join_year' => 2026]);
        $this->player('3', ['join_year' => 2020, 'status_id' => Player::leftStatusId(), 'left_at' => '2026-03-01']);

        $this->assertEquals(['2'], array_column($this->report('movement', ['year' => 2026])['rows']['data'], 'membership_id'));
        $left = $this->report('movement', ['year' => 2026, 'movement' => 'left']);
        $this->assertEquals(['3'], array_column($left['rows']['data'], 'membership_id'));
        $this->assertEquals([1, 1], array_column($left['summary'], 'value'));
    }

    #[Test]
    public function birthdays_follow_the_month_and_say_the_age_turned(): void
    {
        $this->player('1', ['birthdate' => '2012-03-09']);
        $this->player('2', ['birthdate' => '2012-04-01']);
        $this->player('3', ['birthdate' => '2010-03-02', 'archived' => true]);

        $rows = $this->report('birthdays', ['month' => 3])['rows']['data'];
        $this->assertCount(1, $rows);
        $this->assertEquals('2012-03-09', $rows[0]['birthdate']);
        $this->assertEquals(9, $rows[0]['day']);
        $this->assertEquals(now()->year - 2012, $rows[0]['turns']);
    }

    #[Test]
    public function documents_list_what_players_still_owe_with_the_checklist_rules(): void
    {
        DocumentType::query()->update(['is_active' => false]);
        $medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'date', 'is_required' => true, 'is_active' => true]);
        $optional = DocumentType::create(['code' => 'opt', 'name' => 'Optional', 'validity' => 'none', 'is_required' => false, 'is_active' => true]);

        $none = $this->player('1');
        $expired = $this->player('2');
        $soon = $this->player('3');
        $fine = $this->player('4');
        PlayerDocument::create(['player_id' => $expired->id, 'document_type_id' => $medical->id, 'state' => 'received', 'valid_until' => today()->subDay()->toDateString()]);
        PlayerDocument::create(['player_id' => $soon->id, 'document_type_id' => $medical->id, 'state' => 'received', 'valid_until' => today()->addDays(10)->toDateString()]);
        PlayerDocument::create(['player_id' => $fine->id, 'document_type_id' => $medical->id, 'state' => 'received', 'valid_until' => today()->addYear()->toDateString()]);

        $props = $this->report('documents');
        $states = collect($props['rows']['data'])->mapWithKeys(fn ($r) => [$r['membership_id'] => $r['state_code']])->all();

        // The optional type is never owed; player 4 is in order.
        $this->assertEquals(['1' => 'missing', '2' => 'expired', '3' => 'expires_soon'], collect($states)->sortKeys()->all());
        $this->assertEquals(3, $props['summary'][0]['value']);

        $this->assertEquals(['1'], array_column($this->report('documents', ['state' => ['missing']])['rows']['data'], 'membership_id'));
        $this->assertEquals(0, $this->report('documents', ['document_type_id' => [$optional->id]])['rows']['total']);
    }

    #[Test]
    public function the_documents_report_needs_the_documents_right(): void
    {
        $user = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => ['players' => ['view']]])->id,
        ]);

        $this->actingAs($user)->get(route('reports.view', ['players', 'documents']))->assertForbidden();
        $this->actingAs($user)->get(route('reports.view', ['players', 'birthdays']))->assertOk();
    }
}
