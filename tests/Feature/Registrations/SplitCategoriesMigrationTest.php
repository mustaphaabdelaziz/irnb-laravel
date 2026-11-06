<?php

namespace Tests\Feature\Registrations;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\Subscription;
use App\Services\Player\RegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class SplitCategoriesMigrationTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private int $memberCount = 0;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_06_100001_split_categories_by_branch.php');
    }

    private function memberId(): string
    {
        return 'SP-'.str_pad((string) ++$this->memberCount, 5, '0', STR_PAD_LEFT);
    }

    private function football(): Branch
    {
        return Branch::create(['name' => 'Football', 'code' => 'FB']);
    }

    private function swimming(): Branch
    {
        return Branch::create(['name' => 'Swimming', 'code' => 'SW']);
    }

    /** A shared category as it stands before the split. Branches created first, so nothing adopts it. */
    private function shared(string $name = 'Senior', array $extra = []): Category
    {
        return Category::create(['name' => $name, ...$extra]);
    }

    private function member(array $cards, array $player = []): Player
    {
        $model = Player::create(['membership_id' => $this->memberId(), 'firstname' => 'A', 'lastname' => 'B', 'join_year' => 2020, ...$player]);
        foreach ($cards as $card) {
            app(RegistrationService::class)->register($model, $card);
        }

        return $model->refresh();
    }

    private function copyOf(Category $category, Branch $branch): ?object
    {
        return DB::table('categories')->where('name', $category->name)->where('branch_id', $branch->id)->first();
    }

    #[Test]
    public function the_main_branch_keeps_the_row_and_another_branch_gets_a_full_copy(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior', ['name_ar' => 'أكابر', 'name_fr' => 'Séniors', 'name_en' => 'Seniors', 'description' => 'Grown ups']);
        $player = $this->member([
            ['branch_id' => $foot->id, 'category_id' => $senior->id],
            ['branch_id' => $swim->id, 'category_id' => $senior->id],
        ]);

        $this->migration()->up();

        $this->assertSame($foot->id, (int) DB::table('categories')->where('id', $senior->id)->value('branch_id'));
        $copy = $this->copyOf($senior, $swim);
        $this->assertNotEquals($senior->id, $copy->id);
        $this->assertSame('أكابر', $copy->name_ar);
        $this->assertSame('Séniors', $copy->name_fr);
        $this->assertSame('Seniors', $copy->name_en);
        $this->assertSame('Grown ups', $copy->description);
        $this->assertEquals($senior->id, DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $foot->id)->value('category_id'));
        $this->assertEquals($copy->id, DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $swim->id)->value('category_id'));
        $this->assertEquals($senior->id, DB::table('players')->where('id', $player->id)->value('category_id'));
    }

    #[Test]
    public function another_branch_gets_only_what_it_uses(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $this->shared('Ecole');
        $this->member([['branch_id' => $swim->id, 'category_id' => $senior->id]]);

        $this->migration()->up();

        $this->assertSame(1, DB::table('categories')->where('branch_id', $swim->id)->count());
        $this->assertSame(2, DB::table('categories')->where('branch_id', $foot->id)->count());
    }

    #[Test]
    public function every_row_of_that_branch_moves_to_the_copy_and_nothing_of_the_main_branch_does(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $rows = $this->rowsOn($senior, [$foot, $swim]);

        $this->migration()->up();

        $copy = $this->copyOf($senior, $swim);
        foreach (['training_sessions', 'training_schedules', 'deleted_session_slots', 'preseason_targets', 'teams', 'finance_accounts'] as $table) {
            $this->assertEquals($senior->id, DB::table($table)->where('branch_id', $foot->id)->whereNotNull('category_id')->value('category_id'), $table.' on football');
            $this->assertEquals($copy->id, DB::table($table)->where('branch_id', $swim->id)->whereNotNull('category_id')->value('category_id'), $table.' on swimming');
        }
        $this->assertEquals([$senior->id], DB::table('training_session_category')->where('training_session_id', $rows['football_session'])->pluck('category_id')->all());
        $this->assertEquals([$copy->id], DB::table('training_session_category')->where('training_session_id', $rows['swimming_session'])->pluck('category_id')->all());
        $this->assertEquals($senior->id, DB::table('attendances')->where('training_session_id', $rows['football_session'])->value('category_id'));
        $this->assertEquals($copy->id, DB::table('attendances')->where('training_session_id', $rows['swimming_session'])->value('category_id'));
        $this->assertEquals([$copy->id], DB::table('activity_category')->where('activity_id', $rows['swimming_activity'])->pluck('category_id')->all());
        $this->assertEquals([$senior->id], DB::table('activity_category')->where('activity_id', $rows['club_activity'])->pluck('category_id')->all());
    }

    #[Test]
    public function a_mark_alone_causes_no_copy_and_stays_on_its_category(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $ecole = $this->shared('Ecole');
        $senior = $this->shared('Senior');
        $session = $this->heldSession($swim, $senior);
        $player = $this->member([['branch_id' => $foot->id, 'category_id' => $ecole->id]]);
        DB::table('attendances')->insert(['training_session_id' => $session, 'player_id' => $player->id, 'category_id' => $ecole->id, 'status' => 'present']);

        $this->migration()->up();

        $this->assertNull($this->copyOf($ecole, $swim));
        $this->assertEquals($ecole->id, DB::table('attendances')->where('training_session_id', $session)->value('category_id'));
    }

    #[Test]
    public function a_price_on_a_subscription_ticked_only_for_that_branch_brings_its_category(): void
    {
        [, $swim] = [$this->football(), $this->swimming()];
        $cadets = $this->shared('Cadets');
        $piscine = $this->priced('Piscine 2026', $cadets, [$swim]);

        $this->migration()->up();

        $copy = $this->copyOf($cadets, $swim);
        $this->assertNotNull($copy);
        $row = DB::table('category_subscription')->where('subscription_id', $piscine->id)->sole();
        $this->assertEquals($copy->id, $row->category_id);
        $this->assertEquals(1500, $row->amount_student);
        $this->assertEquals(2500, $row->amount_worker);
    }

    #[Test]
    public function a_price_on_a_shared_or_unticked_subscription_alone_causes_no_copy(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $cadets = $this->shared('Cadets');
        $this->priced('Both', $cadets, [$foot, $swim]);
        $this->priced('Everyone', $cadets, []);

        $this->migration()->up();

        $this->assertNull($this->copyOf($cadets, $swim));
        $this->assertSame(2, DB::table('category_subscription')->where('category_id', $cadets->id)->count());
    }

    #[Test]
    public function the_branch_link_is_a_real_foreign_key_and_its_children_survive(): void
    {
        $foot = $this->football();
        $senior = $this->shared('Senior');
        $this->member([['branch_id' => $foot->id, 'category_id' => $senior->id]]);
        $registrations = DB::table('branch_player')->count();

        $this->migration()->up();

        $this->assertSame($registrations, DB::table('branch_player')->count(), 'adding the key must not cascade');
        $this->assertTrue(collect(\Illuminate\Support\Facades\Schema::getForeignKeys('categories'))->contains(fn (array $key) => $key['columns'] === ['branch_id']));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('categories')->where('id', $senior->id)->update(['branch_id' => 999999]);
    }

    #[Test]
    public function generation_marks_follow_a_repointed_schedule_so_no_month_generates_twice(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $ecole = $this->shared('Ecole');
        DB::table('training_schedules')->insert([
            'branch_id' => $swim->id, 'category_id' => $senior->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'valid_from' => '2026-01-01',
        ]);
        $this->member([['branch_id' => $swim->id, 'category_id' => $ecole->id]]);
        DB::table('session_generation_marks')->insert([
            ['category_id' => $senior->id, 'month' => '2026-03', 'created_at' => now()],
            ['category_id' => $ecole->id, 'month' => '2026-03', 'created_at' => now()],
        ]);

        $this->migration()->up();

        $this->assertTrue(DB::table('session_generation_marks')->where('category_id', $this->copyOf($senior, $swim)->id)->where('month', '2026-03')->exists());
        $this->assertTrue(DB::table('session_generation_marks')->where('category_id', $senior->id)->where('month', '2026-03')->exists(), 'football keeps its mark');
        $this->assertFalse(DB::table('session_generation_marks')->where('category_id', $this->copyOf($ecole, $swim)->id)->exists(), 'no schedule, no mark');
    }

    #[Test]
    public function prices_follow_the_branches_a_subscription_is_ticked_for(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $this->member([['branch_id' => $swim->id, 'category_id' => $senior->id]]);

        $only = $this->priced('Only swimming', $senior, [$swim]);
        $both = $this->priced('Both', $senior, [$foot, $swim]);
        $none = $this->priced('None', $senior, []);
        $other = $this->priced('Only football', $senior, [$foot]);

        $this->migration()->up();

        $copy = $this->copyOf($senior, $swim);
        $price = fn (Subscription $s, int $category) => DB::table('category_subscription')->where('subscription_id', $s->id)->where('category_id', $category)->first();

        $this->assertNull($price($only, $senior->id), 'moved');
        $this->assertEquals(1500, $price($only, $copy->id)->amount_student);
        $this->assertEquals(2500, $price($only, $copy->id)->amount_worker);
        foreach ([$both, $none] as $subscription) {
            $this->assertEquals(1500, $price($subscription, $senior->id)->amount_student);
            $this->assertEquals(1500, $price($subscription, $copy->id)->amount_student);
            $this->assertEquals(2500, $price($subscription, $copy->id)->amount_worker);
        }
        $this->assertNotNull($price($other, $senior->id));
        $this->assertNull($price($other, $copy->id), 'swimming is never charged this one');
    }

    #[Test]
    public function the_mirror_follows_a_player_whose_main_registration_moved(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $swimmer = $this->member([['branch_id' => $swim->id, 'category_id' => $senior->id]]);
        $leftFootball = $this->member([
            ['branch_id' => $foot->id, 'category_id' => $senior->id],
            ['branch_id' => $swim->id, 'category_id' => $senior->id],
        ]);
        app(RegistrationService::class)->leave($leftFootball, [$foot->id], '2026-03-15');

        $this->migration()->up();

        $copy = $this->copyOf($senior, $swim);
        $this->assertEquals($copy->id, DB::table('players')->where('id', $swimmer->id)->value('category_id'));
        $this->assertEquals($copy->id, DB::table('players')->where('id', $leftFootball->id)->value('category_id'));
        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function a_player_with_no_registration_keeps_their_category(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $this->member([['branch_id' => $swim->id, 'category_id' => $senior->id]]);
        $flat = Player::create(['membership_id' => $this->memberId(), 'firstname' => 'Flat', 'category_id' => $senior->id]);

        $this->migration()->up();

        $this->assertEquals($senior->id, DB::table('players')->where('id', $flat->id)->value('category_id'));
        $this->assertSame($foot->id, (int) DB::table('categories')->where('id', $senior->id)->value('branch_id'));
    }

    #[Test]
    public function one_branch_owns_everything_and_copies_nothing(): void
    {
        $foot = $this->football();
        $senior = $this->shared('Senior');
        $this->shared('Ecole');
        $player = $this->member([['branch_id' => $foot->id, 'category_id' => $senior->id]]);

        $this->migration()->up();

        $this->assertSame(2, DB::table('categories')->where('branch_id', $foot->id)->count());
        $this->assertSame(2, DB::table('categories')->count());
        $this->assertEquals($senior->id, DB::table('players')->where('id', $player->id)->value('category_id'));
    }

    #[Test]
    public function no_branches_leaves_every_category_with_no_owner(): void
    {
        $this->shared('Senior');

        $this->migration()->up();

        $this->assertSame(1, DB::table('categories')->whereNull('branch_id')->count());
    }

    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $this->rowsOn($senior, [$foot, $swim]);
        $this->priced('Both', $senior, [$foot, $swim]);
        DB::table('session_generation_marks')->insert(['category_id' => $senior->id, 'month' => '2026-03', 'created_at' => '2026-03-01 00:00:00']);

        $this->migration()->up();
        $first = $this->snapshot();
        $this->migration()->up();

        $this->assertEquals($first, $this->snapshot());
    }

    #[Test]
    public function two_branches_may_share_a_name_and_one_branch_may_not(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        Category::create(['name' => 'Senior', 'branch_id' => $foot->id]);
        Category::create(['name' => 'Senior', 'branch_id' => $swim->id]);

        $this->expectException(QueryException::class);
        Category::create(['name' => 'Senior', 'branch_id' => $foot->id]);
    }

    #[Test]
    public function down_merges_copies_back_by_name_onto_the_main_row(): void
    {
        [$foot, $swim] = [$this->football(), $this->swimming()];
        $senior = $this->shared('Senior');
        $player = $this->member([
            ['branch_id' => $foot->id, 'category_id' => $senior->id],
            ['branch_id' => $swim->id, 'category_id' => $senior->id],
        ]);
        $rows = $this->rowsOn($senior, [$foot, $swim]);
        $both = $this->priced('Both', $senior, [$foot, $swim]);
        $only = $this->priced('Only swimming', $senior, [$swim]);
        DB::table('session_generation_marks')->insert(['category_id' => $senior->id, 'month' => '2026-03', 'created_at' => now()]);
        $this->migration()->up();
        // Added after the split, so no main-branch row holds its name.
        $new = Category::create(['name' => 'Poussin', 'branch_id' => $swim->id]);

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('categories', 'branch_id'));
        $this->assertFalse(Schema::hasColumn('categories', 'is_active'));
        $this->assertEqualsCanonicalizing(['Senior', 'Poussin'], DB::table('categories')->pluck('name')->all());
        $this->assertTrue(DB::table('categories')->where('id', $new->id)->exists(), 'a name nobody else holds survives');
        $this->assertEquals([$senior->id], DB::table('branch_player')->where('player_id', $player->id)->distinct()->pluck('category_id')->all());
        foreach (['training_sessions', 'training_schedules', 'deleted_session_slots', 'preseason_targets', 'teams', 'finance_accounts'] as $table) {
            $this->assertEquals($senior->id, DB::table($table)->where('branch_id', $swim->id)->whereNotNull('category_id')->value('category_id'), $table);
        }
        $this->assertEquals([$senior->id], DB::table('training_session_category')->where('training_session_id', $rows['swimming_session'])->pluck('category_id')->all());
        $this->assertSame(1, DB::table('category_subscription')->where('subscription_id', $both->id)->count());
        $this->assertEquals($senior->id, DB::table('category_subscription')->where('subscription_id', $only->id)->value('category_id'));
        $this->assertSame(1, DB::table('session_generation_marks')->count());

        $this->expectException(QueryException::class);
        DB::table('categories')->insert(['name' => 'Senior']);
    }

    /** Sessions, schedules, slots, targets, teams, caisses and activities on the category, one set per branch. */
    private function rowsOn(Category $category, array $branches): array
    {
        $ids = [];
        $type = DB::table('activity_types')->value('id');
        foreach ($branches as $branch) {
            $key = strtolower($branch->name);
            $session = $this->heldSession($branch, $category);
            $ids[$key.'_session'] = $session;
            $player = $this->member([['branch_id' => $branch->id, 'category_id' => $category->id]]);
            DB::table('attendances')->insert(['training_session_id' => $session, 'player_id' => $player->id, 'category_id' => $category->id, 'status' => 'present']);
            DB::table('training_schedules')->insert([
                'branch_id' => $branch->id, 'category_id' => $category->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'valid_from' => '2026-01-01',
            ]);
            DB::table('deleted_session_slots')->insert(['branch_id' => $branch->id, 'category_id' => $category->id, 'date' => '2026-03-02', 'start_time' => '18:00']);
            DB::table('preseason_targets')->insert(['branch_id' => $branch->id, 'category_id' => $category->id, 'season_start_year' => 2026, 'target_count' => 10]);
            $this->team($category, ['branch_id' => $branch->id]);
            DB::table('finance_accounts')->insert(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Caisse '.$key, 'type' => 'cash', 'currency' => 'DZD', 'is_active' => true]);
            $activity = DB::table('activities')->insertGetId(['title' => $key, 'activity_type_id' => $type, 'starts_on' => '2026-03-01', 'branch_id' => $branch->id]);
            DB::table('activity_category')->insert(['activity_id' => $activity, 'category_id' => $category->id]);
            $ids[$key.'_activity'] = $activity;
        }
        $club = DB::table('activities')->insertGetId(['title' => 'club', 'activity_type_id' => $type, 'starts_on' => '2026-03-01']);
        DB::table('activity_category')->insert(['activity_id' => $club, 'category_id' => $category->id]);
        $ids['club_activity'] = $club;

        return $ids;
    }

    private function heldSession(Branch $branch, Category $category): int
    {
        $id = DB::table('training_sessions')->insertGetId([
            'branch_id' => $branch->id, 'category_id' => $category->id, 'date' => '2026-03-02', 'start_time' => '18:00', 'end_time' => '19:00',
            'kind' => SessionKind::Regular->value, 'state' => SessionState::Held->value,
        ]);
        DB::table('training_session_category')->insert(['training_session_id' => $id, 'category_id' => $category->id]);

        return $id;
    }

    private function priced(string $name, Category $category, array $branches): Subscription
    {
        $subscription = Subscription::create(['name' => $name, 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 2000]);
        $subscription->categories()->attach($category->id, ['amount_student' => 1500, 'amount_worker' => 2500]);
        $subscription->branches()->attach(array_map(fn (Branch $b) => $b->id, $branches));

        return $subscription;
    }

    private function snapshot(): array
    {
        $tables = ['categories', 'branch_player', 'training_sessions', 'training_session_category', 'training_schedules', 'deleted_session_slots', 'preseason_targets', 'teams', 'finance_accounts', 'activity_category', 'attendances', 'session_generation_marks', 'category_subscription'];

        return collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($row) => (array) $row)->all()])->all();
    }
}
