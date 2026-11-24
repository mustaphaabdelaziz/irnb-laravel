<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\PlayerSubscription;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Pdf\PdfService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The subscription page prints the list it shows (tab, search, categories,
 * player statuses) as an A4 sheet laid out like the players list.
 */
class SubscriptionPrintListTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Subscription $sub;

    private Category $cadets;

    private Category $juniors;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sub = Subscription::create([
            'name' => 'Season card', 'year' => (int) now()->year,
            'amount_student' => 1000, 'amount_worker' => 1000, 'is_mandatory' => false, 'is_active' => true,
        ]);
        $this->cadets = Category::create(['name' => 'Cadets']);
        $this->juniors = Category::create(['name' => 'Juniors']);
    }

    private function assignee(string $lastname, ?Category $category, float $paid, ?PlayerStatus $status = null): Player
    {
        $player = Player::create([
            'membership_id' => '83'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Ali', 'lastname' => $lastname,
            'is_student' => true, 'outstanding_debt' => 0,
            'category_id' => $category?->id,
            'status_id' => $status?->id,
        ]);
        PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => $this->sub->id,
            'year' => $this->sub->year, 'status_at_time' => 'student', 'is_mandatory' => false,
            'amount_owed' => 1000, 'amount_paid' => $paid,
        ]);

        return $player;
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function it_prints_the_checked_categories_with_amounts_and_figures(): void
    {
        WebsiteConfig::singleton()->update([
            'club_name' => ['ar' => 'النادي الرياضي', 'fr' => 'Club sportif', 'en' => 'Sports club'],
            'club_short_name' => 'IRNB',
        ]);
        $amrani = $this->assignee('Amrani', $this->cadets, 1000);
        $this->assignee('Benali', $this->cadets, 400);
        $this->assignee('Chikhi', $this->cadets, 0);
        $this->assignee('Kaci', $this->juniors, 0);
        $this->assignee('Zitouni', null, 1000);

        $html = $this->capturePdfHtml();

        $response = $this->actingAs($this->admin())
            ->get(route('subscriptions.print-list', [$this->sub, 'category_id' => [$this->cadets->id]]))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $html = $html();
        $this->assertStringContainsString('النادي الرياضي IRNB', $html, 'the Arabic club name heads the sheet');
        $this->assertStringContainsString($this->sub->designation, $html, 'the subscription below the club name');
        $this->assertStringContainsString('Cadets', $html);
        $this->assertStringContainsString((string) $amrani->membership_id, $html);
        foreach (['Amrani', 'Benali', 'Chikhi'] as $name) {
            $this->assertStringContainsString($name, $html);
        }
        $this->assertStringNotContainsString('Kaci', $html);
        $this->assertStringNotContainsString('Zitouni', $html);
        $this->assertLessThan(strpos($html, 'Chikhi'), strpos($html, 'Benali'), 'A→Z');
        $this->assertStringContainsString('1,000.00', $html, 'amount owed');
        $this->assertStringContainsString('400.00', $html, 'paid');
        $this->assertStringContainsString('600.00', $html, 'remaining');

        // total 3 · paid 1 · partial 1 · unpaid 1 · 33.3 %
        $this->assertMatchesRegularExpression('/class="n">3<.*class="n">1<.*class="n">1<.*class="n">1<.*33\.3%/s', $html);
    }

    #[Test]
    public function the_tab_search_and_player_status_narrow_the_sheet(): void
    {
        $injured = PlayerStatus::create(['name' => 'Injured', 'sort_order' => 50, 'is_active' => true]);
        $this->assignee('Amrani', $this->cadets, 0, $injured);
        $this->assignee('Benali', $this->cadets, 0);
        $this->assignee('Chikhi', $this->juniors, 1000, $injured);

        $html = $this->capturePdfHtml();
        $this->actingAs($this->admin())
            ->get(route('subscriptions.print-list', [$this->sub, 'filter' => 'unpaid', 'status_id' => [$injured->id]]))
            ->assertOk();
        $this->assertStringContainsString('Amrani', $html());
        $this->assertStringNotContainsString('Benali', $html(), 'other status');
        $this->assertStringNotContainsString('Chikhi', $html(), 'paid, not in the unpaid tab');

        $html = $this->capturePdfHtml();
        $this->get(route('subscriptions.print-list', [$this->sub, 'status_id' => ['none'], 'search' => 'Benali']))->assertOk();
        $this->assertStringContainsString('Benali', $html());
        $this->assertStringNotContainsString('Amrani', $html());
    }

    #[Test]
    public function several_payment_statuses_print_together(): void
    {
        $this->assignee('Amrani', $this->cadets, 0);
        $this->assignee('Benali', $this->cadets, 400);
        $this->assignee('Chikhi', $this->cadets, 1000);

        $html = $this->capturePdfHtml();
        $this->actingAs($this->admin())
            ->get(route('subscriptions.print-list', [$this->sub, 'payment_status' => ['unpaid', 'partial']]))
            ->assertOk();

        $this->assertStringContainsString('Amrani', $html());
        $this->assertStringContainsString('Benali', $html());
        $this->assertStringNotContainsString('Chikhi', $html(), 'paid');

        $csv = $this->get(route('subscriptions.export', [$this->sub, 'format' => 'csv', 'payment_status' => ['paid']]))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('Chikhi', $csv);
        $this->assertStringNotContainsString('Amrani', $csv);
    }

    #[Test]
    public function the_export_follows_the_same_category_filter(): void
    {
        $this->assignee('Amrani', $this->cadets, 0);
        $this->assignee('Kaci', $this->juniors, 0);
        $this->assignee('Zitouni', null, 0);

        $csv = $this->actingAs($this->admin())
            ->get(route('subscriptions.export', [$this->sub, 'format' => 'csv', 'category_id' => [$this->juniors->id, 'none']]))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Kaci', $csv);
        $this->assertStringContainsString('Zitouni', $csv);
        $this->assertStringNotContainsString('Amrani', $csv);
    }

    #[Test]
    public function printing_needs_only_subscriptions_view(): void
    {
        $role = Role::factory()->create(['permissions' => ['subscriptions' => ['view']]]);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);
        $this->capturePdfHtml();

        $this->actingAs($viewer)->get(route('subscriptions.print-list', $this->sub))->assertOk();
    }

    #[Test]
    public function printing_is_refused_without_subscriptions_view(): void
    {
        $role = Role::factory()->create(['permissions' => ['players' => ['view']]]);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);
        $this->capturePdfHtml();

        $this->actingAs($viewer)->get(route('subscriptions.print-list', $this->sub))->assertForbidden();
    }

    #[Test]
    public function the_page_sends_each_row_status_and_the_status_names(): void
    {
        $injured = PlayerStatus::create(['name' => 'Injured', 'sort_order' => 50, 'is_active' => true]);
        $this->assignee('Amrani', $this->cadets, 0, $injured);

        $this->actingAs($this->admin())->get(route('subscriptions.show', $this->sub))->assertOk()
            ->assertInertia(function ($page) use ($injured) {
                $props = $page->toArray()['props'];
                $this->assertSame($injured->id, $props['playerSubscriptions'][0]['player']['status_id']);
                $this->assertContains($injured->id, array_column($props['playerStatuses'], 'id'));
            });
    }

    /** Records the HTML the controller hands to mPDF instead of rendering it. */
    private function capturePdfHtml(): Closure
    {
        $captured = new stdClass;
        $captured->html = '';

        $fake = new class($captured) extends PdfService
        {
            public function __construct(private stdClass $captured) {}

            public function stream(string $html, string $filename, ?bool $rtl = null, bool $landscape = false): Response
            {
                $this->captured->html = $html;

                return response('', 200, ['Content-Type' => 'application/pdf']);
            }
        };

        $this->app->instance(PdfService::class, $fake);

        return fn () => $captured->html;
    }
}
