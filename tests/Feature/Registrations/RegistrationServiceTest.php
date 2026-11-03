<?php

namespace Tests\Feature\Registrations;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\WebsiteConfig;
use App\Services\Activity\ActivityAction;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    private RegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $this->foot->id]);
        $this->service = app(RegistrationService::class);
    }

    private function player(array $extra = []): Player
    {
        return Player::create(['firstname' => 'A', 'membership_id' => '202200017', 'join_year' => 2022, ...$extra]);
    }

    private function left(): PlayerStatus
    {
        return PlayerStatus::where('code', 'left')->firstOrFail();
    }

    #[Test]
    public function two_registrations_hold_independent_dates_and_categories(): void
    {
        $a = Category::create(['name' => 'Senior']);
        $b = Category::create(['name' => 'Masters']);
        $player = $this->player();

        $this->service->sync($player, [
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15', 'category_id' => $a->id],
            ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10', 'category_id' => $b->id],
        ]);

        $rows = $player->registrations()->get()->keyBy('branch_id');
        $this->assertSame('2022-09-15', $rows[$this->foot->id]->join_date->toDateString());
        $this->assertSame('2025-03-10', $rows[$this->swim->id]->join_date->toDateString());
        $this->assertSame('SW-2025-00001', $rows[$this->swim->id]->membership_id);
        $this->assertSame('202200017', $rows[$this->foot->id]->membership_id);
    }

    #[Test]
    public function the_player_row_copies_the_main_branch_registration(): void
    {
        $a = Category::create(['name' => 'Senior']);
        $b = Category::create(['name' => 'Masters']);
        $player = $this->player();

        $this->service->sync($player, [
            ['branch_id' => $this->swim->id, 'join_date' => '2020-01-05', 'category_id' => $b->id],
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15', 'category_id' => $a->id, 'licence_number' => 'L9'],
        ]);

        $player->refresh();
        $this->assertSame($a->id, $player->category_id);
        $this->assertSame('L9', $player->licence_number);
        $this->assertSame(2020, $player->join_year, 'join_year is the earliest');
    }

    #[Test]
    public function a_player_is_left_only_after_leaving_every_branch(): void
    {
        $player = $this->player();
        $this->service->sync($player, [
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15'],
            ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10'],
        ]);
        $active = $player->fresh()->status_id;

        $this->service->leave($player, [$this->foot->id], '2026-01-10');
        $player->refresh();
        $this->assertNull($player->left_at);
        $this->assertSame($active, $player->status_id);

        $this->service->leave($player, [$this->swim->id], '2026-02-20');
        $player->refresh();
        $this->assertSame($this->left()->id, $player->status_id);
        $this->assertSame('2026-02-20', $player->left_at->toDateString());
    }

    #[Test]
    public function primary_falls_to_the_earliest_active_when_the_main_branch_is_left(): void
    {
        $player = $this->player();
        $this->service->sync($player, [
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15'],
            ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10'],
        ]);

        $this->service->leave($player, [$this->foot->id]);

        $this->assertSame($this->swim->id, $this->service->primary($player)->branch_id);
        $this->assertSame('SW-2025-00001', $player->fresh()->membership_id);
    }

    #[Test]
    public function a_card_left_out_of_sync_is_marked_left_not_deleted(): void
    {
        $player = $this->player();
        $this->service->sync($player, [
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15'],
            ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10'],
        ]);

        $this->service->sync($player, [['branch_id' => $this->foot->id]]);

        $swim = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();
        $this->assertSame($this->left()->id, $swim->status_id);
        $this->assertSame('2025-03-10', $swim->join_date->toDateString());
    }

    #[Test]
    public function adding_the_day_keeps_the_id_and_changing_the_year_renumbers_and_logs(): void
    {
        $player = $this->player();
        $player->branches()->attach($this->foot->id, ['membership_id' => 'FB-2022-00004', 'category_id' => null, 'legacy_join_year' => 2022]);
        $registration = $player->registrations()->firstOrFail();

        $this->service->setJoinDate($registration, '2022-06-01');
        $this->assertSame('FB-2022-00004', $registration->fresh()->membership_id);
        $this->assertSame(0, ActivityLog::where('action', ActivityAction::MEMBERSHIP_ID_CHANGED)->count());

        $this->service->setJoinDate($registration->fresh(), '2021-06-01');
        $fresh = $registration->fresh();
        $this->assertSame('FB-2021-00001', $fresh->membership_id);
        $this->assertSame(2022, $fresh->legacy_join_year, 'the original year is kept');
        $this->assertSame(1, ActivityLog::where('action', ActivityAction::MEMBERSHIP_ID_CHANGED)->count());
    }

    #[Test]
    public function an_old_id_is_kept_when_a_year_change_replaces_it(): void
    {
        $player = $this->player();
        $player->branches()->attach($this->foot->id, ['membership_id' => '202200017', 'category_id' => null, 'legacy_join_year' => 2022]);
        $registration = $player->registrations()->firstOrFail();

        $this->service->setJoinDate($registration, '2021-06-01');

        $fresh = $registration->fresh();
        $this->assertSame('202200017', $fresh->legacy_membership_id);
        $this->assertSame('FB-2021-00001', $fresh->membership_id);
        $this->assertSame('FB-2021-00001', $player->fresh()->membership_id);
    }

    #[Test]
    public function a_saved_date_cannot_be_cleared_by_a_card_without_one(): void
    {
        $player = $this->player();
        $this->service->sync($player, [['branch_id' => $this->foot->id, 'join_date' => '2022-09-15']]);

        $this->service->sync($player, [['branch_id' => $this->foot->id, 'join_date' => null]]);

        $this->assertSame('2022-09-15', $player->registrations()->first()->join_date->toDateString());
    }

    #[Test]
    public function other_positions_are_kept_per_card_and_never_repeat_a_main_one(): void
    {
        $gk = Position::create(['name' => 'GK', 'abbreviation' => 'GK', 'branch_id' => $this->foot->id]);
        $df = Position::create(['name' => 'DF', 'abbreviation' => 'DF', 'branch_id' => $this->foot->id]);
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $player = $this->player();
        $player->otherPositions()->sync([$crawl->id]);
        $this->service->sync($player, [['branch_id' => $this->swim->id, 'join_date' => '2025-03-10']]);

        $this->service->sync($player, [[
            'branch_id' => $this->foot->id, 'join_date' => '2022-09-15',
            'position_id' => $gk->id, 'other_position_ids' => [$df->id, $gk->id],
        ], ['branch_id' => $this->swim->id]]);

        $this->assertEqualsCanonicalizing([$df->id, $crawl->id], $player->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function bulk_fields_go_to_the_right_registration(): void
    {
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $category = Category::create(['name' => 'Senior']);
        $player = $this->player();
        $this->service->sync($player, [
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15'],
            ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10'],
        ]);

        $this->service->setField($player, 'position_id', $crawl->id);
        $this->service->setField($player, 'category_id', $category->id);
        $this->service->setField($player, 'status_id', $this->left()->id);

        $rows = $player->registrations()->get()->keyBy('branch_id');
        $this->assertSame($crawl->id, $rows[$this->swim->id]->position_id);
        $this->assertNull($rows[$this->foot->id]->position_id);
        $this->assertSame($category->id, $rows[$this->foot->id]->category_id);
        $this->assertSame($this->left()->id, $rows[$this->swim->id]->status_id);
        $this->assertNotNull($player->fresh()->left_at);
    }

    #[Test]
    public function a_first_registration_adopts_the_players_id_and_year(): void
    {
        $player = $this->player();

        $this->service->register($player, ['branch_id' => $this->foot->id]);

        $row = BranchRegistration::firstOrFail();
        $this->assertSame('202200017', $row->membership_id);
        $this->assertSame(2022, $row->legacy_join_year);
        $this->assertNull($row->join_date);
    }
}
