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
    public function the_keeper_wins_attendance_and_roster_ties(): void
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
}
