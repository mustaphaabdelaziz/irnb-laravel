<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\Merge\FieldMerge;
use App\Services\Player\Merge\MergeClashes;
use App\Services\Player\Merge\MergePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class PlayerMergeRulesTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function clashes(Player $keeper, Player $duplicate): array
    {
        return app(MergeClashes::class)->find($keeper->id, $duplicate->id);
    }

    #[Test]
    public function the_foreign_key_list_matches_the_live_schema(): void
    {
        $live = [];
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $fk) {
                if ($fk['foreign_table'] === 'players') {
                    $live[] = $table['name'].'.'.$fk['columns'][0];
                }
            }
        }
        sort($live);
        $known = MergePreview::FOREIGN_KEYS;
        sort($known);

        $this->assertSame($known, $live, 'A table now points at players: teach PlayerMerger to move it, then list it in MergePreview::FOREIGN_KEYS.');
    }

    #[Test]
    public function field_diff_auto_fills_empties_and_asks_where_both_differ(): void
    {
        $keeper = $this->player(['firstname' => 'فاطمة', 'lastname' => 'بن علي', 'father' => null, 'email' => 'a@example.test', 'phones' => ['0550000001']]);
        $duplicate = $this->player(['firstname' => 'فاطمه', 'lastname' => 'بن علي', 'father' => 'محمد', 'email' => null, 'phones' => ['0550000002', '0550000001']]);

        $rows = collect(app(FieldMerge::class)->diff($keeper, $duplicate))->keyBy('field');

        $this->assertSame('choose', $rows['firstname']['mode']);
        $this->assertSame('same', $rows['lastname']['mode']);
        $this->assertSame('auto', $rows['father']['mode']);
        $this->assertSame('keep', $rows['email']['mode']);

        $attributes = app(FieldMerge::class)->resolve($keeper, $duplicate, ['firstname' => 'duplicate']);

        $this->assertSame('فاطمه', $attributes['firstname']);
        $this->assertSame('محمد', $attributes['father']);
        $this->assertArrayNotHasKey('email', $attributes);
        $this->assertSame(['0550000001', '0550000002'], $attributes['phones']);
        $this->assertFalse($attributes['archived']);
    }

    #[Test]
    public function same_branch_keeps_the_earliest_join_date(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $branch = $this->branch();
        $mine = $this->registration($keeper, $branch, ['join_date' => '2024-09-01']);
        $theirs = $this->registration($duplicate, $branch, ['join_date' => '2021-09-01']);

        $clash = $this->clashes($keeper, $duplicate)[0];

        $this->assertSame(['registration', $mine, $theirs, 'duplicate'], [$clash['kind'], $clash['keeper_row'], $clash['duplicate_row'], $clash['winner']]);
        $this->assertSame([$mine => $theirs], app(MergeClashes::class)->registrationMap([$clash]));
    }

    #[Test]
    public function a_received_document_beats_a_missing_one_across_merged_registrations(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $branch = $this->branch();
        $type = $this->documentType();
        $mine = $this->registration($keeper, $branch, ['join_date' => '2021-09-01']);
        $theirs = $this->registration($duplicate, $branch, ['join_date' => '2024-09-01']);
        $missing = DB::table('player_documents')->insertGetId(['player_id' => $keeper->id, 'branch_registration_id' => $mine, 'document_type_id' => $type, 'state' => 'missing']);
        $received = DB::table('player_documents')->insertGetId(['player_id' => $duplicate->id, 'branch_registration_id' => $theirs, 'document_type_id' => $type, 'state' => PlayerDocument::RECEIVED]);

        $clash = collect($this->clashes($keeper, $duplicate))->firstWhere('kind', 'document');

        $this->assertSame([$missing, $received, 'duplicate'], [$clash['keeper_row'], $clash['duplicate_row'], $clash['winner']]);
    }

    #[Test]
    public function the_keeper_wins_attendance_ties(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $session = $this->trainingSession($this->category($this->branch()));
        DB::table('attendances')->insert([
            ['training_session_id' => $session, 'player_id' => $keeper->id, 'status' => 'present'],
            ['training_session_id' => $session, 'player_id' => $duplicate->id, 'status' => 'absent_unexcused'],
        ]);

        $clash = collect($this->clashes($keeper, $duplicate))->firstWhere('kind', 'attendance');

        $this->assertSame('keeper', $clash['winner']);
        $this->assertSame('2026-10-01', $clash['label']);
    }

    #[Test]
    public function preview_counts_the_rows_that_move(): void
    {
        $duplicate = $this->player();
        $this->registration($duplicate, $this->branch());
        DB::table('player_emergency_contacts')->insert(['player_id' => $duplicate->id, 'name' => 'Parent Test', 'phones' => json_encode(['0550000009']), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['player_emergency_contacts' => 1, 'branch_player' => 1], app(MergePreview::class)->counts($duplicate->id));
    }

    private function team(): array
    {
        $branch = $this->branch();
        $format = DB::table('game_formats')->insertGetId([
            'sport' => 'football', 'name' => 'F11', 'players_on_field' => 11, 'max_bench' => 7, 'max_called_up' => 18,
            'periods_count' => 2, 'period_minutes' => 45, 'period_label' => 'half', 'scoring' => 'goals', 'sanction_types' => '[]',
        ]);
        $team = DB::table('teams')->insertGetId([
            'branch_id' => $branch, 'category_id' => $this->category($branch), 'name' => 'Team Test',
            'default_game_format_id' => $format, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$team, $format];
    }

    private function game(int $team, int $format): int
    {
        $opponent = DB::table('opponents')->insertGetId(['name' => 'Opp '.$team.'-'.random_int(1, 999999), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('games')->insertGetId([
            'team_id' => $team, 'season_start_year' => 2026, 'game_format_id' => $format, 'opponent_id' => $opponent,
            'side' => 'home', 'date' => '2026-10-10', 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function gameRow(int $game, Player $player, array $extra = []): int
    {
        return DB::table('game_players')->insertGetId(array_merge(['game_id' => $game, 'player_id' => $player->id, 'created_at' => now(), 'updated_at' => now()], $extra));
    }

    private function clash(Player $keeper, Player $duplicate, string $kind): array
    {
        $found = collect($this->clashes($keeper, $duplicate))->where('kind', $kind)->values();
        $this->assertCount(1, $found);

        return $found[0];
    }

    #[Test]
    public function a_team_clash_goes_to_the_duplicate_only_when_it_alone_has_a_shirt_number(): void
    {
        [$team] = $this->team();
        $keeper = $this->player();
        $duplicate = $this->player();
        DB::table('team_players')->insert([
            ['team_id' => $team, 'season_start_year' => 2026, 'player_id' => $keeper->id, 'shirt_number' => null],
            ['team_id' => $team, 'season_start_year' => 2026, 'player_id' => $duplicate->id, 'shirt_number' => 9],
        ]);
        $this->assertSame('duplicate', $this->clash($keeper, $duplicate, 'team')['winner']);

        DB::table('team_players')->where('player_id', $keeper->id)->update(['shirt_number' => 4]);
        $this->assertSame('keeper', $this->clash($keeper, $duplicate, 'team')['winner']);
    }

    #[Test]
    public function a_game_row_with_only_a_role_is_not_empty_but_a_blank_one_loses_to_a_called_up_one(): void
    {
        [$team, $format] = $this->team();
        $game = $this->game($team, $format);
        $keeper = $this->player();
        $duplicate = $this->player();
        $mine = $this->gameRow($game, $keeper, ['role' => 'starter']);
        $this->gameRow($game, $duplicate, ['called_up' => true, 'minutes_played' => 60]);
        $this->assertSame('keeper', $this->clash($keeper, $duplicate, 'game')['winner']);

        DB::table('game_players')->where('id', $mine)->update(['role' => 'none']);
        $this->assertSame('duplicate', $this->clash($keeper, $duplicate, 'game')['winner']);
    }

    #[Test]
    public function injury_and_academic_year_clashes_keep_the_keepers_row(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $branch = $this->branch();
        foreach ([$keeper, $duplicate] as $player) {
            DB::table('injury_notes')->insert(['player_id' => $player->id, 'branch_id' => $branch, 'start_date' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('player_academic_years')->insert(['player_id' => $player->id, 'academic_year' => 2025, 'education_level' => 'primary', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame('keeper', $this->clash($keeper, $duplicate, 'injury')['winner']);
        $this->assertSame('keeper', $this->clash($keeper, $duplicate, 'academic_year')['winner']);
    }

    #[Test]
    public function exempt_beats_missing_and_a_document_tie_goes_to_the_keeper(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $type = $this->documentType();
        $mine = DB::table('player_documents')->insertGetId(['player_id' => $keeper->id, 'document_type_id' => $type, 'state' => 'missing']);
        $theirs = DB::table('player_documents')->insertGetId(['player_id' => $duplicate->id, 'document_type_id' => $type, 'state' => PlayerDocument::EXEMPT]);
        $this->assertSame('duplicate', $this->clash($keeper, $duplicate, 'document')['winner']);

        DB::table('player_documents')->whereIn('id', [$mine, $theirs])->update(['state' => PlayerDocument::RECEIVED]);
        $this->assertSame('keeper', $this->clash($keeper, $duplicate, 'document')['winner']);
    }

    #[Test]
    public function equal_join_dates_keep_the_keepers_registration(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $branch = $this->branch();
        $this->registration($keeper, $branch, ['join_date' => '2022-09-01']);
        $this->registration($duplicate, $branch, ['join_date' => '2022-09-01']);

        $this->assertSame('keeper', $this->clash($keeper, $duplicate, 'registration')['winner']);
    }

    #[Test]
    public function a_picture_choice_copies_url_and_filename_and_archived_needs_both(): void
    {
        $keeper = $this->player(['picture_url' => '/media/a.jpg', 'picture_filename' => 'a.jpg', 'archived' => true]);
        $duplicate = $this->player(['picture_url' => '/media/b.jpg', 'picture_filename' => 'b.jpg', 'archived' => false]);
        $merge = app(FieldMerge::class);

        $picked = $merge->resolve($keeper, $duplicate, ['picture' => 'duplicate']);
        $this->assertSame(['/media/b.jpg', 'b.jpg'], [$picked['picture_url'], $picked['picture_filename']]);
        $this->assertFalse($picked['archived']);

        $kept = $merge->resolve($keeper, $duplicate, []);
        $this->assertArrayNotHasKey('picture_url', $kept);
    }

    #[Test]
    public function phones_written_differently_merge_into_one(): void
    {
        $keeper = $this->player(['phones' => ['0550000001']]);
        $duplicate = $this->player(['phones' => ['+213550000001']]);

        $this->assertSame(['0550000001'], app(FieldMerge::class)->resolve($keeper, $duplicate, [])['phones']);
    }

    #[Test]
    public function the_unknown_city_placeholder_counts_as_empty(): void
    {
        $keeper = $this->player(['city' => 'Unknown']);
        $duplicate = $this->player(['city' => 'Oran']);
        $merge = app(FieldMerge::class);

        $this->assertSame('auto', collect($merge->diff($keeper, $duplicate))->firstWhere('field', 'city')['mode']);
        $this->assertSame('Oran', $merge->resolve($keeper, $duplicate, [])['city']);
        $this->assertSame('keep', collect($merge->diff($duplicate, $keeper))->firstWhere('field', 'city')['mode']);
    }

    #[Test]
    public function the_same_subscription_keeps_the_more_paid_row(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $subscription = $this->subscription('Cotisation');
        $mine = $this->playerSubscription($keeper, $subscription, ['amount_paid' => 0]);
        $theirs = $this->playerSubscription($duplicate, $subscription, ['amount_paid' => 1000]);

        $clash = $this->clash($keeper, $duplicate, 'subscription');

        $this->assertSame([$mine, $theirs, 'duplicate'], [$clash['keeper_row'], $clash['duplicate_row'], $clash['winner']]);
        $this->assertSame(DB::table('subscriptions')->where('id', $subscription)->value('name'), $clash['label']);
    }

    #[Test]
    public function a_subscription_paid_the_same_keeps_the_keepers_row_and_manual_debts_never_clash(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $subscription = $this->subscription();
        $this->playerSubscription($keeper, $subscription, ['amount_paid' => 500]);
        $this->playerSubscription($duplicate, $subscription, ['amount_paid' => 500]);
        $this->playerSubscription($keeper, null, ['label' => 'Manual']);
        $this->playerSubscription($duplicate, null, ['label' => 'Manual']);

        $this->assertSame('keeper', $this->clash($keeper, $duplicate, 'subscription')['winner']);
    }

    #[Test]
    public function repeated_slots_within_one_player_pair_only_once(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $type = $this->documentType();
        $first = DB::table('player_documents')->insertGetId(['player_id' => $keeper->id, 'document_type_id' => $type, 'state' => 'missing']);
        DB::table('player_documents')->insert(['player_id' => $keeper->id, 'document_type_id' => $type, 'state' => 'missing']);
        DB::table('player_documents')->insert(['player_id' => $duplicate->id, 'document_type_id' => $type, 'state' => 'missing']);

        $clash = $this->clash($keeper, $duplicate, 'document');
        $this->assertSame($first, $clash['keeper_row']);
    }
}
