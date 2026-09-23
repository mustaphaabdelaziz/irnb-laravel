<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Pdf\PdfService;
use App\Services\Player\RegisterPlayerService;
use App\Support\Season;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class BoardTablePdfTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(string $firstname, string $lastname, ?Category $category): Player
    {
        return app(RegisterPlayerService::class)->handle([
            'firstname' => $firstname,
            'lastname' => $lastname,
            'join_year' => 2026,
            'category_id' => $category?->id,
        ]);
    }

    #[Test]
    public function the_board_table_renders_for_one_category(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $this->player('Amine', 'Benali', $cadets);

        $response = $this->actingAs($this->admin())
            ->get(route('players.board-table', ['category_id' => $cadets->id]))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_lists_only_that_categorys_active_players_in_name_order(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $juniors = Category::create(['name' => 'Juniors']);

        $this->player('Yanis', 'Ziani', $cadets);
        $this->player('Amine', 'Benali', $cadets);
        $this->player('Sami', 'Kaci', $juniors);
        $archived = $this->player('Old', 'Member', $cadets);
        $archived->forceFill(['archived' => true])->save();

        $html = view('pdf.board-table', [
            'club' => ['name' => 'IRNB', 'logo' => null, 'address' => null, 'phone' => null, 'email' => null, 'currency' => 'DZD'],
            'category' => $cadets,
            'season' => Season::current(),
            'players' => Player::query()->where('category_id', $cadets->id)->where('archived', false)
                ->orderBy('lastname')->orderBy('firstname')->get(),
        ])->render();

        $this->assertStringContainsString('Benali', $html);
        $this->assertStringContainsString('Ziani', $html);
        $this->assertStringNotContainsString('Kaci', $html);
        $this->assertStringNotContainsString('Member', $html);
        $this->assertLessThan(strpos($html, 'Ziani'), strpos($html, 'Benali'), 'sorted by last name');
        $this->assertStringContainsString(Season::current()->label(), $html);
    }

    #[Test]
    public function the_route_lists_only_that_categorys_active_players_in_name_order(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $juniors = Category::create(['name' => 'Juniors']);

        $this->player('Yanis', 'Ziani', $cadets);
        $this->player('Amine', 'Benali', $cadets);
        $this->player('Sami', 'Kaci', $juniors);
        $archived = $this->player('Old', 'Member', $cadets);
        $archived->forceFill(['archived' => true])->save();

        $renderedHtml = $this->captureBoardTableHtml();

        $this->actingAs($this->admin())
            ->get(route('players.board-table', ['category_id' => $cadets->id]))
            ->assertOk();

        $html = $renderedHtml();

        $this->assertStringContainsString('Benali', $html);
        $this->assertStringContainsString('Ziani', $html);
        $this->assertStringNotContainsString('Kaci', $html);
        $this->assertStringNotContainsString('Member', $html);
        $this->assertLessThan(strpos($html, 'Ziani'), strpos($html, 'Benali'), 'sorted by last name');
        $this->assertStringContainsString(Season::current()->label(), $html);
    }

    #[Test]
    public function the_route_reflects_the_requested_season(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $this->player('Amine', 'Benali', $cadets);

        $renderedHtml = $this->captureBoardTableHtml();

        $this->actingAs($this->admin())
            ->get(route('players.board-table', ['category_id' => $cadets->id, 'season' => 2024]))
            ->assertOk();

        $this->assertStringContainsString(Season::forStartYear(2024)->label(), $renderedHtml());
    }

    /**
     * Binds a PdfService test double in place of the real one so the
     * controller's own query and view run untouched, but mPDF never gets to
     * compress the result into bytes we can't assert on — we just record the
     * HTML it was about to turn into a PDF.
     */
    private function captureBoardTableHtml(): Closure
    {
        $captured = new stdClass;
        $captured->html = '';

        $fake = new class($captured) extends PdfService
        {
            public function __construct(private stdClass $captured) {}

            public function stream(string $html, string $filename, bool $rtl = true): Response
            {
                $this->captured->html = $html;

                return response('', 200, ['Content-Type' => 'application/pdf']);
            }
        };

        $this->app->instance(PdfService::class, $fake);

        return fn () => $captured->html;
    }

    #[Test]
    public function an_unknown_category_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get(route('players.board-table', ['category_id' => 999999]))
            ->assertSessionHasErrors('category_id');
    }

    #[Test]
    public function printing_the_board_table_needs_only_players_view(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $role = Role::factory()->create(['permissions' => ['players' => ['view']]]);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('players.board-table', ['category_id' => $cadets->id]))
            ->assertOk();
    }

    /**
     * FileNumber::drawer() used to be called per row in the blade template,
     * and it queries WebsiteConfig::singleton() every time it isn't given an
     * explicit size — turning the board table into an N+1. The controller
     * must compute the drawer size once and hand it to the view, so the
     * query count stays flat as the row count grows.
     */
    #[Test]
    public function printing_the_board_table_queries_website_config_a_constant_number_of_times(): void
    {
        // Pre-warm the singleton row so both requests below hit the same
        // (row-already-exists) path — otherwise the very first touch of
        // website_configs pays for an extra firstOrCreate() insert that has
        // nothing to do with row count, and would mask what we're testing.
        WebsiteConfig::singleton();

        $small = Category::create(['name' => 'Small']);
        $this->player('Amine', 'Benali', $small);

        DB::enableQueryLog();
        $this->actingAs($this->admin())
            ->get(route('players.board-table', ['category_id' => $small->id]))
            ->assertOk();
        $countForOne = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'website_configs'))->count();
        DB::flushQueryLog();

        $big = Category::create(['name' => 'Big']);
        $this->player('B', 'Ziani', $big);
        $this->player('C', 'Kaci', $big);
        $this->player('D', 'Amrani', $big);

        $this->actingAs($this->admin())
            ->get(route('players.board-table', ['category_id' => $big->id]))
            ->assertOk();
        $countForThree = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'website_configs'))->count();
        DB::disableQueryLog();

        $this->assertSame($countForOne, $countForThree, 'website_configs must not be queried once per row');
    }
}
