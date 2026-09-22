<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebsiteConfig;
use App\Support\ClubIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The app is sold to other clubs, so nothing may show one club's name to
 * another. A club's own name comes from Settings; until it is set, every
 * surface shows neutral defaults.
 */
class ClubIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function a_saved_short_name_wins(): void
    {
        $config = new WebsiteConfig(['club_short_name' => 'FCB']);

        $this->assertSame('FCB', ClubIdentity::shortName($config));
    }

    #[Test]
    public function a_missing_or_blank_short_name_falls_back_to_the_neutral_default(): void
    {
        $this->assertSame('Sports Club', ClubIdentity::shortName(null));
        $this->assertSame('Sports Club', ClubIdentity::shortName(new WebsiteConfig(['club_short_name' => null])));
        $this->assertSame('Sports Club', ClubIdentity::shortName(new WebsiteConfig(['club_short_name' => '   '])));
    }

    #[Test]
    public function a_saved_full_name_wins_and_keeps_only_the_locales_that_were_filled_in(): void
    {
        $config = new WebsiteConfig(['club_name' => ['ar' => 'نادي الأمل', 'fr' => '', 'en' => null]]);

        // Blank locales are dropped so the client falls back across the club's
        // real names before it ever reaches a generic default.
        $this->assertSame(['ar' => 'نادي الأمل'], ClubIdentity::name($config));
    }

    #[Test]
    public function a_club_with_no_name_at_all_gets_the_neutral_default_in_every_language(): void
    {
        $name = ClubIdentity::name(new WebsiteConfig(['club_name' => ['ar' => '', 'fr' => '', 'en' => '']]));

        $this->assertSame(ClubIdentity::DEFAULT_NAME, $name);
    }

    #[Test]
    public function the_arabic_default_is_actually_arabic(): void
    {
        // It used to be the English "Sports Club", so an Arabic club opened a
        // fresh install to a name in the wrong language.
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', ClubIdentity::DEFAULT_NAME['ar']);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', ClubIdentity::DEFAULT_TAGLINE['ar']);
    }

    #[Test]
    public function a_fresh_install_shares_neutral_names_with_every_page(): void
    {
        // No settings row exists yet: this is what a newly sold copy shows
        // before anyone opens Settings.
        $this->assertSame(0, WebsiteConfig::query()->count());

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('appShortName', 'Sports Club')
                ->where('appName.ar', ClubIdentity::DEFAULT_NAME['ar'])
                ->where('appName.en', 'Sports Club'));
    }

    #[Test]
    public function a_configured_club_sees_its_own_name_everywhere(): void
    {
        WebsiteConfig::singleton()->update([
            'club_short_name' => 'FCB',
            'club_name' => ['ar' => 'نادي برشلونة', 'fr' => 'FC Barcelone', 'en' => 'FC Barcelona'],
        ]);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('appShortName', 'FCB')
                ->where('appName.fr', 'FC Barcelone'));
    }

    #[Test]
    public function the_server_rendered_title_uses_the_club_name_not_the_technical_app_name(): void
    {
        // config('app.name') names the desktop app's data folder and reads
        // "SPORT_CLUB" on this install. It must never reach the browser tab.
        config(['app.name' => 'SPORT_CLUB']);
        WebsiteConfig::singleton()->update(['club_short_name' => 'FCB']);

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<title', $html);
        $this->assertStringNotContainsString('SPORT_CLUB', $html);
    }

    #[Test]
    public function clearing_the_short_name_in_settings_saves_instead_of_crashing(): void
    {
        // The rule said nullable while the column is NOT NULL: an emptied field
        // arrived as null (ConvertEmptyStringsToNull), passed validation, and
        // hit the database constraint as a server error.
        WebsiteConfig::singleton()->update(['club_short_name' => 'FCB']);

        $this->actingAs($this->admin())
            ->put(route('settings.update'), ['club_short_name' => ''])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // An emptied name means "no short name", so the neutral default shows.
        $this->assertSame('Sports Club', ClubIdentity::shortName(WebsiteConfig::singleton()));
    }

    #[Test]
    public function no_club_name_is_hardcoded_in_the_application(): void
    {
        // Two places may still say it: the one-off importer that brought this
        // club's data over from its previous system, and a code comment that
        // explains the two-product setup. Neither reaches a screen.
        $allowed = [
            'app/Console/Commands/ImportMongoJsonData.php',
            'app/Services/Backup/BackupService.php',
        ];

        $offenders = collect(['app', 'resources/js', 'resources/views'])
            ->flatMap(fn (string $dir) => File::allFiles(base_path($dir)))
            ->filter(fn ($file) => in_array($file->getExtension(), ['php', 'js', 'vue'], true))
            ->map(fn ($file) => str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1)))
            ->reject(fn (string $path) => in_array($path, $allowed, true))
            ->filter(fn (string $path) => preg_match('/\bIRNB\b/i', File::get(base_path($path))) === 1)
            ->values()
            ->all();

        $this->assertSame(
            [],
            $offenders,
            "A club name is hardcoded here — use App\\Support\\ClubIdentity or useClubIdentity() instead:\n"
            .implode("\n", $offenders),
        );
    }
}
