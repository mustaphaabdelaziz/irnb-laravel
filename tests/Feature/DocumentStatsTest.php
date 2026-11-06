<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\DocumentStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Seeded types (migration 2026_09_24_100002): birth_certificate, photo,
 * medical_certificate (required); parental_authorization (required, max_age
 * 17); id_card_copy, residence_certificate, school_certificate (optional).
 * Adults below are born 1990, so three types apply to them, four to minors.
 */
class DocumentStatsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05');
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function player(array $attributes = []): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '20267'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Player'.$this->sequence,
            'lastname' => 'Stats',
            'birthdate' => '1990-01-01',
            ...$attributes,
        ]);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    private function received(Player $player, string $code, ?string $validUntil = null): void
    {
        PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $this->type($code)->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
            'valid_until' => $validUntil,
        ]);
    }

    private function exempt(Player $player, string $code): void
    {
        PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $this->type($code)->id,
            'state' => PlayerDocument::EXEMPT,
            'exempt_reason' => 'Handled by the federation',
        ]);
    }

    private function stats(): DocumentStats
    {
        return app(DocumentStats::class);
    }

    private function summary(): ?array
    {
        return $this->stats()->summary(Player::query());
    }

    /** @return array<string, array<string, mixed>> code => by_type row */
    private function byType(array $summary): array
    {
        $codes = DocumentType::pluck('code', 'id');

        return collect($summary['by_type'])->mapWithKeys(fn (array $row) => [$codes[$row['id']] => $row])->all();
    }

    /** The five counters of a row, in the order DocumentStats writes them. */
    private function counts(array $row): array
    {
        return array_intersect_key($row, array_flip(['required', 'received', 'expiring', 'missing', 'pct']));
    }

    /**
     * Nine players, one situation each (required / received / missing):
     *  a nothing 3/0/3 · b complete 3/3/0 · c medical expiring 3/1/2 ·
     *  d medical expired 3/0/3 · e medical exempt 2/0/2 ·
     *  f picture + exempt photo 3/1/2 · g exempt photo, no picture 2/0/2 ·
     *  h minor 4/0/4 · i optional documents only 3/0/3.
     *
     * @return array<string, Player>
     */
    private function example(): array
    {
        $a = $this->player();

        $b = $this->player(['picture_url' => '/media/players/b.jpg']);
        $this->received($b, 'birth_certificate');
        $this->received($b, 'medical_certificate', '2027-08-31');

        $c = $this->player();
        $this->received($c, 'medical_certificate', '2026-10-20');

        $d = $this->player();
        $this->received($d, 'medical_certificate', '2026-10-04');

        $e = $this->player();
        $this->exempt($e, 'medical_certificate');

        $f = $this->player(['picture_url' => '/media/players/f.jpg']);
        $this->exempt($f, 'photo');

        $g = $this->player();
        $this->exempt($g, 'photo');

        $h = $this->player(['birthdate' => '2012-03-01']);

        $i = $this->player();
        $this->received($i, 'school_certificate', '2026-10-20');
        $this->received($i, 'id_card_copy', '2026-10-10');

        return compact('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i');
    }

    #[Test]
    public function the_summary_counts_documents_the_way_the_player_checklist_does(): void
    {
        $this->example();

        $summary = $this->summary();

        $this->assertSame(26, $summary['required']);
        $this->assertSame(5, $summary['received']);
        $this->assertSame(1, $summary['expiring']);
        $this->assertSame(21, $summary['missing']);
        $this->assertSame(19.2, $summary['pct']);
        $this->assertSame(1, $summary['players_complete']);
        $this->assertSame(9, $summary['players_total']);

        $types = $this->byType($summary);
        $this->assertSame(['required' => 9, 'received' => 1, 'expiring' => 0, 'missing' => 8, 'pct' => 11.1], $this->counts($types['birth_certificate']));
        // f's picture settles the photo despite the exempt record; g's exempt photo is not required.
        $this->assertSame(['required' => 8, 'received' => 2, 'expiring' => 0, 'missing' => 6, 'pct' => 25.0], $this->counts($types['photo']));
        // c's expiring certificate is received; d's expired one is missing; e is exempt.
        $this->assertSame(['required' => 8, 'received' => 2, 'expiring' => 1, 'missing' => 6, 'pct' => 25.0], $this->counts($types['medical_certificate']));
        // Only the minor needs the parental authorization.
        $this->assertSame(['required' => 1, 'received' => 0, 'expiring' => 0, 'missing' => 1, 'pct' => 0.0], $this->counts($types['parental_authorization']));
        // Optional types never count.
        $this->assertArrayNotHasKey('school_certificate', $types);
        $this->assertArrayNotHasKey('id_card_copy', $types);
        $this->assertSame('Birth certificate', $types['birth_certificate']['name']);
    }

    #[Test]
    public function rows_come_worst_first_with_nothing_required_last(): void
    {
        $this->player();
        $done = $this->player();
        $this->received($done, 'birth_certificate');

        $codes = DocumentType::pluck('code', 'id');
        $byType = $this->summary()['by_type'];

        // medical 0 % and photo 0 % tie → by name (Medical < Photo); birth 50 %;
        // nobody is young enough for the parental authorization → null, last.
        $this->assertSame(
            ['medical_certificate', 'photo', 'birth_certificate', 'parental_authorization'],
            array_map(fn (array $row) => $codes[$row['id']], $byType),
        );
        $this->assertNull($byType[3]['pct']);
        $this->assertSame(0, $byType[3]['required']);
    }

    #[Test]
    public function inactive_and_optional_types_are_never_counted(): void
    {
        $player = $this->player();
        $this->received($player, 'school_certificate');
        $this->type('medical_certificate')->update(['is_active' => false]);

        $summary = $this->summary();

        $this->assertSame(2, $summary['required']);
        $this->assertSame(2, $summary['missing']);
        $this->assertSame(
            ['birth_certificate', 'parental_authorization', 'photo'],
            collect(array_keys($this->byType($summary)))->sort()->values()->all(),
        );
    }

    #[Test]
    public function a_player_with_nothing_required_has_a_complete_file(): void
    {
        $player = $this->player();
        foreach (['birth_certificate', 'photo', 'medical_certificate'] as $code) {
            $this->exempt($player, $code);
        }

        $summary = $this->summary();

        $this->assertSame(0, $summary['required']);
        $this->assertNull($summary['pct']);
        $this->assertSame(1, $summary['players_complete']);
        $this->assertSame(1, $summary['players_total']);
    }

    #[Test]
    public function without_any_active_required_type_there_is_nothing_to_report(): void
    {
        $this->player();
        DocumentType::query()->update(['is_required' => false]);

        $this->assertNull($this->summary());
        $this->assertNull($this->stats()->breakdown(Player::query()));
    }

    #[Test]
    public function an_empty_population_reports_zeros_and_no_percent(): void
    {
        $summary = $this->summary();

        $this->assertSame(0, $summary['players_total']);
        $this->assertSame(0, $summary['players_complete']);
        $this->assertSame(0, $summary['required']);
        $this->assertNull($summary['pct']);
        $this->assertCount(4, $summary['by_type']);
        $this->assertSame([null, null, null, null], array_column($summary['by_type'], 'pct'));

        $this->assertSame([], $this->stats()->breakdown(Player::query())['by_category']);
    }

    #[Test]
    public function the_population_is_whatever_builder_it_is_given_in_two_queries(): void
    {
        ['a' => $a, 'b' => $b] = $this->example();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $eloquent = $this->stats()->summary(Player::query()->whereKey([$a->id, $b->id])->orderBy('lastname'));
        $this->assertSame(2, $queries, 'one read of the types, one aggregate');

        // The players list hands over a query builder (PlayerController::statsQuery).
        $base = $this->stats()->summary(Player::query()->whereKey([$a->id, $b->id])->getQuery()->from('players'));

        $this->assertSame(2, $eloquent['players_total']);
        $this->assertSame(6, $eloquent['required']);
        $this->assertSame(3, $eloquent['received']);
        $this->assertSame(1, $eloquent['players_complete']);
        $this->assertSame($eloquent, $base);
    }

    #[Test]
    public function the_breakdown_groups_by_category_and_a_club_of_one_category_each_adds_up_to_the_summary(): void
    {
        $seniors = Category::create(['name' => 'Seniors']);
        $u13 = Category::create(['name' => 'U13']);
        $complete = $this->player(['category_id' => $seniors->id, 'picture_url' => '/media/players/s.jpg']);
        $this->received($complete, 'birth_certificate');
        $this->received($complete, 'medical_certificate', '2027-08-31');
        $this->player(['category_id' => $seniors->id]);                            // 0 of 3
        $this->player(['category_id' => $u13->id, 'birthdate' => '2014-01-01']);  // 0 of 4
        $this->player();                                                           // no category, 0 of 3

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $result = $this->stats()->breakdown(Player::query());
        $this->assertSame(5, $queries, 'the types, the category names, the grouped query, then the types and the summary');

        // Worst first: two 0 % rows (no category sorts before "U13" by name), then Seniors.
        $this->assertSame([null, 'U13', 'Seniors'], array_column($result['by_category'], 'name'));

        $rows = collect($result['by_category'])->keyBy(fn (array $row) => $row['name'] ?? 'none');
        $this->assertSame(['required' => 6, 'received' => 3, 'expiring' => 0, 'missing' => 3, 'pct' => 50.0], $this->counts($rows['Seniors']));
        $this->assertSame($seniors->id, $rows['Seniors']['category_id']);
        $this->assertSame(2, $rows['Seniors']['players_total']);
        $this->assertSame(1, $rows['Seniors']['players_complete']);
        $this->assertSame(4, $rows['U13']['required']);
        $this->assertSame(0.0, $rows['U13']['pct']);
        $this->assertNull($rows['none']['category_id']);
        $this->assertSame(3, $rows['none']['required']);

        // Nobody here holds two registrations, so the lines add up to the summary.
        $this->assertSame(13, $result['summary']['required']);
        $this->assertSame(4, $result['summary']['players_total']);
        $this->assertSame($result['summary'], $this->summary());
    }
}
