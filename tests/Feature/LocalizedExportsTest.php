<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTask;
use App\Models\Category;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Player\RegisterPlayerService;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class LocalizedExportsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $locale): User
    {
        // SetLocale reads the `lang` cookie first, then users.preferred_lng.
        return User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => $locale]);
    }

    private function sheet($response): string
    {
        ob_start();
        $response->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, ob_get_clean());
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    /** A label as the xlsx writer escapes it inside <t> (e.g. "N° d'adhésion" → &apos;). */
    private function xmlText(string $label): string
    {
        return htmlspecialchars($label, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function routes(): array
    {
        return [
            'players' => ['players.export', 'col.membership_id'],
            'transactions' => ['transactions.export', 'col.cash_register'],
            'catalogs' => ['equipment.catalogs.export', 'col.brand'],
            'board members' => ['board.members.export', 'col.term_start'],
            'board tasks' => ['board.tasks.export', 'col.due_date'],
        ];
    }

    #[Test]
    #[DataProvider('routes')]
    public function exports_default_to_xlsx_with_headers_in_the_users_language(string $route, string $headerKey): void
    {
        foreach (['ar', 'fr'] as $locale) {
            $response = $this->actingAs($this->admin($locale))->get(route($route));

            $response->assertOk();
            $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('content-type'));
            $this->assertStringContainsString($this->xmlText(UiLang::get($headerKey, null, $locale)), $this->sheet($response->baseResponse), "$route/$locale");
        }
    }

    #[Test]
    public function the_transactions_export_writes_localized_type_and_status_and_numeric_amounts(): void
    {
        $admin = $this->admin('fr');
        Transaction::create([
            'transaction_date' => '2026-01-15', 'transaction_type' => 'income', 'category' => 'other',
            'amount' => 1500, 'status' => 'Paid', 'payment_method' => 'bank', 'recorded_by_user_id' => $admin->id,
        ]);

        $xml = $this->sheet($this->actingAs($admin)->get(route('transactions.export'))->baseResponse);

        $this->assertStringContainsString($this->xmlText(UiLang::get('income', null, 'fr')), $xml);
        $this->assertStringContainsString($this->xmlText(UiLang::get('paid', null, 'fr')), $xml);
        $this->assertStringContainsString($this->xmlText(UiLang::get('bank_transfer', null, 'fr')), $xml);
        $this->assertMatchesRegularExpression('#<v>1500</v>#', $xml);
        $this->assertMatchesRegularExpression('#<c r="A\d+" s="2"><v>46037</v>#', $xml); // 2026-01-15 as a date cell
    }

    #[Test]
    public function format_csv_still_returns_a_bom_csv(): void
    {
        $response = $this->actingAs($this->admin('ar'))->get(route('players.export', ['format' => 'csv']));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
    }

    #[Test]
    public function the_players_export_localizes_the_player_type_and_ends_with_the_job(): void
    {
        $admin = $this->admin('fr');
        app(RegisterPlayerService::class)->handle(['firstname' => 'Amine', 'lastname' => 'Benali', 'join_year' => 2026, 'is_student' => true]);

        $csv = $this->actingAs($admin)->get(route('players.export', ['format' => 'csv']))->assertOk()->streamedContent();

        $this->assertStringContainsString(UiLang::get('student', null, 'fr'), $csv);
        $headerLine = explode("\r\n", $csv)[2] ?? ''; // BOM+title, blank spacer, then the headers
        $this->assertStringEndsWith(UiLang::get('col.job', null, 'fr'), rtrim($headerLine, ','), $headerLine);
    }

    #[Test]
    public function the_subscription_export_localizes_status_and_total_row(): void
    {
        $admin = $this->admin('fr');
        $subscription = Subscription::create(['name' => 'Saison', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 2000]);

        $csv = $this->actingAs($admin)->get(route('subscriptions.export', [$subscription, 'format' => 'csv']))->assertOk()->streamedContent();

        $this->assertStringContainsString(UiLang::get('col.total', null, 'fr'), $csv);
        $this->assertStringContainsString(UiLang::get('col.amount_owed', null, 'fr'), $csv);
        $this->assertStringContainsString(UiLang::get('all', null, 'fr'), $csv);
    }

    #[Test]
    public function the_subscription_export_survives_a_bad_filter_and_slashes_in_the_designation(): void
    {
        $admin = $this->admin('en');
        $subscription = Subscription::create(['name' => 'Saison A\\B/C', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 2000]);

        foreach (['a\\b', ['x'], '../x', 'paid'] as $filter) {
            $response = $this->actingAs($admin)->get(route('subscriptions.export', [$subscription, 'format' => 'csv', 'filter' => $filter]));

            $response->assertOk();
            $disposition = (string) $response->headers->get('content-disposition');
            $expected = $filter === 'paid' ? 'paid' : 'all';
            $this->assertStringContainsString('_'.$expected.'.csv', $disposition, json_encode($filter));
            $this->assertStringNotContainsString('\\', $disposition);
            $this->assertStringNotContainsString('/', $disposition);
            $this->assertStringContainsString('('.UiLang::get($expected, null, 'en').')', $response->streamedContent());
        }
    }

    #[Test]
    public function equipment_items_and_board_rows_carry_localized_codes_dates_and_numbers(): void
    {
        $admin = $this->admin('fr');
        $catalog = EquipmentCatalog::create(['name' => 'Balls', 'category' => 'Kits']);
        EquipmentItem::create([
            'catalog_id' => $catalog->id, 'unique_identifier' => 'IRNB-1', 'purchase_date' => '2026-01-15',
            'status' => 'Under Repair', 'condition' => 'Good', 'designation' => 'Ball',
        ]);
        $member = BoardMember::create(['name' => 'Karim', 'role' => 'president', 'status' => 'active', 'term_start' => '2026-01-15']);
        BoardTask::create(['title' => 'Plan', 'board_member_id' => $member->id, 'status' => 'in_progress', 'priority' => 'high', 'progress' => 40]);

        $items = $this->sheet($this->actingAs($admin)->get(route('equipment.items.export', $catalog))->baseResponse);
        $this->assertStringContainsString($this->xmlText(UiLang::get('under_repair', null, 'fr')), $items);
        $this->assertStringContainsString($this->xmlText(UiLang::get('good', null, 'fr')), $items);
        $this->assertMatchesRegularExpression('#s="2"><v>46037</v>#', $items);

        $members = $this->sheet($this->actingAs($admin)->get(route('board.members.export'))->baseResponse);
        $this->assertStringContainsString($this->xmlText(UiLang::get('president', null, 'fr')), $members);
        $this->assertStringContainsString($this->xmlText(UiLang::get('active', null, 'fr')), $members);
        $this->assertMatchesRegularExpression('#s="2"><v>46037</v>#', $members);

        $tasks = $this->sheet($this->actingAs($admin)->get(route('board.tasks.export'))->baseResponse);
        $this->assertStringContainsString($this->xmlText(UiLang::get('high', null, 'fr')), $tasks);
        $this->assertStringContainsString($this->xmlText(UiLang::get('in_progress', null, 'fr')), $tasks);
        $this->assertMatchesRegularExpression('#<v>40</v>#', $tasks);
    }

    #[Test]
    public function board_member_roles_export_the_custom_label_or_the_translated_built_in_name(): void
    {
        $admin = $this->admin('fr');
        BoardRole::create(['name' => 'kit_manager', 'label' => 'Responsable matériel']);
        BoardRole::create(['name' => 'unlabelled_role']);
        BoardMember::create(['name' => 'Karim', 'role' => 'president', 'status' => 'active']);
        BoardMember::create(['name' => 'Sami', 'role' => 'kit_manager', 'status' => 'active']);
        BoardMember::create(['name' => 'Nadia', 'role' => 'unlabelled_role', 'status' => 'active']);

        $xml = $this->sheet($this->actingAs($admin)->get(route('board.members.export'))->baseResponse);

        $this->assertStringContainsString($this->xmlText('Responsable matériel'), $xml);
        $this->assertStringNotContainsString('kit_manager', $xml);
        $this->assertStringContainsString($this->xmlText(UiLang::get('president', null, 'fr')), $xml);
        $this->assertStringContainsString('unlabelled_role', $xml); // no label, no key: the name, as t(r) shows it
    }

    #[Test]
    public function the_board_table_stays_pdf_by_default_and_offers_xlsx(): void
    {
        $admin = $this->admin('ar');
        $cadets = Category::create(['name' => 'Cadets']);
        app(RegisterPlayerService::class)->handle(['firstname' => 'Amine', 'lastname' => 'Benali', 'join_year' => 2026, 'category_id' => $cadets->id]);

        $this->actingAs($admin)->get(route('players.board-table', ['category_id' => $cadets->id]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            $this->xmlText(UiLang::get('col.membership_id', null, 'ar')),
            $this->sheet($this->actingAs($admin)->get(route('players.board-table', ['category_id' => $cadets->id, 'format' => 'xlsx']))->baseResponse),
        );
    }
}
