<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Attendance\BranchChoice;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationWritersTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $this->foot->id]);
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    private function member(string $id = '201700001'): Player
    {
        $player = Player::create(['firstname' => 'A', 'membership_id' => $id, 'join_year' => 2017]);
        $player->branches()->attach($this->foot->id);

        return $player;
    }

    #[Test]
    public function bulk_attach_needs_a_date_and_uses_it(): void
    {
        $player = $this->member();
        $payload = ['ids' => [$player->id], 'field' => 'branches', 'mode' => 'attach', 'value' => [$this->swim->id]];

        $this->post(route('players.bulkUpdate'), $payload)->assertSessionHasErrors('join_date');

        $this->post(route('players.bulkUpdate'), [...$payload, 'join_date' => '2025-03-10'])->assertSessionHasNoErrors();

        $row = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();
        $this->assertSame('2025-03-10', $row->join_date->toDateString());
        $this->assertSame('SW-2025-00001', $row->membership_id);
    }

    #[Test]
    public function bulk_detach_and_replace_mark_left_instead_of_deleting(): void
    {
        $player = $this->member();
        $left = PlayerStatus::where('code', 'left')->value('id');

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'branches', 'mode' => 'replace',
            'value' => [$this->swim->id], 'join_date' => '2025-03-10',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $player->registrations()->count());
        $this->assertSame($left, $player->registrations()->where('branch_id', $this->foot->id)->value('status_id'));

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'branches', 'mode' => 'detach', 'value' => [$this->swim->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $player->registrations()->count());
        $this->assertSame($left, $player->fresh()->status_id);
    }

    #[Test]
    public function bulk_category_reaches_the_registration_and_the_copy(): void
    {
        $category = Category::create(['name' => 'Senior']);
        $player = $this->member();

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'category_id', 'value' => $category->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($category->id, $player->registrations()->firstOrFail()->category_id);
        $this->assertSame($category->id, $player->fresh()->category_id);
    }

    #[Test]
    public function bulk_status_changes_only_the_chosen_branch(): void
    {
        $player = $this->member('201700010');
        $player->branches()->attach($this->swim->id);
        app(RegistrationService::class)->leave($player, [$this->swim->id], '2024-01-01');
        $paused = PlayerStatus::where('code', 'paused')->value('id');

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'status_id', 'value' => $paused, 'branch_id' => $this->foot->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($paused, $player->registrations()->where('branch_id', $this->foot->id)->value('status_id'));
        $swim = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();
        $this->assertSame(PlayerStatus::where('code', 'left')->value('id'), $swim->status_id);
        $this->assertSame('2024-01-01', $swim->left_at->toDateString());
    }

    #[Test]
    public function bulk_status_needs_a_branch(): void
    {
        $player = $this->member('201700011');

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'status_id', 'value' => PlayerStatus::where('code', 'paused')->value('id'),
        ])->assertSessionHasErrors('branch_id');
    }

    #[Test]
    public function bulk_status_in_a_club_with_no_branches_needs_none(): void
    {
        WebsiteConfig::singleton()->update(['main_branch_id' => null]);
        Branch::query()->delete();
        $player = Player::create(['firstname' => 'Solo', 'membership_id' => '201700012', 'join_year' => 2017]);
        $paused = PlayerStatus::where('code', 'paused')->value('id');

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'status_id', 'value' => $paused,
        ])->assertSessionHasNoErrors();

        $this->assertSame($paused, $player->fresh()->status_id);
    }

    #[Test]
    public function bulk_attach_reopens_a_left_registration_and_keeps_its_date(): void
    {
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700013', 'join_year' => 2017]);
        $registrations = app(RegistrationService::class);
        $original = $registrations->register($player, ['branch_id' => $this->foot->id, 'join_date' => '2020-05-05']);
        $registrations->leave($player, [$this->foot->id]);

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'branches', 'mode' => 'attach',
            'value' => [$this->foot->id], 'join_date' => '2025-03-10',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $player->registrations()->count());
        $row = $player->registrations()->firstOrFail();
        $this->assertSame($original->id, $row->id);
        $this->assertFalse(Player::isLeftStatus($row->status_id));
        $this->assertSame('2020-05-05', $row->join_date->toDateString());
    }

    #[Test]
    public function branch_members_keep_their_data_when_removed_and_new_ones_need_a_date(): void
    {
        $stays = $this->member('201700002');
        $newcomer = Player::create(['firstname' => 'B', 'membership_id' => '201700003', 'join_year' => 2017]);

        $this->post(route('branches.players.sync', $this->foot), ['player_ids' => [$stays->id, $newcomer->id]])
            ->assertSessionHasErrors('join_date');

        $this->post(route('branches.players.sync', $this->foot), ['player_ids' => [$stays->id, $newcomer->id], 'join_date' => '2026-01-20'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-01-20', $newcomer->registrations()->firstOrFail()->join_date->toDateString());

        $this->post(route('branches.players.sync', $this->foot), ['player_ids' => [$newcomer->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(PlayerStatus::where('code', 'left')->value('id'), $stays->registrations()->firstOrFail()->status_id);
    }

    #[Test]
    public function the_branch_page_reopens_a_returning_member_without_a_date(): void
    {
        $player = $this->member('201700014');
        app(RegistrationService::class)->leave($player, [$this->foot->id]);

        $this->post(route('branches.players.sync', $this->foot), ['player_ids' => [$player->id]])
            ->assertSessionHasNoErrors();

        $row = $player->registrations()->firstOrFail();
        $this->assertFalse(Player::isLeftStatus($row->status_id));
        $this->assertNull($row->left_at);
    }

    #[Test]
    public function the_branch_page_lists_active_members_only(): void
    {
        $active = $this->member('201700015');
        $gone = $this->member('201700016');
        app(RegistrationService::class)->leave($gone, [$this->foot->id]);

        $this->get(route('branches.index'))->assertInertia(fn ($page) => $page
            ->where('branches', function ($branches) use ($active) {
                $foot = collect($branches)->firstWhere('id', $this->foot->id);

                return $foot['players_count'] === 1
                    && collect($foot['players'])->pluck('id')->all() === [$active->id];
            })
            ->where('mainBranchId', $this->foot->id));
    }

    #[Test]
    public function a_category_or_status_in_use_by_a_registration_cannot_be_deleted(): void
    {
        $category = Category::create(['name' => 'Senior']);
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700004', 'join_year' => 2017]);
        $player->registrations()->create(['branch_id' => $this->foot->id, 'category_id' => $category->id, 'status_id' => null]);

        $this->delete(route('categories.destroy', $category))->assertSessionHas('error', 'flash.category_has_registrations');
        $this->assertNotNull($category->fresh());

        // Only a registration holds this status: no player row does.
        $status = PlayerStatus::create(['name' => 'Trial', 'is_active' => true]);
        $player->registrations()->firstOrFail()->update(['status_id' => $status->id]);
        $this->assertFalse($status->players()->exists());

        $this->delete(route('player-statuses.destroy', $status))->assertSessionHas('error', 'flash.player_status_in_use');
        $this->assertNotNull($status->fresh());
    }

    #[Test]
    public function the_main_branch_opens_first_and_can_be_reassigned(): void
    {
        $this->post(route('branches.main', $this->swim))->assertSessionHasNoErrors();
        $this->assertSame($this->swim->id, Branch::mainId());

        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $this->assertSame($this->swim->id, BranchChoice::resolve($request, null));
    }

    #[Test]
    public function a_mistaken_registration_can_be_deleted_outright(): void
    {
        $player = $this->member('201700005');
        $player->branches()->attach($this->swim->id);
        $mistake = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();

        $this->delete(route('players.registrations.destroy', [$player, $mistake]))->assertSessionHas('success');

        $this->assertSame([$this->foot->id], $player->registrations()->pluck('branch_id')->all());
    }

    #[Test]
    public function a_registration_with_obligations_or_the_last_one_stays(): void
    {
        $player = $this->member('201700006');
        $only = $player->registrations()->firstOrFail();

        $this->delete(route('players.registrations.destroy', [$player, $only]))->assertSessionHas('error', 'flash.registration_last');

        $player->branches()->attach($this->swim->id);
        $swim = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();
        $subscription = Subscription::create(['name' => 'Swim fee', 'year' => 2026, 'amount_student' => 100, 'amount_worker' => 100]);
        $subscription->branches()->attach($this->swim->id);
        $player->playerSubscriptions()->create(['subscription_id' => $subscription->id, 'year' => 2026, 'amount_owed' => 100, 'amount_paid' => 0]);

        $this->delete(route('players.registrations.destroy', [$player, $swim]))->assertSessionHas('error', 'flash.registration_has_obligations');
        $this->assertSame(2, $player->registrations()->count());
    }

    #[Test]
    public function a_registration_of_another_player_is_not_found(): void
    {
        $player = $this->member('201700007');
        $other = $this->member('201700008');
        $registration = $other->registrations()->firstOrFail();

        $this->delete(route('players.registrations.destroy', [$player, $registration]))->assertNotFound();
    }

    #[Test]
    public function no_production_code_writes_registrations_through_the_bare_pivot(): void
    {
        $pattern = '/\$(player|p)->branches\(\)->(sync|attach|detach|syncWithoutDetaching|toggle)\b|\$branch->players\(\)->(sync|attach|detach|syncWithoutDetaching|toggle)\b/';

        $offenders = collect(File::allFiles(app_path()))
            ->filter(fn ($file) => preg_match($pattern, $file->getContents()) === 1)
            ->map(fn ($file) => $file->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $offenders);
    }

    #[Test]
    public function changing_the_main_branch_refreshes_players_of_the_old_main_too(): void
    {
        $tennis = Branch::create(['name' => 'Tennis', 'code' => 'TN']);
        $senior = Category::create(['name' => 'Senior']);
        $junior = Category::create(['name' => 'Junior']);
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700099', 'join_year' => 2017]);
        $player->registrations()->create(['branch_id' => $this->foot->id, 'category_id' => $senior->id, 'join_date' => '2020-01-01', 'membership_id' => 'FB-2020-00001']);
        $player->registrations()->create(['branch_id' => $tennis->id, 'category_id' => $junior->id, 'join_date' => '2019-01-01', 'membership_id' => 'TN-2019-00001']);
        app(RegistrationService::class)->refreshMirror($player);
        $this->assertSame('FB-2020-00001', $player->fresh()->membership_id);

        $this->post(route('branches.main', $this->swim))->assertSessionHasNoErrors();

        $fresh = $player->fresh();
        $this->assertSame('TN-2019-00001', $fresh->membership_id);
        $this->assertSame($junior->id, $fresh->category_id);
    }

    #[Test]
    public function bulk_attach_and_replace_give_the_old_id_to_the_main_branch(): void
    {
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700001', 'join_year' => 2017]);

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'branches', 'mode' => 'attach',
            'value' => [$this->swim->id, $this->foot->id], 'join_date' => '2025-03-10',
        ])->assertSessionHasNoErrors();

        $this->assertSame('201700001', $player->registrations()->where('branch_id', $this->foot->id)->value('membership_id'));
        $this->assertSame('SW-2025-00001', $player->registrations()->where('branch_id', $this->swim->id)->value('membership_id'));
        $this->assertSame('201700001', $player->fresh()->membership_id);

        $other = Player::create(['firstname' => 'B', 'membership_id' => '201700002', 'join_year' => 2017]);
        $this->post(route('players.bulkUpdate'), [
            'ids' => [$other->id], 'field' => 'branches', 'mode' => 'replace',
            'value' => [$this->swim->id, $this->foot->id], 'join_date' => '2025-03-10',
        ])->assertSessionHasNoErrors();

        $this->assertSame('201700002', $other->registrations()->where('branch_id', $this->foot->id)->value('membership_id'));
        $this->assertSame('201700002', $other->fresh()->membership_id);
    }

    #[Test]
    public function deleting_a_registration_keeps_its_legacy_id_findable(): void
    {
        $service = app(RegistrationService::class);
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700001', 'join_year' => 2017]);
        $swim = $service->register($player, ['branch_id' => $this->swim->id]);
        $this->assertSame('201700001', $swim->membership_id);
        $service->register($player, ['branch_id' => $this->foot->id, 'join_date' => '2025-03-10']);
        $this->assertSame('FB-2025-00001', $player->registrations()->where('branch_id', $this->foot->id)->value('membership_id'));

        $this->delete(route('players.registrations.destroy', [$player, $swim]))->assertSessionHas('success');

        $foot = $player->registrations()->firstOrFail();
        $this->assertSame('FB-2025-00001', $foot->membership_id);
        $this->assertSame('201700001', $foot->legacy_membership_id);
    }

    #[Test]
    public function deleting_a_registration_hands_its_id_to_a_primary_that_has_none(): void
    {
        $service = app(RegistrationService::class);
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700001']);
        $swim = $service->register($player, ['branch_id' => $this->swim->id]);
        $foot = $service->register($player, ['branch_id' => $this->foot->id]);
        $this->assertNull($foot->membership_id);

        $this->delete(route('players.registrations.destroy', [$player, $swim]))->assertSessionHas('success');

        $this->assertSame('201700001', $foot->fresh()->membership_id);
        $this->assertSame('201700001', $player->fresh()->membership_id);
    }

    #[Test]
    public function the_left_status_cannot_be_deleted(): void
    {
        $left = PlayerStatus::where('code', 'left')->firstOrFail();

        $this->delete(route('player-statuses.destroy', $left))->assertSessionHas('error', 'flash.player_status_required');
        $this->assertNotNull($left->fresh());
    }

    #[Test]
    public function a_branch_holding_registrations_cannot_be_deleted(): void
    {
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700020', 'join_year' => 2017]);
        $player->registrations()->create(['branch_id' => $this->swim->id]);
        app(RegistrationService::class)->leave($player, [$this->swim->id]);

        $this->delete(route('branches.destroy', $this->swim))->assertSessionHas('error', 'flash.branch_has_registrations');

        $this->assertNotNull($this->swim->fresh());
        $this->assertSame(1, $player->registrations()->count());
    }

    #[Test]
    public function bulk_status_to_left_stamps_the_date_and_back_clears_it(): void
    {
        $player = $this->member('201700030');
        $left = PlayerStatus::where('code', 'left')->value('id');
        $registered = PlayerStatus::where('code', 'registered')->value('id');
        $payload = ['ids' => [$player->id], 'field' => 'status_id', 'branch_id' => $this->foot->id];

        $this->post(route('players.bulkUpdate'), [...$payload, 'value' => $left])->assertSessionHasNoErrors();
        $row = $player->registrations()->firstOrFail();
        $this->assertSame($left, $row->status_id);
        $this->assertNotNull($row->left_at);
        $this->assertNotNull($player->fresh()->left_at);

        $this->post(route('players.bulkUpdate'), [...$payload, 'value' => $registered])->assertSessionHasNoErrors();
        $row = $player->registrations()->firstOrFail();
        $this->assertSame($registered, $row->status_id);
        $this->assertNull($row->left_at);
        $this->assertNull($player->fresh()->left_at);
    }
}
