<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDuplicateDecision;
use App\Models\User;
use App\Services\Player\Merge\MergePreview;
use App\Services\Player\Merge\PlayerMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class PlayerMergerTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function merge(Player $keeper, Player $duplicate, array $choices = []): PlayerDuplicateDecision
    {
        return app(PlayerMerger::class)->merge($keeper, $duplicate, $choices, User::factory()->admin()->create());
    }

    private function assertNothingPointsAt(int $playerId): void
    {
        foreach (MergePreview::FOREIGN_KEYS as $key) {
            [$table, $column] = explode('.', $key);
            $this->assertSame(0, DB::table($table)->where($column, $playerId)->count(), $key);
        }
        $this->assertSame(0, DB::table('transactions')->where('related_entity_type', 'Player')->where('related_entity_id', $playerId)->count());
        $this->assertSame(0, DB::table('equipment_rentals')->where('rentable_type', Player::class)->where('rentable_id', $playerId)->count());
        $this->assertSame(0, DB::table('activity_logs')->where('subject_type', Player::class)->where('subject_id', $playerId)->count());
    }

    #[Test]
    public function a_player_cannot_be_merged_into_itself(): void
    {
        $player = $this->player();

        $this->expectException(InvalidArgumentException::class);
        $this->merge($player, $player);
    }

    #[Test]
    public function it_moves_everything_applies_choices_and_deletes_the_duplicate(): void
    {
        $keeper = $this->player(['firstname' => 'فاطمة', 'lastname' => 'بن علي', 'father' => null, 'phones' => ['0550000001']]);
        $duplicate = $this->player(['firstname' => 'فاطمه', 'lastname' => 'بن علي', 'father' => 'محمد', 'phones' => ['0550000002']]);
        DB::table('player_emergency_contacts')->insert(['player_id' => $duplicate->id, 'name' => 'Parent Test', 'phones' => json_encode(['0550000009']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('transactions')->insert(['amount' => 1000, 'transaction_date' => '2026-10-01', 'transaction_type' => 'income', 'category' => 'subscription', 'related_entity_type' => 'Player', 'related_entity_id' => $duplicate->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('activity_logs')->insert(['action' => 'player_registered', 'subject_type' => Player::class, 'subject_id' => $duplicate->id, 'occurred_at' => now(), 'created_at' => now()]);
        $branch = $this->branch();
        $this->registration($duplicate, $branch, ['membership_id' => 'FB-2021-00001', 'join_date' => '2021-09-01']);

        $decision = $this->merge($keeper, $duplicate, ['firstname' => 'duplicate']);

        $this->assertDatabaseMissing('players', ['id' => $duplicate->id]);
        $this->assertNothingPointsAt($duplicate->id);
        $keeper->refresh();
        $this->assertSame('فاطمه', $keeper->firstname);
        $this->assertSame('محمد', $keeper->father);
        $this->assertSame(['0550000001', '0550000002'], $keeper->phones);
        $this->assertSame(1, DB::table('branch_player')->where('player_id', $keeper->id)->where('membership_id', 'FB-2021-00001')->count());
        $this->assertSame(1, DB::table('player_emergency_contacts')->where('player_id', $keeper->id)->count());

        $this->assertSame(PlayerDuplicateDecision::MERGED, $decision->status);
        $this->assertSame($keeper->id, $decision->kept_player_id);
        // The snapshot holds the duplicate as it was before the merge.
        $snapshot = $decision->player_a_id === $keeper->id ? $decision->player_b_snapshot : $decision->player_a_snapshot;
        $this->assertSame($duplicate->id, $snapshot['id']);
    }

    #[Test]
    public function the_earliest_registration_wins_and_takes_the_other_documents_and_history(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $branch = $this->branch();
        $type = $this->documentType();
        $mine = $this->registration($keeper, $branch, ['membership_id' => 'FB-2024-00010', 'join_date' => '2024-09-01']);
        $theirs = $this->registration($duplicate, $branch, ['membership_id' => 'FB-2021-00003', 'join_date' => '2021-09-01']);
        $missing = DB::table('player_documents')->insertGetId(['player_id' => $keeper->id, 'branch_registration_id' => $mine, 'document_type_id' => $type, 'state' => 'missing']);
        $received = DB::table('player_documents')->insertGetId(['player_id' => $duplicate->id, 'branch_registration_id' => $theirs, 'document_type_id' => $type, 'state' => PlayerDocument::RECEIVED]);
        DB::table('player_document_files')->insert(['player_document_id' => $missing, 'path' => 'x/a.pdf', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10, 'created_at' => now()]);
        DB::table('registration_status_changes')->insert(['branch_registration_id' => $mine, 'changed_on' => '2025-01-01', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

        $this->merge($keeper, $duplicate);

        $this->assertSame([$theirs], DB::table('branch_player')->where('player_id', $keeper->id)->pluck('id')->all());
        $this->assertSame('FB-2021-00003', DB::table('branch_player')->where('id', $theirs)->value('membership_id'));
        $this->assertSame([$received], DB::table('player_documents')->where('player_id', $keeper->id)->pluck('id')->all());
        $this->assertSame($received, (int) DB::table('player_document_files')->value('player_document_id'));
        $this->assertSame($theirs, (int) DB::table('registration_status_changes')->value('branch_registration_id'));
    }

    #[Test]
    public function the_kept_players_copy_follows_a_moved_registration(): void
    {
        $keeper = $this->player(['membership_id' => 'T-OLD-1']);
        $duplicate = $this->player();
        $branch = $this->branch();
        $category = $this->category($branch);
        $status = DB::table('player_statuses')->insertGetId(['name' => 'Active', 'code' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->registration($duplicate, $branch, ['membership_id' => 'FB-2021-00007', 'join_date' => '2021-09-01', 'category_id' => $category, 'status_id' => $status]);

        $this->merge($keeper, $duplicate);

        $row = DB::table('players')->where('id', $keeper->id)->first();
        $this->assertSame('FB-2021-00007', $row->membership_id);
        $this->assertSame($category, (int) $row->category_id);
        $this->assertSame($status, (int) $row->status_id);
        $this->assertSame(2021, (int) $row->join_year);
    }

    #[Test]
    public function a_losing_keeper_registration_leaves_its_id_searchable_on_the_winner(): void
    {
        $keeper = $this->player(['membership_id' => 'FB-2024-00010']);
        $duplicate = $this->player();
        $branch = $this->branch();
        $this->registration($keeper, $branch, ['membership_id' => 'FB-2024-00010', 'join_date' => '2024-09-01']);
        $theirs = $this->registration($duplicate, $branch, ['membership_id' => 'FB-2021-00003', 'join_date' => '2021-09-01']);

        $this->merge($keeper, $duplicate);

        $this->assertSame('FB-2021-00003', DB::table('players')->where('id', $keeper->id)->value('membership_id'));
        $this->assertSame('FB-2024-00010', DB::table('branch_player')->where('id', $theirs)->value('legacy_membership_id'));
    }

    #[Test]
    public function a_winner_that_already_holds_an_old_id_keeps_its_own(): void
    {
        $keeper = $this->player(['membership_id' => 'FB-2024-00010']);
        $duplicate = $this->player();
        $branch = $this->branch();
        $this->registration($keeper, $branch, ['membership_id' => 'FB-2024-00010', 'join_date' => '2024-09-01']);
        $theirs = $this->registration($duplicate, $branch, ['membership_id' => 'FB-2021-00003', 'legacy_membership_id' => '202100003', 'join_date' => '2021-09-01']);

        $this->merge($keeper, $duplicate);

        $this->assertSame('202100003', DB::table('branch_player')->where('id', $theirs)->value('legacy_membership_id'));
    }

    #[Test]
    public function the_same_subscription_on_both_keeps_the_paid_one_and_is_owed_once(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $subscription = $this->subscription();
        $mine = $this->playerSubscription($keeper, $subscription);
        $theirs = $this->playerSubscription($duplicate, $subscription, ['amount_paid' => 1000]);
        $payment = $this->payment($duplicate, $theirs, 1000);

        $this->merge($keeper, $duplicate);

        $this->assertSame([$theirs], DB::table('player_subscriptions')->where('player_id', $keeper->id)->pluck('id')->all());
        $this->assertDatabaseMissing('player_subscriptions', ['id' => $mine]);
        $this->assertSame($theirs, (int) DB::table('transactions')->where('id', $payment)->value('player_subscription_id'));
        $this->assertEquals(1000, (float) DB::table('player_subscriptions')->where('id', $theirs)->value('amount_paid'));
        $this->assertEquals(0, (float) $keeper->fresh()->outstanding_debt);
    }

    #[Test]
    public function the_losing_subscriptions_payments_move_to_the_kept_one(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $subscription = $this->subscription();
        $mine = $this->playerSubscription($keeper, $subscription, ['amount_paid' => 300]);
        $theirs = $this->playerSubscription($duplicate, $subscription, ['amount_paid' => 500]);
        $this->payment($keeper, $mine, 300);
        $this->payment($duplicate, $theirs, 500);

        $this->merge($keeper, $duplicate);

        $this->assertSame([$theirs], DB::table('player_subscriptions')->where('player_id', $keeper->id)->pluck('id')->all());
        $this->assertSame(2, DB::table('transactions')->where('player_subscription_id', $theirs)->count());
        $this->assertEquals(800, (float) DB::table('player_subscriptions')->where('id', $theirs)->value('amount_paid'));
        $this->assertEquals(200, (float) $keeper->fresh()->outstanding_debt);
    }

    #[Test]
    public function the_keeper_wins_session_ties_and_shared_pivots_are_not_doubled(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $category = $this->category($this->branch());
        $shared = $this->trainingSession($category, '2026-10-01');
        $only = $this->trainingSession($category, '2026-10-08');
        DB::table('attendances')->insert([
            ['training_session_id' => $shared, 'player_id' => $keeper->id, 'status' => 'present'],
            ['training_session_id' => $shared, 'player_id' => $duplicate->id, 'status' => 'absent_unexcused'],
            ['training_session_id' => $only, 'player_id' => $duplicate->id, 'status' => 'late'],
        ]);
        $position = DB::table('positions')->insertGetId(['name' => 'Gardien', 'abbreviation' => 'GK']);
        DB::table('player_other_positions')->insert([
            ['player_id' => $keeper->id, 'position_id' => $position],
            ['player_id' => $duplicate->id, 'position_id' => $position],
        ]);

        $this->merge($keeper, $duplicate);

        $this->assertSame(['present', 'late'], DB::table('attendances')->where('player_id', $keeper->id)->orderBy('training_session_id')->pluck('status')->all());
        $this->assertSame(1, DB::table('player_other_positions')->where('player_id', $keeper->id)->count());
    }

    #[Test]
    public function the_keepers_school_year_gains_the_missing_periods(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $mine = DB::table('player_academic_years')->insertGetId(['player_id' => $keeper->id, 'academic_year' => '2025-2026', 'education_level' => 'cem1']);
        $theirs = DB::table('player_academic_years')->insertGetId(['player_id' => $duplicate->id, 'academic_year' => '2025-2026', 'education_level' => 'cem1']);
        DB::table('player_academic_records')->insert([
            ['player_academic_year_id' => $mine, 'period' => 't1', 'gpa' => 12],
            ['player_academic_year_id' => $theirs, 'period' => 't1', 'gpa' => 9],
            ['player_academic_year_id' => $theirs, 'period' => 't2', 'gpa' => 14],
        ]);

        $this->merge($keeper, $duplicate);

        $this->assertSame([$mine], DB::table('player_academic_years')->where('player_id', $keeper->id)->pluck('id')->all());
        $this->assertEquals(['t1' => 12, 't2' => 14], DB::table('player_academic_records')->where('player_academic_year_id', $mine)->pluck('gpa', 'period')->map(fn ($g) => (float) $g)->all());
    }

    #[Test]
    public function dismissed_pairs_of_the_duplicate_are_dropped_and_a_dismissed_pair_becomes_merged(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $third = $this->player();
        foreach ([[$keeper, $duplicate], [$duplicate, $third]] as [$x, $y]) {
            PlayerDuplicateDecision::create([
                'player_a_id' => $x->id, 'player_b_id' => $y->id, 'status' => PlayerDuplicateDecision::DISMISSED,
                'player_a_snapshot' => [], 'player_b_snapshot' => [], 'decided_at' => now(),
            ]);
        }

        $this->merge($keeper, $duplicate);

        $decision = PlayerDuplicateDecision::sole();
        $this->assertSame(PlayerDuplicateDecision::MERGED, $decision->status);
        $this->assertSame($keeper->id, $decision->player_a_id);
        $this->assertNull($decision->player_b_id);
    }

    #[Test]
    public function a_failure_rolls_everything_back(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $this->registration($duplicate, $this->branch());
        Player::saving(function (Player $player) use ($keeper) {
            if ($player->id === $keeper->id) {
                throw new RuntimeException('boom');
            }
        });

        try {
            $this->merge($keeper, $duplicate);
            $this->fail('The merge should have thrown.');
        } catch (RuntimeException) {
        }

        $this->assertDatabaseHas('players', ['id' => $duplicate->id]);
        $this->assertSame(1, DB::table('branch_player')->where('player_id', $duplicate->id)->count());
        $this->assertDatabaseCount('player_duplicate_decisions', 0);
    }
}
