<?php

namespace Tests\Feature;

use App\Models\CountryState;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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
}
