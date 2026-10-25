<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Pdf\PdfService;
use App\Services\Player\RegisterPlayerService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PlayersListPdfTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(string $firstname, string $lastname, ?Category $category = null, array $extra = []): Player
    {
        $player = app(RegisterPlayerService::class)->handle([
            'firstname' => $firstname,
            'lastname' => $lastname,
            'join_year' => 2026,
            'category_id' => $category?->id,
        ]);
        $player->forceFill($extra)->save();

        return $player;
    }

    #[Test]
    public function the_list_renders_as_a_pdf(): void
    {
        $this->player('Amine', 'Benali', null, ['birthdate' => '2010-03-04', 'health_blood_group_rhesus' => 'O+']);

        $response = $this->actingAs($this->admin())->get(route('players.print-list'))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_prints_the_filtered_players_with_their_four_columns(): void
    {
        $football = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swimming']);

        $ziani = $this->player('Yanis', 'Ziani', null, ['birthdate' => '2011-07-20', 'health_blood_group_rhesus' => 'A-']);
        $benali = $this->player('Amine', 'Benali', null, ['birthdate' => '2010-03-04', 'health_blood_group_rhesus' => 'O+']);
        $kaci = $this->player('Sami', 'Kaci');
        $football->players()->attach([$ziani->id, $benali->id]);
        $swim->players()->attach($kaci->id);

        $html = $this->capturePdfHtml();

        $this->actingAs($this->admin())
            ->get(route('players.print-list', ['branch_id' => [$football->id]]))
            ->assertOk();

        $html = $html();
        $this->assertStringContainsString('Football', $html, 'the branch heads the sheet');
        $this->assertStringContainsString('Benali', $html);
        $this->assertStringContainsString('Ziani', $html);
        $this->assertStringNotContainsString('Kaci', $html);
        $this->assertLessThan(strpos($html, 'Ziani'), strpos($html, 'Benali'), 'sorted by last name');
        $this->assertStringContainsString((string) $benali->membership_id, $html);
        $this->assertStringContainsString('04/03/2010', $html);
        $this->assertStringContainsString('O+', $html);
        $this->assertStringContainsString('A-', $html);
    }

    #[Test]
    public function the_header_always_reads_the_arabic_club_name_then_the_short_name(): void
    {
        WebsiteConfig::singleton()->update([
            'club_name' => ['ar' => 'الإتحاد الرياضي النوري البنوري', 'fr' => 'Union sportive', 'en' => 'Sports union'],
            'club_short_name' => 'IRNB',
        ]);
        app()->setLocale('fr');

        $html = $this->capturePdfHtml();

        $this->actingAs($this->admin())->get(route('players.print-list'))->assertOk();

        $this->assertStringContainsString('الإتحاد الرياضي النوري البنوري IRNB', $html());
        $this->assertStringNotContainsString('Union sportive', $html());
    }

    #[Test]
    public function printing_the_list_needs_only_players_view(): void
    {
        $role = Role::factory()->create(['permissions' => ['players' => ['view']]]);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->capturePdfHtml();

        $this->actingAs($viewer)->get(route('players.print-list'))->assertOk();
    }

    /** Records the HTML the controller hands to mPDF instead of rendering it. */
    private function capturePdfHtml(): Closure
    {
        $captured = new stdClass;
        $captured->html = '';

        $fake = new class($captured) extends PdfService
        {
            public function __construct(private stdClass $captured) {}

            public function stream(string $html, string $filename, bool $rtl = true, bool $landscape = false): Response
            {
                $this->captured->html = $html;

                return response('', 200, ['Content-Type' => 'application/pdf']);
            }
        };

        $this->app->instance(PdfService::class, $fake);

        return fn () => $captured->html;
    }
}
