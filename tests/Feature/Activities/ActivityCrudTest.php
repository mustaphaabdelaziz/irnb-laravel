<?php

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\ActivityType;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(string $first = 'Sami'): Player
    {
        static $n = 0;
        $n++;

        return Player::create(['membership_id' => "A{$n}", 'firstname' => $first, 'lastname' => 'Zidane']);
    }

    private function member(): BoardMember
    {
        $term = BoardTerm::firstOrCreate(['name' => '2024–2028'], ['is_current' => true]);

        return BoardMember::create(['name' => 'Karim', 'role' => 'member', 'board_term_id' => $term->id, 'status' => 'active']);
    }

    private function activity(array $attributes = []): Activity
    {
        return Activity::create($attributes + [
            'title' => 'Blood drive', 'activity_type_id' => ActivityType::first()->id, 'starts_on' => '2026-02-14',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Summer camp 2026',
            'activity_type_id' => ActivityType::where('name', 'مخيم صيفي')->value('id'),
            'starts_on' => '2026-07-05',
            'ends_on' => '2026-07-15',
            'location' => 'Tikjda',
            'partners' => 'Youth directorate',
            'beneficiaries_count' => 40,
            'external_volunteers_count' => 3,
            'cost' => '120000.50',
            'description' => 'Ten days in the mountains.',
            'report' => 'All went well.',
        ];
    }

    #[Test]
    public function an_activity_is_created_with_its_participants_and_categories(): void
    {
        $category = Category::create(['name' => 'U15']);
        $branch = Branch::create(['name' => 'Football']);
        $player = $this->player();
        $member = $this->member();

        $response = $this->actingAs($this->admin())->post(route('activities.store'), $this->payload([
            'branch_id' => $branch->id,
            'responsible_board_member_id' => $member->id,
            'category_ids' => [$category->id],
            'player_ids' => [$player->id],
            'board_member_ids' => [$member->id],
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.activity_created');

        $activity = Activity::sole();
        $response->assertRedirect(route('activities.show', $activity));
        $this->assertNotNull($activity->created_by_user_id);
        $this->assertSame('2026-07-15', $activity->ends_on->format('Y-m-d'));
        $this->assertSame([$category->id], $activity->categories->pluck('id')->all());
        $this->assertSame([$player->id], $activity->players->pluck('id')->all());
        $this->assertSame([$member->id], $activity->boardMembers->pluck('id')->all());
        $this->assertSame(1 + 1 + 3, $activity->participants_count);
        $this->assertSame(1, ActivityLog::where('action', ActivityAction::ACTIVITY_CREATED)->count());
    }

    #[Test]
    public function validation_rules(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('activities.store'), $this->payload(['title' => '']))->assertSessionHasErrors('title');
        $this->post(route('activities.store'), $this->payload(['activity_type_id' => null]))->assertSessionHasErrors('activity_type_id');
        $this->post(route('activities.store'), $this->payload(['ends_on' => '2026-07-01']))->assertSessionHasErrors('ends_on');
        $this->post(route('activities.store'), $this->payload(['beneficiaries_count' => -1]))->assertSessionHasErrors('beneficiaries_count');
        $this->assertSame(0, Activity::count());
    }

    #[Test]
    public function an_activity_is_updated_and_its_lists_resynced(): void
    {
        $old = $this->player('Old');
        $new = $this->player('New');
        $activity = $this->activity();
        $activity->players()->attach($old);

        $this->actingAs($this->admin())->put(route('activities.update', $activity), $this->payload([
            'title' => 'Renamed', 'player_ids' => [$new->id],
        ]))->assertSessionHas('success', 'flash.activity_updated');

        $activity->refresh();
        $this->assertSame('Renamed', $activity->title);
        $this->assertSame([$new->id], $activity->players->pluck('id')->all());
        $this->assertSame(1, ActivityLog::where('action', ActivityAction::ACTIVITY_UPDATED)->count());
    }

    #[Test]
    public function an_activity_is_deleted(): void
    {
        $activity = $this->activity();

        $this->actingAs($this->admin())->delete(route('activities.destroy', $activity))
            ->assertRedirect(route('activities.index'))
            ->assertSessionHas('success', 'flash.activity_deleted');

        $this->assertModelMissing($activity);
        $this->assertSame(1, ActivityLog::where('action', ActivityAction::ACTIVITY_DELETED)->count());
    }

    #[Test]
    public function the_index_filters_and_totals_the_filtered_set(): void
    {
        $camp = ActivityType::where('name', 'مخيم صيفي')->value('id');
        $a = $this->activity(['title' => 'Camp Tikjda', 'activity_type_id' => $camp, 'starts_on' => '2026-07-05', 'beneficiaries_count' => 40, 'external_volunteers_count' => 2]);
        $a->players()->attach($this->player());
        $this->activity(['title' => 'Blood drive', 'starts_on' => '2026-02-14', 'beneficiaries_count' => 100]);
        $this->activity(['title' => 'Old camp', 'activity_type_id' => $camp, 'starts_on' => '2025-07-05', 'beneficiaries_count' => 30]);

        $this->actingAs($this->admin())
            ->get(route('activities.index', ['types' => [$camp], 'from' => '2026-01-01', 'to' => '2026-12-31']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Activities/Index')
                ->has('activities.data', 1)
                ->where('activities.data.0.title', 'Camp Tikjda')
                ->where('activities.data.0.participants_count', 3)
                ->where('stats.count', 1)
                ->where('stats.beneficiaries', 40)
                ->where('stats.participants', 3));

        $this->get(route('activities.index', ['search' => 'blood']))
            ->assertInertia(fn (Assert $page) => $page->has('activities.data', 1)->where('stats.beneficiaries', 100));
    }

    #[Test]
    public function the_show_page_carries_participants_by_full_name(): void
    {
        $activity = $this->activity();
        $activity->players()->attach($this->player('Amine'));

        $this->actingAs($this->admin())->get(route('activities.show', $activity))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Activities/Show')
                ->where('activity.title', 'Blood drive')
                ->where('activity.players.0.name', Player::first()->fullname)
                ->where('reportLimit', 4));
    }

    #[Test]
    public function the_create_and_edit_forms_render(): void
    {
        $this->player();
        $activity = $this->activity();

        $this->actingAs($this->admin())->get(route('activities.create'))
            ->assertInertia(fn (Assert $page) => $page->component('Activities/Form')->where('activity', null)->has('types', 6)->has('players', 1));

        $this->get(route('activities.edit', $activity))
            ->assertInertia(fn (Assert $page) => $page->component('Activities/Form')->where('activity.id', $activity->id)->where('activity.player_ids', []));
    }

    #[Test]
    public function the_edit_form_still_offers_former_board_members_linked_to_the_activity(): void
    {
        $active = $this->member();
        $participant = $this->member();
        $responsible = $this->member();
        $unrelated = $this->member();
        BoardMember::whereKey([$participant->id, $responsible->id, $unrelated->id])->update(['status' => 'former']);
        $activity = $this->activity(['responsible_board_member_id' => $responsible->id]);
        $activity->boardMembers()->attach($participant);

        $offers = fn (array $ids) => fn (Collection $options) => $options->pluck('value')->sort()->values()->all() === $ids;

        $this->actingAs($this->admin())->get(route('activities.edit', $activity))
            ->assertInertia(fn (Assert $page) => $page->where('boardMembers', $offers([$active->id, $participant->id, $responsible->id])));

        $this->get(route('activities.create'))
            ->assertInertia(fn (Assert $page) => $page->where('boardMembers', $offers([$active->id])));
    }

    #[Test]
    public function the_index_shows_the_first_photo_as_cover_without_the_gallery(): void
    {
        $activity = $this->activity();
        $activity->photos()->create(['path' => 'activities/x/b.jpg', 'thumb_path' => 'activities/x/thumbs/b.jpg', 'sort_order' => 2]);
        $activity->photos()->create(['path' => 'activities/x/a.jpg', 'thumb_path' => 'activities/x/thumbs/a.jpg', 'sort_order' => 1]);
        $activity->photos()->create(['path' => 'activities/x/c.jpg', 'thumb_path' => 'activities/x/thumbs/c.jpg', 'sort_order' => 1]);
        $bare = $this->activity(['title' => 'No photos', 'starts_on' => '2026-01-01']);

        $this->actingAs($this->admin())->get(route('activities.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activities.data.0.id', $activity->id)
                ->where('activities.data.0.cover', ['thumb_url' => '/media/activities/x/thumbs/a.jpg'])
                ->missing('activities.data.0.photos')
                ->where('activities.data.1.id', $bare->id)
                ->where('activities.data.1.cover', null));
    }

    #[Test]
    public function array_valued_index_filters_are_ignored_not_fatal(): void
    {
        $this->activity();
        $this->actingAs($this->admin());

        foreach (['types[0][]=1', 'branch[]=1', 'search[]=x', 'from[]=2026-01-01', 'to[]=2026-12-31'] as $query) {
            $this->get(route('activities.index').'?'.$query)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('activities.data', 1)->where('stats.count', 1));
        }
    }

    #[Test]
    public function the_participant_count_uses_loaded_relations_without_querying(): void
    {
        $activity = $this->activity(['external_volunteers_count' => 2]);
        $activity->players()->attach($this->player());
        $activity->boardMembers()->attach($this->member());
        $activity = Activity::with(['players', 'boardMembers'])->find($activity->id);

        DB::enableQueryLog();
        $count = $activity->participants_count;

        $this->assertSame(4, $count);
        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function each_action_needs_its_right(): void
    {
        $activity = $this->activity();
        $viewer = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['activities' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)->get(route('activities.index'))->assertOk();
        $this->actingAs($viewer)->get(route('activities.show', $activity))->assertOk();
        $this->actingAs($viewer)->get(route('activities.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('activities.store'), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->put(route('activities.update', $activity), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('activities.destroy', $activity))->assertForbidden();

        $nobody = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => []])->id]);
        $this->actingAs($nobody)->get(route('activities.index'))->assertForbidden();
    }
}
