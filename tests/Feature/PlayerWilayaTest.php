<?php

namespace Tests\Feature;

use App\Models\CountryState;
use App\Models\Player;
use App\Models\User;
use App\Support\WilayaMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerWilayaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function wilaya(string $code): CountryState
    {
        return CountryState::query()->where('code', $code)->firstOrFail();
    }

    #[Test]
    public function the_form_offers_every_wilaya_with_its_code_and_both_names(): void
    {
        $props = $this->actingAs($this->admin())->get(route('players.create'))
            ->assertOk()->viewData('page')['props'];

        $this->assertCount(58, $props['wilayas']);

        $ghardaia = collect($props['wilayas'])->firstWhere('code', '47');
        $this->assertSame('Ghardaïa', $ghardaia['name']);
        $this->assertSame('غرداية', $ghardaia['ar_name']);
        $this->assertSame($this->wilaya('47')->id, $ghardaia['id']);

        // Communes are keyed by the same id the form submits.
        $this->assertArrayHasKey($this->wilaya('47')->id, $props['communes']);
    }

    #[Test]
    public function a_player_is_saved_against_a_wilaya_id(): void
    {
        $ghardaia = $this->wilaya('47');

        $this->actingAs($this->admin())->post(route('players.store'), [
            'firstname' => 'Amine',
            'wilaya_id' => $ghardaia->id,
            'city' => 'Metlili',
        ])->assertRedirect();

        $player = Player::query()->firstOrFail();
        $this->assertSame($ghardaia->id, $player->wilaya_id);
        $this->assertSame('Ghardaïa', $player->wilaya->name_fr);
    }

    /**
     * Show.vue falls back to player.wilaya?.localized_name (and then
     * player.state) so the linked wilaya's name follows the viewer's
     * language — this locks in that the show page actually serializes the
     * `wilaya` relation with its `localized_name` append, not just the id.
     */
    #[Test]
    public function the_show_page_exposes_the_wilayas_localized_name(): void
    {
        $ghardaia = $this->wilaya('47');

        $player = Player::create([
            'membership_id' => '202600099',
            'firstname' => 'Amine',
            'wilaya_id' => $ghardaia->id,
            'city' => 'Metlili',
        ]);

        // The default admin() actor's preferred_lng is 'ar' (users table default).
        $props = $this->actingAs($this->admin())
            ->get(route('players.show', $player))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame($ghardaia->id, $props['player']['wilaya']['id']);
        $this->assertSame('غرداية', $props['player']['wilaya']['localized_name']);
    }

    #[Test]
    public function the_list_filters_by_wilaya(): void
    {
        $ghardaia = $this->wilaya('47');
        $alger = $this->wilaya('16');

        Player::create(['membership_id' => '202600001', 'firstname' => 'Amine', 'wilaya_id' => $ghardaia->id]);
        Player::create(['membership_id' => '202600002', 'firstname' => 'Yanis', 'wilaya_id' => $alger->id]);

        $props = $this->actingAs($this->admin())
            ->get(route('players.index', ['wilaya_id' => $ghardaia->id]))
            ->assertOk()->viewData('page')['props'];

        $this->assertCount(1, $props['players']['data']);
        $this->assertSame('Amine', $props['players']['data'][0]['firstname']);
    }

    #[Test]
    public function the_import_resolves_a_wilaya_by_code_name_or_arabic_name(): void
    {
        $header = implode(',', array_fill(0, 20, 'h'));
        $row = function (string $wilaya, string $membership) {
            $cells = array_fill(0, 20, '');
            $cells[0] = 'Amine'.$membership;
            $cells[19] = $wilaya;

            return implode(',', $cells);
        };

        $csv = "\xEF\xBB\xBF".implode("\n", [$header, $row('47', 'a'), $row('Ghardaïa', 'b'), $row('غرداية', 'c')])."\n";

        $this->actingAs($this->admin())->post(route('players.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('players.csv', $csv),
        ])->assertRedirect();

        $expected = $this->wilaya('47')->id;
        $this->assertSame([$expected, $expected, $expected], Player::query()->orderBy('id')->pluck('wilaya_id')->all());
    }

    #[Test]
    public function the_export_names_the_wilaya_in_the_users_language(): void
    {
        Player::create([
            'membership_id' => '202600003', 'firstname' => 'Amine',
            'wilaya_id' => $this->wilaya('47')->id,
        ]);

        // preferred_lng defaults to 'ar' for every user (see the users table
        // migration), so the default admin() actor would see the Arabic name
        // here — a French-speaking user is what "the user's language" means.
        $frenchAdmin = User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => 'fr']);

        $csv = $this->actingAs($frenchAdmin)->get(route('players.export'))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Ghardaïa', $csv);
    }

    /**
     * The wilaya-sync migration (2026_09_23_100003) can leave stray rows
     * with no `code` behind (unmatched/duplicate legacy rows) — the form
     * must never offer one of those as a choice.
     */
    #[Test]
    public function an_uncoded_stray_wilaya_row_is_not_offered_in_the_form(): void
    {
        CountryState::query()->create([
            'country_id' => $this->wilaya('01')->country_id,
            'external_id' => null,
            'code' => null,
            'name' => 'Stray Legacy Row',
            'ar_name' => null,
        ]);

        $props = $this->actingAs($this->admin())->get(route('players.create'))
            ->assertOk()->viewData('page')['props'];

        $this->assertCount(58, $props['wilayas']);
        $this->assertFalse(
            collect($props['wilayas'])->contains(fn ($w) => $w['name'] === 'Stray Legacy Row'),
            'the uncoded row must not be offered to the form'
        );
    }

    /**
     * The import lookup normalises the same way the migration backfill
     * does: case/accent/space-insensitive, and also accepts the bare
     * external_id (no leading zero), not just the zero-padded `code`.
     */
    #[Test]
    public function the_import_lookup_is_case_accent_and_space_insensitive_and_accepts_the_external_id(): void
    {
        $header = implode(',', array_fill(0, 20, 'h'));
        $row = function (string $wilaya, string $tag) {
            $cells = array_fill(0, 20, '');
            $cells[0] = 'Amine'.$tag;
            $cells[19] = $wilaya;

            return implode(',', $cells);
        };

        $csv = "\xEF\xBB\xBF".implode("\n", [
            $header,
            $row('GHARDAIA', 'a'),
            $row('ghardaia', 'b'),
            $row(' Ghardaïa ', 'c'),
            $row('7', 'd'), // external_id without leading zero -> Biskra (code 07)
        ])."\n";

        $this->actingAs($this->admin())->post(route('players.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('players.csv', $csv),
        ])->assertRedirect();

        $ghardaiaId = $this->wilaya('47')->id;
        $biskraId = $this->wilaya('07')->id;

        $this->assertSame($ghardaiaId, Player::where('firstname', 'Aminea')->value('wilaya_id'));
        $this->assertSame($ghardaiaId, Player::where('firstname', 'Amineb')->value('wilaya_id'));
        $this->assertSame($ghardaiaId, Player::where('firstname', 'Aminec')->value('wilaya_id'));
        $this->assertSame($biskraId, Player::where('firstname', 'Amined')->value('wilaya_id'));
    }

    /**
     * The backfill's normalise() must fold uppercase accented legacy values
     * (e.g. the old seed spellings written in caps) the same way it folds
     * plain ASCII typos, and must also resolve the extra southern-wilaya
     * aliases that differ from the official spelling by more than accents.
     */
    #[Test]
    public function the_backfill_matches_uppercase_accented_and_legacy_southern_spellings(): void
    {
        Player::create(['membership_id' => '202600030', 'firstname' => 'A', 'state' => 'SÉTIF']);
        Player::create(['membership_id' => '202600031', 'firstname' => 'B', 'state' => 'BÉJAÏA']);
        Player::create(['membership_id' => '202600032', 'firstname' => 'C', 'state' => 'GHARDAIA']);
        Player::create(['membership_id' => '202600033', 'firstname' => 'D', 'state' => 'El Menia']);
        Player::create(['membership_id' => '202600034', 'firstname' => 'E', 'state' => 'Bordj Baji Mokhtar']);

        $migration = require database_path('migrations/2026_09_23_100004_add_wilaya_id_to_players.php');
        $migration->backfillWilayaId();

        $this->assertSame($this->wilaya('19')->id, Player::where('membership_id', '202600030')->value('wilaya_id'), 'SÉTIF');
        $this->assertSame($this->wilaya('06')->id, Player::where('membership_id', '202600031')->value('wilaya_id'), 'BÉJAÏA');
        $this->assertSame($this->wilaya('47')->id, Player::where('membership_id', '202600032')->value('wilaya_id'), 'GHARDAIA');
        $this->assertSame($this->wilaya('58')->id, Player::where('membership_id', '202600033')->value('wilaya_id'), 'El Menia -> El Meniaa');
        $this->assertSame($this->wilaya('50')->id, Player::where('membership_id', '202600034')->value('wilaya_id'), 'Bordj Baji Mokhtar -> Bordj Badji Mokhtar');
    }

    /**
     * A state value that matches no wilaya is left alone (state untouched,
     * wilaya_id stays null) and reported once, in bulk, for the owner to
     * fix by hand — not thrown away or guessed at.
     */
    #[Test]
    public function the_backfill_reports_unmatched_state_values_without_touching_them(): void
    {
        Player::create(['membership_id' => '202600040', 'firstname' => 'F', 'state' => 'Not A Real Wilaya']);
        Player::create(['membership_id' => '202600041', 'firstname' => 'G', 'state' => 'Also Unknown']);

        Log::spy();

        $migration = require database_path('migrations/2026_09_23_100004_add_wilaya_id_to_players.php');
        $migration->backfillWilayaId();

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            return $message === 'Wilaya backfill: unmatched player.state values'
                && $context['count'] === 2
                && in_array('Not A Real Wilaya', $context['values'], true)
                && in_array('Also Unknown', $context['values'], true);
        });

        $this->assertNull(Player::where('membership_id', '202600040')->value('wilaya_id'));
        $this->assertSame('Not A Real Wilaya', Player::where('membership_id', '202600040')->value('state'));
        $this->assertNull(Player::where('membership_id', '202600041')->value('wilaya_id'));
        $this->assertSame('Also Unknown', Player::where('membership_id', '202600041')->value('state'));
    }

    /**
     * "Unknown" is the import's own placeholder for a blank wilaya cell, not
     * a mismatched spelling — it must not appear in the "please fix these"
     * list, but it must still be visible to the owner as a count so a
     * database full of never-set wilayas isn't silently invisible.
     */
    #[Test]
    public function the_backfill_excludes_the_unknown_placeholder_from_the_list_but_counts_it(): void
    {
        Player::create(['membership_id' => '202600050', 'firstname' => 'H', 'state' => 'Unknown']);
        Player::create(['membership_id' => '202600051', 'firstname' => 'I', 'state' => 'UNKNOWN']);
        Player::create(['membership_id' => '202600052', 'firstname' => 'J', 'state' => 'unknown']);
        Player::create(['membership_id' => '202600053', 'firstname' => 'K', 'state' => 'Truly Unmatched']);

        Log::spy();

        $migration = require database_path('migrations/2026_09_23_100004_add_wilaya_id_to_players.php');
        $migration->backfillWilayaId();

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            return $message === 'Wilaya backfill: unmatched player.state values'
                && $context['values'] === ['Truly Unmatched']
                && $context['count'] === 1
                && $context['placeholder_count'] === 3;
        });
    }

    /**
     * Only a coded (official) country_states row is a valid wilaya_id — a
     * stray/duplicate legacy row the wilaya-sync migration left uncoded must
     * be rejected the same way it's excluded from the form's choices.
     */
    #[Test]
    public function storing_a_player_against_an_uncoded_wilaya_row_fails_validation(): void
    {
        $stray = CountryState::query()->create([
            'country_id' => $this->wilaya('01')->country_id,
            'external_id' => null,
            'code' => null,
            'name' => 'Stray Legacy Row',
            'ar_name' => null,
        ]);

        $this->actingAs($this->admin())->post(route('players.store'), [
            'firstname' => 'Amine',
            'wilaya_id' => $stray->id,
        ])->assertSessionHasErrors('wilaya_id');

        $this->assertSame(0, Player::query()->count());
    }

    /**
     * The desktop build runs `migrate` on every boot. If up() ever partially
     * failed after the column-add DDL committed (SQLite doesn't roll back
     * DDL), the migration would stay unrecorded and every later boot would
     * re-run up() — which must not throw "duplicate column name" when the
     * column is already there.
     */
    #[Test]
    public function up_can_be_re_run_after_the_column_already_exists_without_throwing(): void
    {
        $migration = require database_path('migrations/2026_09_23_100004_add_wilaya_id_to_players.php');

        $migration->up();

        $this->assertTrue(Schema::hasColumn('players', 'wilaya_id'));
    }

    /**
     * Old 19-column files (and anyone who fills the template's legacy
     * "state" column, index 10, instead of the newer "wilaya" column,
     * appended at index 19) must still resolve a wilaya_id from the state
     * cell — not be left null just because the newer column is absent.
     */
    #[Test]
    public function a_legacy_19_column_row_resolves_the_wilaya_from_the_state_cell(): void
    {
        $row = function (string $state, string $tag) {
            $cells = array_fill(0, 19, '');
            $cells[0] = 'Amine'.$tag;
            $cells[10] = $state; // legacy "state" column, no "wilaya" cell at all

            return $cells;
        };

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_fill(0, 19, 'h'));
        fputcsv($fh, $row('الجزائر', 'a'));
        fputcsv($fh, $row('Ghardaïa', 'b'));
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $this->actingAs($this->admin())->post(route('players.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('players.csv', $csv),
        ])->assertRedirect();

        $this->assertSame($this->wilaya('16')->id, Player::where('firstname', 'Aminea')->value('wilaya_id'), 'الجزائر -> Alger (16)');
        $this->assertSame($this->wilaya('47')->id, Player::where('firstname', 'Amineb')->value('wilaya_id'), 'Ghardaïa -> 47');
    }

    /**
     * When a row carries both the legacy "state" cell and the newer
     * "wilaya" cell, the more specific "wilaya" cell wins.
     */
    #[Test]
    public function when_both_the_wilaya_and_legacy_state_cells_are_given_the_wilaya_cell_wins(): void
    {
        $cells = array_fill(0, 21, '');
        $cells[0] = 'Amine';
        $cells[10] = 'Ghardaïa'; // legacy state cell -> would resolve to 47
        $cells[19] = '16'; // wilaya cell -> must win, resolves to Alger (16)

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_fill(0, 21, 'h'));
        fputcsv($fh, $cells);
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $this->actingAs($this->admin())->post(route('players.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('players.csv', $csv),
        ])->assertRedirect();

        $this->assertSame($this->wilaya('16')->id, Player::query()->firstOrFail()->wilaya_id);
    }

    /**
     * A user typing a wilaya name by hand rarely matches the official
     * spelling letter-for-letter: 'الاغواط' (plain alef) for official
     * 'الأغواط' (hamza-on-alef), and 'عين الدفلي' (ya) for official
     * 'عين الدفلى' (alef maqsura). normalise() must fold both sides the
     * same way NameNormalizer folds job names, so these still match.
     */
    #[Test]
    public function normalise_folds_arabic_letter_variants_so_hand_typed_spellings_match_official_names(): void
    {
        $this->assertSame(WilayaMatcher::normalise('الأغواط'), WilayaMatcher::normalise('الاغواط'));
        $this->assertSame(WilayaMatcher::normalise('عين الدفلى'), WilayaMatcher::normalise('عين الدفلي'));
    }

    #[Test]
    public function the_import_resolves_hand_typed_arabic_spellings_that_only_differ_by_letter_form(): void
    {
        $row = function (string $wilaya, string $tag) {
            $cells = array_fill(0, 21, '');
            $cells[0] = 'Amine'.$tag;
            $cells[19] = $wilaya;

            return $cells;
        };

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_fill(0, 21, 'h'));
        fputcsv($fh, $row('الاغواط', 'a'));
        fputcsv($fh, $row('عين الدفلي', 'b'));
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $this->actingAs($this->admin())->post(route('players.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('players.csv', $csv),
        ])->assertRedirect();

        $this->assertSame($this->wilaya('03')->id, Player::where('firstname', 'Aminea')->value('wilaya_id'), 'الاغواط -> Laghouat (03)');
        $this->assertSame($this->wilaya('44')->id, Player::where('firstname', 'Amineb')->value('wilaya_id'), 'عين الدفلي -> Aïn Defla (44)');
    }

    /**
     * Folding Arabic letter variants must never make two different official
     * wilayas collide onto the same lookup key — every one of the 58
     * official Arabic names must still normalise to a distinct key.
     */
    #[Test]
    public function no_two_official_wilaya_names_collide_after_arabic_folding(): void
    {
        $names = require database_path('data/algeria_wilayas_official.php');

        $keys = collect($names)->map(fn ($entry) => WilayaMatcher::normalise($entry['ar']))->unique();

        $this->assertCount(58, $keys);
    }
}
