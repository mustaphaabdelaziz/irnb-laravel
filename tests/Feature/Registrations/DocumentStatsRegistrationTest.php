<?php

namespace Tests\Feature\Registrations;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\User;
use App\Services\Player\DocumentStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\Support\DocumentBranchFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DocumentStatsRegistrationTest extends TestCase
{
    use CompetitionFixtures, DocumentBranchFixtures, RefreshDatabase, RegistrationFixtures;

    private DocumentType $medical;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        DocumentType::query()->update(['is_active' => false]);
        $this->medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'none', 'is_required' => true, 'is_active' => true]);
        $this->requireDocumentsEverywhere();
    }

    private function received(Player $player, Branch $branch): void
    {
        PlayerDocument::create([
            'player_id' => $player->id,
            'branch_registration_id' => $player->registrations()->where('branch_id', $branch->id)->value('id'),
            'document_type_id' => $this->medical->id,
            'state' => PlayerDocument::RECEIVED,
        ]);
    }

    private function stats(): DocumentStats
    {
        return app(DocumentStats::class);
    }

    #[Test]
    public function club_wide_a_person_counts_once_and_each_registration_owes(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $summary = $this->stats()->summary(Player::query());

        $this->assertSame(1, $summary['players_total']);
        $this->assertSame(0, $summary['players_complete']);
        $this->assertSame(2, $summary['required']);
        $this->assertSame(1, $summary['received']);
        $this->assertSame(1, $summary['missing']);
    }

    #[Test]
    public function with_a_branch_only_that_registration_owes(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $foot = $this->stats()->summary(Player::query(), null, $this->foot->id);
        $this->assertSame(1, $foot['required']);
        $this->assertSame(0, $foot['missing']);
        $this->assertSame(1, $foot['players_complete']);

        $swim = $this->stats()->summary(Player::query(), null, $this->swim->id);
        $this->assertSame(1, $swim['required']);
        $this->assertSame(1, $swim['missing']);
        $this->assertSame(0, $swim['players_complete']);
    }

    #[Test]
    public function a_left_registration_owes_nothing(): void
    {
        $this->leftFootball();

        $summary = $this->stats()->summary(Player::query());
        $this->assertSame(1, $summary['required']);

        $none = $this->stats()->summary(Player::query(), null, $this->foot->id);
        $this->assertSame(0, $none['required']);
        $this->assertSame(0, $none['players_total']);
        $this->assertSame(0, $none['players_complete']);
    }

    #[Test]
    public function left_everywhere_owes_nothing_and_stays_out_of_the_category_lines(): void
    {
        $this->leftBoth();

        $result = $this->stats()->breakdown(Player::query());

        $this->assertSame(0, $result['summary']['required']);
        $this->assertSame(0, $this->stats()->summary(Player::query(), null, $this->foot->id)['players_total']);
        $this->assertSame([], $result['by_category']);
    }

    #[Test]
    public function category_lines_count_each_active_registration(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $result = $this->stats()->breakdown(Player::query());
        $rows = collect($result['by_category'])->keyBy('name');

        $this->assertSame(1, $result['summary']['players_total']);
        $this->assertSame(1, $rows['Senior']['players_total']);
        $this->assertSame(1, $rows['Senior']['players_complete']);
        $this->assertSame(0, $rows['Senior']['missing']);
        $this->assertSame(1, $rows['U15']['players_total']);
        $this->assertSame(0, $rows['U15']['players_complete']);
        $this->assertSame(1, $rows['U15']['missing']);
    }

    #[Test]
    public function category_lines_follow_the_branch_chosen(): void
    {
        $this->bothActive();

        $rows = $this->stats()->breakdown(Player::query(), null, $this->swim->id)['by_category'];

        $this->assertSame(['U15'], array_column($rows, 'name'));
    }

    #[Test]
    public function a_player_with_no_registration_shows_under_their_own_category(): void
    {
        $this->noRegistration();

        $result = $this->stats()->breakdown(Player::query());

        $this->assertSame(['Senior'], array_column($result['by_category'], 'name'));
        $this->assertSame(1, $result['summary']['missing']);
    }

    #[Test]
    public function the_dashboard_uses_the_branch_chosen(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);
        $admin = User::factory()->admin()->create();

        $members = fn (array $query) => $this->actingAs($admin)
            ->get(route('dashboard', $query), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'members',
            ])
            ->assertOk()->json('props.members');

        $this->assertSame(0, $members(['branch' => $this->foot->id])['documents']['summary']['missing']);
        $this->assertSame(1, $members(['branch' => $this->swim->id])['documents']['summary']['missing']);
    }

    #[Test]
    public function the_team_page_counts_the_teams_own_branch(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);
        $team = $this->team($this->u15, ['branch_id' => $this->swim->id]);
        $this->onRoster($team, \App\Support\Season::current()->startYear, [$player]);

        $this->actingAs($this->admin())->get(route('teams.show', $team))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentStats.required', 1)
                ->where('documentStats.missing', 1));
    }

    #[Test]
    public function the_players_list_counts_and_filters_on_the_branch_chosen(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $list = fn (array $query) => $this->actingAs($admin)->get(route('players.index', $query))->assertOk();

        $list(['branch_id' => [$this->foot->id]])->assertInertia(fn (Assert $page) => $page
            ->where('players.data.0.missing_documents_count', 0)
            ->where('documentStats.missing', 0)
            ->etc());
        $list(['branch_id' => [$this->swim->id]])->assertInertia(fn (Assert $page) => $page
            ->where('players.data.0.missing_documents_count', 1)
            ->where('documentStats.missing', 1)
            ->etc());
        // Several branches, or none: any active registration.
        $list([])->assertInertia(fn (Assert $page) => $page->where('players.data.0.missing_documents_count', 1)->etc());
        $list(['branch_id' => [$this->foot->id, $this->swim->id]])->assertInertia(fn (Assert $page) => $page->where('players.data.0.missing_documents_count', 1)->etc());

        $list(['branch_id' => [$this->foot->id], 'documents' => ['missing']])->assertInertia(fn (Assert $page) => $page->has('players.data', 0)->etc());
        $list(['branch_id' => [$this->swim->id], 'documents' => ['missing']])->assertInertia(fn (Assert $page) => $page->has('players.data', 1)->etc());
        $list(['documents' => ['missing']])->assertInertia(fn (Assert $page) => $page->has('players.data', 1)->etc());
        $list(['branch_id' => [$this->foot->id], 'documents' => ['missing-'.$this->medical->id]])->assertInertia(fn (Assert $page) => $page->has('players.data', 0)->etc());
    }

    #[Test]
    public function a_branchs_figures_count_only_people_active_in_it(): void
    {
        $this->leftFootball();
        $this->leftBoth('Gone');
        $this->noRegistration();

        $foot = $this->stats()->summary(Player::query(), null, $this->foot->id);
        $this->assertSame(1, $foot['players_total']);
        $this->assertSame(1, $foot['required']);

        $swim = $this->stats()->summary(Player::query(), null, $this->swim->id);
        $this->assertSame(2, $swim['players_total']);
        $this->assertSame(0, $swim['players_complete']);
    }

    #[Test]
    public function the_dashboard_branch_figures_leave_out_someone_who_left_that_branch(): void
    {
        $this->leftFootball();
        $admin = User::factory()->admin()->create();

        $documents = $this->actingAs($admin)
            ->get(route('dashboard', ['branch' => $this->foot->id]), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'members',
            ])
            ->assertOk()->json('props.members.documents');

        $this->assertSame(0, $documents['summary']['players_total']);
        $this->assertSame(0, $documents['summary']['players_complete']);
        $this->assertSame([], $documents['by_category']);
    }

    #[Test]
    public function the_team_page_leaves_out_a_guest_from_another_sport(): void
    {
        $member = $this->bothActive();
        $guest = $this->bothActive('Guest');
        app(\App\Services\Player\RegistrationService::class)->leave($guest, [$this->swim->id], $this->leaveDate);
        $team = $this->team($this->u15, ['branch_id' => $this->swim->id]);
        $this->onRoster($team, \App\Support\Season::current()->startYear, [$member, $guest]);

        $this->actingAs($this->admin())->get(route('teams.show', $team))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentStats.players_total', 1)
                ->where('documentStats.players_complete', 0));
    }
}
