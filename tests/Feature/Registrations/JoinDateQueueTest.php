<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JoinDateQueueTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    private function member(string $name, int $year, array $branches): Player
    {
        $player = Player::create(['firstname' => $name, 'membership_id' => $year.substr(md5($name), 0, 5), 'join_year' => $year]);
        $player->branches()->attach($branches);

        return $player;
    }

    #[Test]
    public function two_branch_players_come_first_and_exact_ones_stay_out(): void
    {
        $this->member('Single', 2017, [$this->foot->id]);
        $this->member('Double', 2019, [$this->foot->id, $this->swim->id]);
        $exact = $this->member('Exact', 2020, [$this->foot->id]);
        $exact->registrations()->update(['join_date' => '2020-05-05']);

        $this->get(route('players.join-dates.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Players/JoinDates')
                ->where('total', 3)
                ->where('registrations.data.0.player_name', fn ($name) => str_contains($name, 'Double'))
                ->where('registrations.data.1.player_name', fn ($name) => str_contains($name, 'Double'))
                ->where('registrations.data.2.player_name', fn ($name) => str_contains($name, 'Single')));
    }

    #[Test]
    public function it_filters_by_branch_and_year(): void
    {
        $this->member('A', 2017, [$this->foot->id]);
        $this->member('B', 2019, [$this->swim->id]);

        $this->get(route('players.join-dates.index', ['branch_id' => $this->swim->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('registrations.data', 1)->etc());
        $this->get(route('players.join-dates.index', ['year' => 2017]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('registrations.data', 1)->etc());
    }

    #[Test]
    public function the_entry_date_is_only_a_hint_when_its_year_matches(): void
    {
        $match = $this->member('Match', 2026, [$this->foot->id]);
        $other = $this->member('Other', 2017, [$this->foot->id]);
        DB::table('players')->where('id', $match->id)->update(['created_at' => '2026-10-24 10:00:00']);
        DB::table('players')->where('id', $other->id)->update(['created_at' => '2026-10-24 10:00:00']);

        $this->get(route('players.join-dates.index', ['year' => 2026]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('registrations.data.0.hint', '2026-10-24')->etc());
        $this->get(route('players.join-dates.index', ['year' => 2017]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('registrations.data.0.hint', null)->etc());
        $this->assertSame(2, BranchRegistration::needsJoinDate()->count());
    }

    #[Test]
    public function saving_sets_the_date_and_rejects_bad_ones(): void
    {
        $player = $this->member('A', 2017, [$this->foot->id]);
        $player->update(['birthdate' => '2010-01-01']);
        $registration = $player->registrations()->firstOrFail();

        $this->put(route('players.join-dates.update', $registration), ['join_date' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('join_date');
        $this->put(route('players.join-dates.update', $registration), ['join_date' => '2009-12-31'])
            ->assertSessionHasErrors('join_date');

        $this->put(route('players.join-dates.update', $registration), ['join_date' => '2017-03-12'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2017-03-12', $registration->fresh()->join_date->toDateString());
    }

    #[Test]
    public function the_first_of_september_is_suggested_for_football_and_single_branch_rows_only(): void
    {
        \App\Models\WebsiteConfig::singleton()->update(['main_branch_id' => $this->foot->id]);
        $this->member('Double', 2015, [$this->foot->id, $this->swim->id]);
        $this->member('SwimOnly', 2018, [$this->swim->id]);

        $rows = collect($this->get(route('players.join-dates.index'))->viewData('page')['props']['registrations']['data']);
        $suggestion = fn (string $name, string $branch) => $rows
            ->first(fn ($row) => str_contains($row['player_name'], $name) && $row['branch'] === $branch)['suggestion'];

        $this->assertSame('2015-09-01', $suggestion('Double', 'Football'));
        $this->assertNull($suggestion('Double', 'Swimming'));
        $this->assertSame('2018-09-01', $suggestion('SwimOnly', 'Swimming'));
    }

    #[Test]
    public function no_suggestion_lands_before_birth_or_after_today(): void
    {
        $this->member('Born', 2015, [$this->foot->id])->update(['birthdate' => '2015-10-01']);
        $this->member('Now', (int) now()->year, [$this->foot->id]);
        $future = now()->setDate((int) now()->year, 9, 1)->isFuture();

        $rows = collect($this->get(route('players.join-dates.index'))->viewData('page')['props']['registrations']['data']);
        $this->assertNull($rows->first(fn ($row) => str_contains($row['player_name'], 'Born'))['suggestion']);
        $this->assertSame($future ? null : now()->year.'-09-01', $rows->first(fn ($row) => str_contains($row['player_name'], 'Now'))['suggestion']);
    }

    #[Test]
    public function saving_all_keeps_the_good_dates_and_reports_the_bad_ones(): void
    {
        $good = $this->member('Good', 2015, [$this->foot->id])->registrations()->firstOrFail();
        $bad = $this->member('Bad', 2015, [$this->foot->id]);
        $bad->update(['birthdate' => '2016-01-01']);
        $badRow = $bad->registrations()->firstOrFail();

        $this->put(route('players.join-dates.bulk-update'), ['dates' => [$good->id => '2015-09-01', $badRow->id => '2015-09-01']])
            ->assertSessionHasErrors('dates.'.$badRow->id);

        $this->assertSame('2015-09-01', $good->fresh()->join_date->toDateString());
        $this->assertNull($badRow->fresh()->join_date);
    }

    #[Test]
    public function saving_all_never_overwrites_an_exact_date(): void
    {
        $registration = $this->member('Exact', 2015, [$this->foot->id])->registrations()->firstOrFail();
        $registration->update(['join_date' => '2015-03-03']);

        $this->put(route('players.join-dates.bulk-update'), ['dates' => [$registration->id => '2015-09-01']]);

        $this->assertSame('2015-03-03', $registration->fresh()->join_date->toDateString());
    }

    #[Test]
    public function it_needs_player_rights(): void
    {
        $viewer = User::factory()->create(['privileges' => [], 'is_active' => true, 'email_verified_at' => now()]);
        $registration = $this->member('A', 2017, [$this->foot->id])->registrations()->firstOrFail();

        $this->actingAs($viewer)->get(route('players.join-dates.index'))->assertForbidden();
        $this->actingAs($viewer)->put(route('players.join-dates.update', $registration), ['join_date' => '2017-03-12'])->assertForbidden();
        $this->actingAs($viewer)->put(route('players.join-dates.bulk-update'), ['dates' => [$registration->id => '2017-03-12']])->assertForbidden();
    }

    #[Test]
    public function a_saved_last_row_returns_to_the_last_page_with_rows(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $this->member('Player '.$i, 2017, [$this->foot->id]);
        }

        $page2 = $this->get(route('players.join-dates.index', ['page' => 2]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('registrations.data', 1)->etc());
        $rowId = $page2->viewData('page')['props']['registrations']['data'][0]['id'];

        $this->put(route('players.join-dates.update', $rowId), ['join_date' => '2017-03-12'])
            ->assertSessionHasNoErrors();

        $this->get(route('players.join-dates.index', ['page' => 2]))
            ->assertRedirect(route('players.join-dates.index', ['page' => 1]));

        $this->followingRedirects()
            ->get(route('players.join-dates.index', ['page' => 2]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('registrations.data', 50)->etc());
    }
}
