<?php

namespace Tests\Feature\Registrations;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\Subscription;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Player
    {
        $branch = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700001', 'join_year' => 2017]);
        $player->branches()->attach($branch->id, ['membership_id' => '201700001', 'category_id' => null, 'legacy_join_year' => 2017]);

        return $player;
    }

    #[Test]
    public function clean_data_passes(): void
    {
        $this->member();

        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function a_wrong_copy_fails(): void
    {
        $player = $this->member();
        DB::table('players')->where('id', $player->id)->update(['licence_number' => 'WRONG']);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('player copy differs from primary')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_year_mismatch_fails(): void
    {
        $player = $this->member();
        DB::table('branch_player')->where('player_id', $player->id)->update(['legacy_join_year' => 1999]);
        DB::table('players')->where('id', $player->id)->update(['join_year' => 2017]);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('join year differs')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_player_copy_of_the_id_that_differs_fails(): void
    {
        $player = $this->member();
        DB::table('players')->where('id', $player->id)->update(['membership_id' => '201799999']);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('player copy differs from primary')
            ->expectsOutputToContain('membership_id')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_duplicate_id_fails(): void
    {
        $player = $this->member();
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        Schema::table('branch_player', fn ($table) => $table->dropUnique('branch_reg_membership_unique'));
        DB::table('branch_player')->insert([
            'branch_id' => $swim->id, 'player_id' => $player->id, 'membership_id' => '201700001', 'legacy_join_year' => 2017,
        ]);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('duplicate IDs')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_player_left_while_the_only_registration_is_active_fails(): void
    {
        $player = $this->member();
        DB::table('players')->where('id', $player->id)->update(['status_id' => Player::leftStatusId(), 'left_at' => '2024-02-03']);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('player status or leave date differs')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_leave_date_on_a_registration_that_has_not_left_fails(): void
    {
        $player = $this->member();
        DB::table('branch_player')->where('player_id', $player->id)->update(['left_at' => '2024-02-03']);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('leave date on a registration that has not left')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_left_registration_without_a_leave_date_is_allowed(): void
    {
        $player = $this->member();
        DB::table('branch_player')->where('player_id', $player->id)->update(['status_id' => Player::leftStatusId()]);
        DB::table('players')->where('id', $player->id)->update(['status_id' => Player::leftStatusId(), 'left_at' => null]);

        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function a_player_with_no_branch_is_reported_but_does_not_fail(): void
    {
        Player::create(['firstname' => 'Solo', 'membership_id' => '201700002', 'join_year' => 2017]);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('no branch: 1')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_document_with_no_registration_fails_when_the_player_has_one(): void
    {
        $player = $this->member();
        DB::table('player_documents')->insert([
            'player_id' => $player->id, 'branch_registration_id' => null,
            'document_type_id' => DocumentType::query()->value('id'), 'state' => 'received',
        ]);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('document with no registration of its player')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_document_on_another_players_registration_fails(): void
    {
        $player = $this->member();
        $other = Player::create(['firstname' => 'B', 'membership_id' => '201700002']);
        DB::table('player_documents')->insert([
            'player_id' => $other->id, 'branch_registration_id' => $player->registrations()->value('id'),
            'document_type_id' => DocumentType::query()->value('id'), 'state' => 'received',
        ]);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('document with no registration of its player')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_blank_document_passes_for_a_player_with_no_registration_unless_doubled(): void
    {
        $this->member();
        $flat = Player::create(['firstname' => 'B', 'membership_id' => '201700002']);
        $row = ['player_id' => $flat->id, 'branch_registration_id' => null, 'document_type_id' => DocumentType::query()->value('id'), 'state' => 'received'];
        DB::table('player_documents')->insert($row);

        $this->artisan('registrations:verify')->assertExitCode(0);

        DB::table('player_documents')->insert($row);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('document held twice')
            ->assertExitCode(1);
    }

    private function club(): array
    {
        $foot = Branch::firstOrCreate(['name' => 'Football'], ['code' => 'FB']);
        $swim = Branch::firstOrCreate(['name' => 'Swimming'], ['code' => 'SW']);

        return [$foot, $swim, Category::create(['name' => 'Senior', 'branch_id' => $foot->id]), Category::create(['name' => 'Senior', 'branch_id' => $swim->id])];
    }

    #[Test]
    public function an_unowned_category_fails_once_a_branch_exists(): void
    {
        $this->club();
        Category::create(['name' => 'Orphan']);

        $this->artisan('registrations:verify')->expectsOutputToContain('category with no branch')->assertExitCode(1);
    }

    #[Test]
    public function an_unowned_category_is_fine_with_no_branches(): void
    {
        Category::create(['name' => 'Senior']);

        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function a_registrations_category_of_another_branch_fails(): void
    {
        [$foot, , , $swimSenior] = $this->club();
        $player = Player::create(['firstname' => 'A', 'membership_id' => '202000001', 'join_year' => 2020]);
        app(\App\Services\Player\RegistrationService::class)->register($player, ['branch_id' => $foot->id, 'category_id' => $swimSenior->id]);

        $this->artisan('registrations:verify')->expectsOutputToContain('category of another branch')->assertExitCode(1);
    }

    #[Test]
    public function a_session_pivot_category_of_another_branch_fails(): void
    {
        [$foot, , $senior, $swimSenior] = $this->club();
        $session = TrainingSession::create(['branch_id' => $foot->id, 'category_id' => $senior->id, 'date' => '2026-03-02', 'start_time' => '18:00', 'end_time' => '19:00', 'kind' => 'preseason', 'state' => 'held']);
        $session->categories()->syncWithoutDetaching([$senior->id, $swimSenior->id]);

        $this->artisan('registrations:verify')->expectsOutputToContain('category of another branch')->assertExitCode(1);
    }

    #[Test]
    public function same_named_categories_priced_differently_fail(): void
    {
        [, , $senior, $swimSenior] = $this->club();
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 1, 'amount_worker' => 1]);
        $subscription->categories()->attach($senior->id, ['amount_student' => 100, 'amount_worker' => 200]);
        $subscription->categories()->attach($swimSenior->id, ['amount_student' => 150, 'amount_worker' => 200]);

        $this->artisan('registrations:verify')->expectsOutputToContain('same-named categories priced differently')->assertExitCode(1);
    }

    #[Test]
    public function a_copy_with_no_price_row_fails_where_the_subscription_applies(): void
    {
        [$foot, $swim, $senior] = $this->club();
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 1, 'amount_worker' => 1]);
        $subscription->categories()->attach($senior->id, ['amount_student' => 100, 'amount_worker' => 200]);
        $subscription->branches()->attach([$foot->id, $swim->id]);

        $this->artisan('registrations:verify')->expectsOutputToContain('same-named category with no price row')->assertExitCode(1);
    }

    #[Test]
    public function a_copy_with_no_price_row_is_fine_where_the_subscription_does_not_apply(): void
    {
        [$foot, , $senior] = $this->club();
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 1, 'amount_worker' => 1]);
        $subscription->categories()->attach($senior->id, ['amount_student' => 100, 'amount_worker' => 200]);
        $subscription->branches()->attach([$foot->id]);

        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function matching_prices_pass(): void
    {
        [$foot, $swim, $senior, $swimSenior] = $this->club();
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 1, 'amount_worker' => 1]);
        $subscription->categories()->attach($senior->id, ['amount_student' => 100, 'amount_worker' => 200]);
        $subscription->categories()->attach($swimSenior->id, ['amount_student' => 100, 'amount_worker' => 200]);
        $subscription->branches()->attach([$foot->id, $swim->id]);

        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function a_deleted_slot_of_another_branch_fails(): void
    {
        [$foot, , , $swimSenior] = $this->club();
        DB::table('deleted_session_slots')->insert(['branch_id' => $foot->id, 'category_id' => $swimSenior->id, 'date' => '2026-03-02', 'start_time' => '18:00']);

        $this->artisan('registrations:verify')->expectsOutputToContain('category of another branch')->assertExitCode(1);
    }

    #[Test]
    public function a_preseason_target_of_another_branch_fails(): void
    {
        [$foot, , , $swimSenior] = $this->club();
        DB::table('preseason_targets')->insert(['branch_id' => $foot->id, 'category_id' => $swimSenior->id, 'season_start_year' => 2026, 'target_count' => 10]);

        $this->artisan('registrations:verify')->expectsOutputToContain('category of another branch')->assertExitCode(1);
    }

    #[Test]
    public function an_activity_category_of_another_branch_fails(): void
    {
        [$foot, , , $swimSenior] = $this->club();
        $activity = Activity::create(['title' => 'Camp', 'activity_type_id' => ActivityType::query()->value('id'), 'starts_on' => '2026-03-02', 'branch_id' => $foot->id]);
        DB::table('activity_category')->insert(['activity_id' => $activity->id, 'category_id' => $swimSenior->id]);

        $this->artisan('registrations:verify')->expectsOutputToContain('category of another branch')->assertExitCode(1);
    }

    #[Test]
    public function an_attendance_category_of_another_branch_fails(): void
    {
        [$foot, , $senior, $swimSenior] = $this->club();
        $session = TrainingSession::create(['branch_id' => $foot->id, 'category_id' => $senior->id, 'date' => '2026-03-02', 'start_time' => '18:00', 'end_time' => '19:00', 'kind' => 'preseason', 'state' => 'held']);
        $player = Player::create(['firstname' => 'A', 'membership_id' => '202000002', 'join_year' => 2020]);
        DB::table('attendances')->insert(['training_session_id' => $session->id, 'player_id' => $player->id, 'category_id' => $swimSenior->id, 'status' => 'present']);

        $this->artisan('registrations:verify')->expectsOutputToContain('category of another branch')->assertExitCode(1);
    }
}
