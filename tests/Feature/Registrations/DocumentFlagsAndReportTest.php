<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\User;
use App\Services\Competitions\PlayerFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\Support\DocumentBranchFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DocumentFlagsAndReportTest extends TestCase
{
    use CompetitionFixtures, DocumentBranchFixtures, RefreshDatabase, RegistrationFixtures;

    private DocumentType $medical;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        DocumentType::query()->update(['is_active' => false]);
        $this->medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'none', 'is_required' => true, 'required_for_matches' => true, 'is_active' => true]);
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

    private function flagsOn(Branch $branch, Player $player): array
    {
        $team = $this->team($branch->is($this->foot) ? $this->senior : $this->u15, ['branch_id' => $branch->id]);

        return app(PlayerFlags::class)->for([$player->id], $this->admin(), $this->game($team))[$player->id];
    }

    /** @return list<array<string, mixed>> */
    private function report(array $query = []): array
    {
        $rows = [];
        $this->actingAs(User::factory()->admin()->create())->get(route('reports.view', ['players', 'documents', ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $rows = $page->toArray()['props']['rows']['data'];
            });

        return $rows;
    }

    #[Test]
    public function the_match_badge_reads_the_teams_branch(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $this->assertFalse($this->flagsOn($this->foot, $player)['documents_missing']);
        $swim = $this->flagsOn($this->swim, $player);
        $this->assertTrue($swim['documents_missing']);
        $this->assertSame(['Medical'], $swim['documents']);
    }

    #[Test]
    public function a_branch_the_player_left_shows_no_missing_paper(): void
    {
        $this->assertFalse($this->flagsOn($this->foot, $this->leftFootball())['documents_missing']);
    }

    #[Test]
    public function left_everywhere_shows_no_missing_paper(): void
    {
        $this->assertFalse($this->flagsOn($this->swim, $this->leftBoth())['documents_missing']);
    }

    #[Test]
    public function a_player_with_no_registration_is_judged_on_their_own_rows(): void
    {
        $this->assertTrue($this->flagsOn($this->foot, $this->noRegistration())['documents_missing']);
    }

    #[Test]
    public function with_no_team_the_badge_means_any_active_registration(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $this->assertTrue(app(PlayerFlags::class)->for([$player->id], $this->admin())[$player->id]['documents_missing']);
    }

    #[Test]
    public function the_report_has_one_line_per_registration_still_owing(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $rows = $this->report();

        $this->assertCount(1, $rows);
        $this->assertSame('Swimming', $rows[0]['branch']);
        $this->assertSame('U15', $rows[0]['category']);
        $this->assertSame($player->registrationIn($this->swim->id)->membership_id, $rows[0]['membership_id']);
    }

    #[Test]
    public function both_registrations_owing_give_two_lines(): void
    {
        $this->bothActive();

        $this->assertEqualsCanonicalizing(['Football', 'Swimming'], array_column($this->report(), 'branch'));
    }

    #[Test]
    public function the_report_follows_the_branch_and_category_on_the_same_registration(): void
    {
        $this->bothActive();

        $this->assertSame(['Football'], array_column($this->report(['branch_id' => [$this->foot->id]]), 'branch'));
        $this->assertSame(['Swimming'], array_column($this->report(['category_id' => [$this->u15->id]]), 'branch'));
        $this->assertSame([], $this->report(['branch_id' => [$this->swim->id], 'category_id' => [$this->senior->id]]));
    }

    #[Test]
    public function a_left_registration_owes_nothing_in_the_report(): void
    {
        $this->leftFootball();
        $this->assertSame(['Swimming'], array_column($this->report(), 'branch'));
    }

    #[Test]
    public function left_everywhere_has_no_line(): void
    {
        $this->leftBoth();
        $this->assertSame([], $this->report());
    }

    #[Test]
    public function a_player_with_no_registration_has_a_line_with_no_branch(): void
    {
        $flat = $this->noRegistration();

        $rows = $this->report();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['branch']);
        $this->assertSame('Senior', $rows[0]['category']);
        $this->assertSame($flat->membership_id, $rows[0]['membership_id']);
    }
}
