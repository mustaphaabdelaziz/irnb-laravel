# Help Center Part 2c — Finance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the `finance` help topic (7 Arabic articles with screenshots) and the demo finance data the screenshots need.

**Architecture:** Same Help Center engine as Parts 1, 2a and 2b (`resources/help/ar/<topic>/<slug>.md` → `HelpArticleParser` → `HelpLibrary` → Vue). A new `HelpDemoFinanceSeeder`, called last from the guarded `HelpDemoSeeder`, adds a club bank account, a closed previous season, hand-entered incomes/expenses, two transfers and budget lines through the app's own models, observer and `FinanceService`. Current-season dates are relative to the seed day. No framework change.

**Tech Stack:** Laravel 13, Inertia 2 + Vue 3, PHPUnit-style tests (`#[Test]`), Playwright screenshot script (`npm run help:shots`).

Spec: `docs/superpowers/specs/2026-11-08-help-center-part2-design.md` (phase 2c). References: `resources/help/README.md` (authoring guide), `resources/help/ar/subscriptions/*.md` and `resources/help/ar/attendance/*.md` (house style), `docs/superpowers/plans/2026-11-08-help-center-part2b-attendance.md` (previous phase, same task shapes).

## Global Constraints

- Offline only; no CDN. Screenshots in `public/help-shots/<locale>/` (never `public/help/` — it shadows the `/help` route).
- Help UI uses app tokens (`primary-*`, `dark:`), dark text `primary-300`; markers `amber-500`.
- Articles Arabic only; UI strings in ar/fr/en, flat dotted keys, insert-only (text edits, never re-serialise the JSON), no key segment named after a JS String method (`search`, `link`, `replace`, `split`, `match`…).
- Visibility: article `module:` else topic module. Topic `finance` → module `transactions`. Articles 1–3 use the topic module (`transactions`); articles 4–7 set `module: finance`. Rights per route (from `config/permissions.php` + `PermissionMap`): transactions list/show/export/template = transactions view; add + import = transactions add; edit = transactions edit; row «حذف» and bulk «حذف المحدد» = transactions delete (checkboxes show only with it); `/finance` dashboard and `/finance/registers` = finance view; transfer = finance add; add caisse = finance add, edit/deactivate = finance edit, delete = finance delete; `/finance/settings` (seasons, budget) = finance edit; season PDF (`reports.financial`) = **reports** view. Articles say which right a step needs when it is not view.
- Writing rules: simple MSA, singular imperative, ≤ 15 words a sentence, one action per step (outcomes, not extra clicks; filling adjacent fields of one form card is one step, as 2a `create-annual` «اكتب الاسم والسعرين»), every clicked element ringed, phone hint where a step relies on gestures/sidebar/shortcuts («في الهاتف، افتح القائمة أولًا بزر ☰», «في الهاتف، اسحب الشاشة بإصبعك إلى الأعلى»), touchpad alongside mouse wheel, exact labels from `resources/js/i18n/ar.json` in «…», no emoji standing in for app icons, tooltip-only text not quoted as visible (icon buttons: their name only appears on hover — describe the icon, quote the hover name as «… » only if the README allows it as in earlier articles), every fact checked in code, ⚠️ warning for irreversible actions, 3–7 steps, ≤ 1 tip, ≤ 1 warning, worked example followed through.
- Articles never quote a season's years («2026/2027»): say «الموسم الحالي» / «الموسم الماضي». Amounts are quoted with the app's formatting as it shows in the shots.
- Lessons from 2a/2b: walk each article literally against the component's watchers and defaults (e.g. the caisse select switches to the player's caisse when a player is chosen; type change filters categories); take each screenshot in the state the reader is in at that step; a step holds one action.
- The UI labels «حذف» / «حذف المحدد» actually archive: the entry leaves totals and balances, stays in the activity log, and **cannot be restored from the app**. Articles call it «حذف» (what the user sees) and say it cannot be undone.
- Never document, screenshot or run the finance reset (superadmin danger zone); a one-line mention at most.
- Demo data only in the throwaway screenshot DB: the new seeder calls `HelpDemoSeeder::assertThrowawayDatabase()` first.
- Git: stage explicit files only (main folder has unrelated untracked `.claude/`, `ASIRAM.png`, `HANDOFF.md`, `brag-output/`). Never `git add -A`.
- Tests: `php artisan test --filter=<Name>`; full suite only in the worktree without `bootstrap/cache/config.php`: `php -d memory_limit=1G vendor/bin/phpunit`.

## Demo facts (produced by Task 2, quoted by the articles)

Existing (HelpDemoSeeder / HelpDemoSubscriptionsSeeder / migrations): club cash account «صندوق النادي» (club-level, type cash, stored name `Cash`), the opening fund, coffres «خزينة …» per branch (Swimming, Football), caisse «صندوق أقل من 14 سنة» (Football, category «أقل من 14 سنة») holding the subscription payments of أحمد and ياسين; subscription payments dated the seed day.

New (Task 2):

| What | Value |
|---|---|
| Bank account | «الحساب البنكي للنادي», club-level, type bank, account number `00799999000123456789`, opening balance 0 |
| Previous season (dated from its start + 40 / 130 / 220 days) | income «منحة البلدية» «منح / إعانات» 150 000 → bank (method bank); expense «شراء بدلات رياضية» «معدات» 45 000 ← bank (bank); income «تبرع أحد المحسنين» «تبرعات» 15 000 → club cash (cash). Then **closed** by the demo user: closing balance 120 000, carried as the current season's opening balance |
| Current season hand entries (days before the seed day, clamped to the season start) | 48 income «منحة البلدية» GRT 200 000 bank/bank · 44 expense «شراء كرات وأقماع» EQP 18 000 Football coffre/cash · 38 expense «فاتورة الكهرباء» UTL 9 500 club cash/ccp · 37 the same «فاتورة الكهرباء» again (entered twice by mistake — the cancel article removes it) · 33 income «تبرع من مؤسسة محلية» DON 25 000 club cash/cash · 27 expense «أجرة مدرب السباحة» SAL 30 000 bank/bank · 22 income «مداخيل دورة الأحياء» EVR 16 000 Football coffre/cash · 18 expense «صيانة مضخة المسبح» MNT 14 000 bank/bank · 14 expense «لوازم الإسعافات الأولية» SUP 4 500 Football coffre/cash · 9 income «رسوم العضوية» MEM 6 000 club cash/cash · 6 expense «طباعة بطاقات اللاعبين» ADM 3 200 club cash/baridimob · 3 expense «نقل الفريق إلى مباراة خارج الديار» EVE 12 000 Football coffre/cash · 1 income «بيع قمصان النادي» OIN 8 000 club cash/cash |
| Transfers | 45 days before: bank → Football coffre 50 000, notes «تموين خزينة كرة القدم»; 10 days before: «صندوق أقل من 14 سنة» → Football coffre 6 000, notes «إيداع اشتراكات أقل من 14 سنة في الخزينة» |
| Budget, current season | income GRT 200 000, SUB 60 000; expense EQP 30 000, SAL 120 000, UTL 40 000 |
| Expected balances | bank 211 000; club cash 31 800; «صندوق أقل من 14 سنة» = its subscription receipts − 6 000 (expected 1 000: أحمد 3 000 + ياسين 4 000); Football coffre own cash = 31 500 + any subscription money the resolver sent there |
| Current season expense total | 100 700 (income = 255 000 hand entries + the subscription payments; the test pins the observed total) |

Caveats: if the seed day is in the first ~7 weeks of the season (September–mid-October with a September start), the current-season entries cluster on the season's first day; regenerate shots later in the season. Ids are pinned by the seeder test and reported by Task 2 for `shots.js`.

## File map

| File | Responsibility |
|---|---|
| `resources/help/topics.php` (modify) | add `finance` topic |
| `resources/js/i18n/{ar,fr,en}.json` (modify) | `help.topic.finance` |
| `database/seeders/HelpDemoFinanceSeeder.php` (create) | bank account, previous season + close, entries, transfers, budget |
| `database/seeders/HelpDemoSeeder.php` (modify) | call the new seeder last |
| `tests/Feature/Help/HelpDemoFinanceSeederTest.php` (create) | pins the demo facts above |
| `resources/js/Pages/Transactions/*.vue`, `Partials/TransactionForm.vue`, `Pages/Finance/*.vue`, `Components/Finance/*.vue` (modify) | `data-help` attributes only |
| `resources/help/shots.js` (modify) | new shots |
| `resources/help/ar/finance/*.md` (create) | 7 articles |
| `public/help-shots/ar/**` (generated) | screenshots + boxes |

---

### Task 1: `finance` topic

**Files:**
- Modify: `resources/help/topics.php`
- Modify: `resources/js/i18n/ar.json`, `fr.json`, `en.json`

**Interfaces:**
- Produces: topic `finance` (icon `money`, module `transactions`, article order `record-transaction, find-export-import, cancel-transaction, move-money, caisses, season, dashboard-report`); i18n key `help.topic.finance`.

- [ ] **Step 1: Add the topic** — in `resources/help/topics.php`, after the `attendance` line:

```php
        'finance' => ['icon' => 'money', 'module' => 'transactions', 'articles' => ['record-transaction', 'find-export-import', 'cancel-transaction', 'move-money', 'caisses', 'season', 'dashboard-report']],
```

Check that `money` renders on the help topic tiles (grep the icon component used by `resources/js/Pages/Help/Index.vue`; the sidebar uses `money` for «المالية» in `resources/js/Composables/useNavigation.js:36`). If it does not, use an icon the tiles render and record the change.

Check how `HelpLibrary` treats an article whose `module` differs from its topic's (spec: "articles override"): a user with finance view but no transactions view must still see articles 4–7. If the topic tile/listing hides them because the topic module is `transactions`, stop and report (do not change the framework in this task).

- [ ] **Step 2: i18n** — insert right after `help.topic.attendance` in each file (text edit):

| key | ar | fr | en |
|---|---|---|---|
| `help.topic.finance` | المالية | Finances | Finance |

- [ ] **Step 3: Run** `php artisan test --filter=Help` (all pass) and `npm run i18n:check`.

- [ ] **Step 4: Commit**

```bash
git add resources/help/topics.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(help): finance topic"
```

---

### Task 2: Demo finance seeder

**Files:**
- Create: `database/seeders/HelpDemoFinanceSeeder.php`
- Modify: `database/seeders/HelpDemoSeeder.php` (call it after `HelpDemoAttendanceSeeder`)
- Test: `tests/Feature/Help/HelpDemoFinanceSeederTest.php`

**Interfaces:**
- Consumes: `HelpDemoSeeder::assertThrowawayDatabase()`, `HelpDemoSeeder::USERNAME`, `HelpDemoSubscriptionsSeeder::CAISSE`; models `FinanceAccount` (fillable `branch_id, category_id, parent_account_id, name, type, is_treasury, is_opening_fund, account_number, opening_balance, current_balance, currency, is_active, sort_order, notes`), `FinanceCategory` (`type`, `code`, `name`), `Transaction` (+ `TransactionObserver`: sets `fiscal_year`/`fiscal_year_id`, firstOrCreates the open `FiscalYear`, recomputes balances and year totals on save), `FinanceTransfer` (`from_account_id, to_account_id, amount, transfer_date, reference, notes, created_by_user_id`), `FiscalYear` (`year` = season key), `Budget` (`fiscal_year_id, finance_category_id, planned_amount, notes`); `FinanceService::recomputeAccountBalances(): void`, `FinanceService::closeYear(FiscalYear $fy, User $user): FiscalYear`, `FinanceService::ownBalance(FinanceAccount): float`, `FinanceService::yearTotals(int $year): array`; `App\Support\Season::current()->start()` (CarbonImmutable), `Season::forStartYear(int)`; `App\Support\FiscalPeriod::keyForDate($date): int`, `FiscalPeriod::currentKey(): int`; `TransactionController::store` sets `category = Str::slug($financeCategory->name, '_')` — mirror it.
- Produces: constants `HelpDemoFinanceSeeder::BANK = 'الحساب البنكي للنادي'`, `BANK_RIB = '00799999000123456789'`, `DUPLICATE = 'فاتورة الكهرباء'`, `OPENING = 120000`; the demo facts table above; the ids (bank account, Football coffre, club cash, U14 caisse, the two «فاتورة الكهرباء» transactions, the previous/current `FiscalYear`) written into the task report for later shots.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Help/HelpDemoFinanceSeederTest.php`:

```php
<?php

namespace Tests\Feature\Help;

use App\Models\Budget;
use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Services\FinanceService;
use App\Support\FiscalPeriod;
use Database\Seeders\HelpDemoFinanceSeeder as Demo;
use Database\Seeders\HelpDemoSeeder;
use Database\Seeders\HelpDemoSubscriptionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoFinanceSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mid-season seed day so no current-season entry is clamped to the season start.
        $this->travelTo('2026-11-18 10:00:00');
        $this->seed(HelpDemoSeeder::class);
    }

    #[Test]
    public function the_bank_account_exists_and_holds_the_expected_balance(): void
    {
        $bank = FinanceAccount::where('name', Demo::BANK)->firstOrFail();
        $this->assertSame('bank', $bank->type);
        $this->assertNull($bank->branch_id);
        $this->assertSame(Demo::BANK_RIB, $bank->account_number);
        $this->assertEqualsWithDelta(211000, (float) $bank->current_balance, 0.01);
    }

    #[Test]
    public function the_previous_season_is_closed_and_carries_its_balance(): void
    {
        $current = FiscalPeriod::currentKey();
        $previous = FiscalYear::where('year', $current - 1)->firstOrFail();
        $this->assertSame('closed', $previous->status);
        $this->assertEqualsWithDelta(Demo::OPENING, (float) $previous->closing_balance, 0.01);

        $now = FiscalYear::where('year', $current)->firstOrFail();
        $this->assertSame('open', $now->status);
        $this->assertEqualsWithDelta(Demo::OPENING, (float) $now->opening_balance, 0.01);
    }

    #[Test]
    public function current_season_entries_transfers_and_budget_are_seeded(): void
    {
        $key = FiscalPeriod::currentKey();
        $this->assertSame(2, Transaction::where('title', Demo::DUPLICATE)->where('archived', false)->count());
        $this->assertSame(2, FinanceTransfer::count());
        $this->assertSame(5, Budget::whereHas('fiscalYear', fn ($q) => $q->where('year', $key))->count());

        $totals = app(FinanceService::class)->yearTotals($key);
        $this->assertEqualsWithDelta(100700, (float) $totals['expense'], 0.01);
    }

    #[Test]
    public function caisse_balances_match_the_demo_facts(): void
    {
        $cash = FinanceAccount::whereNull('branch_id')->where('type', 'cash')
            ->where('is_opening_fund', false)->orderBy('id')->firstOrFail();
        $this->assertEqualsWithDelta(31800, (float) $cash->current_balance, 0.01);

        $u14 = FinanceAccount::where('name', HelpDemoSubscriptionsSeeder::CAISSE)->firstOrFail();
        $this->assertEqualsWithDelta(1000, (float) $u14->current_balance, 0.01);
    }
}
```

Adjust the `yearTotals` array key and the `Budget::fiscalYear` relation name to what the code really has (read `FinanceService::yearTotals` and `Budget`). If an expected number differs once the seeder runs, find out why (e.g. a subscription payment landed in another caisse) before changing it, and record the real number and the reason in the report — the articles quote these.

- [ ] **Step 2: Run it** — `php artisan test --filter=HelpDemoFinanceSeederTest` → FAIL (class `HelpDemoFinanceSeeder` not found).

- [ ] **Step 3: Write the seeder** — `database/seeders/HelpDemoFinanceSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Budget;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransfer;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\FiscalPeriod;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Finance for the Help Center demo club. Called only from the guarded
 * HelpDemoSeeder, after the subscription payments. Entries go through
 * Transaction + TransactionObserver and FinanceService, so balances, season
 * totals and the carried opening balance are the app's own numbers.
 */
class HelpDemoFinanceSeeder extends Seeder
{
    public const BANK = 'الحساب البنكي للنادي';

    public const BANK_RIB = '00799999000123456789';

    public const DUPLICATE = 'فاتورة الكهرباء';

    /** The previous season's net (150 000 − 45 000 + 15 000), carried to the current one. */
    public const OPENING = 120000;

    /** Previous season: [days after its start, type, category code, title, amount, account, method]. */
    private const PREVIOUS = [
        [40, 'income', 'GRT', 'منحة البلدية', 150000, 'bank', 'bank'],
        [130, 'expense', 'EQP', 'شراء بدلات رياضية', 45000, 'bank', 'bank'],
        [220, 'income', 'DON', 'تبرع أحد المحسنين', 15000, 'cash', 'cash'],
    ];

    /** Current season: [days before the seed day, type, category code, title, amount, account, method]. */
    private const CURRENT = [
        [48, 'income', 'GRT', 'منحة البلدية', 200000, 'bank', 'bank'],
        [44, 'expense', 'EQP', 'شراء كرات وأقماع', 18000, 'football', 'cash'],
        [38, 'expense', 'UTL', self::DUPLICATE, 9500, 'cash', 'ccp'],
        // Entered twice by mistake: the cancel-transaction article removes this one.
        [37, 'expense', 'UTL', self::DUPLICATE, 9500, 'cash', 'ccp'],
        [33, 'income', 'DON', 'تبرع من مؤسسة محلية', 25000, 'cash', 'cash'],
        [27, 'expense', 'SAL', 'أجرة مدرب السباحة', 30000, 'bank', 'bank'],
        [22, 'income', 'EVR', 'مداخيل دورة الأحياء', 16000, 'football', 'cash'],
        [18, 'expense', 'MNT', 'صيانة مضخة المسبح', 14000, 'bank', 'bank'],
        [14, 'expense', 'SUP', 'لوازم الإسعافات الأولية', 4500, 'football', 'cash'],
        [9, 'income', 'MEM', 'رسوم العضوية', 6000, 'cash', 'cash'],
        [6, 'expense', 'ADM', 'طباعة بطاقات اللاعبين', 3200, 'cash', 'baridimob'],
        [3, 'expense', 'EVE', 'نقل الفريق إلى مباراة خارج الديار', 12000, 'football', 'cash'],
        [1, 'income', 'OIN', 'بيع قمصان النادي', 8000, 'cash', 'cash'],
    ];

    /** Current season budget: category code => planned amount. */
    private const BUDGET = ['GRT' => 200000, 'SUB' => 60000, 'EQP' => 30000, 'SAL' => 120000, 'UTL' => 40000];

    private User $demo;

    public function run(): void
    {
        HelpDemoSeeder::assertThrowawayDatabase();

        $this->demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();
        $football = Branch::where('name', 'Football')->firstOrFail();

        $accounts = [
            'bank' => FinanceAccount::create([
                'branch_id' => null,
                'name' => self::BANK,
                'type' => 'bank',
                'account_number' => self::BANK_RIB,
                'opening_balance' => 0,
                'current_balance' => 0,
                'currency' => 'DZD',
                'is_treasury' => false,
                'is_active' => true,
            ]),
            'cash' => FinanceAccount::whereNull('branch_id')->where('type', 'cash')
                ->where('is_opening_fund', false)->orderBy('id')->firstOrFail(),
            'football' => $football->treasury()->firstOrFail(),
        ];

        $today = CarbonImmutable::today();
        $seasonStart = Season::current()->start();
        $previousStart = Season::forStartYear($seasonStart->year - 1)->start();

        foreach (self::PREVIOUS as [$days, $type, $code, $title, $amount, $account, $method]) {
            $this->entry($previousStart->addDays($days), $type, $code, $title, $amount, $accounts[$account], $method);
        }
        $finance = app(FinanceService::class);
        $finance->closeYear(FiscalYear::where('year', FiscalPeriod::keyForDate($previousStart))->firstOrFail(), $this->demo);

        foreach (self::CURRENT as [$days, $type, $code, $title, $amount, $account, $method]) {
            $this->entry($today->subDays($days)->max($seasonStart), $type, $code, $title, $amount, $accounts[$account], $method);
        }

        $u14 = FinanceAccount::where('name', HelpDemoSubscriptionsSeeder::CAISSE)->firstOrFail();
        $this->transfer($accounts['bank'], $accounts['football'], 50000, $today->subDays(45)->max($seasonStart), 'تموين خزينة كرة القدم');
        $this->transfer($u14, $accounts['football'], 6000, $today->subDays(10)->max($seasonStart), 'إيداع اشتراكات أقل من 14 سنة في الخزينة');
        $finance->recomputeAccountBalances();

        $year = FiscalYear::where('year', FiscalPeriod::currentKey())->firstOrFail();
        foreach (self::BUDGET as $code => $planned) {
            Budget::create([
                'fiscal_year_id' => $year->id,
                'finance_category_id' => $this->category($code)->id,
                'planned_amount' => $planned,
            ]);
        }
    }

    private function entry(CarbonImmutable $date, string $type, string $code, string $title, int $amount, FinanceAccount $account, string $method): Transaction
    {
        $category = $this->category($code);

        return Transaction::create([
            'title' => $title,
            'amount' => $amount,
            'transaction_date' => $date->toDateString(),
            'transaction_type' => $type,
            'finance_category_id' => $category->id,
            // As TransactionController::store.
            'category' => Str::slug($category->name, '_'),
            'payment_method' => $method,
            'finance_account_id' => $account->id,
            'status' => 'Paid',
            'recorded_by_user_id' => $this->demo->id,
        ]);
    }

    private function transfer(FinanceAccount $from, FinanceAccount $to, int $amount, CarbonImmutable $date, string $notes): void
    {
        FinanceTransfer::create([
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => $amount,
            'transfer_date' => $date->toDateString(),
            'notes' => $notes,
            'created_by_user_id' => $this->demo->id,
        ]);
    }

    private function category(string $code): FinanceCategory
    {
        return FinanceCategory::where('code', $code)->firstOrFail();
    }
}
```

Verify before running: `Branch::treasury()` exists (used by `HelpDemoSubscriptionsSeeder`); `FinanceCategory` codes are unique across types (if not, add `->where('type', $type)`); the previous season's `FiscalYear` row exists after the three entries (observer); `closeYear` writes the current season's opening balance (it upserts key + 1). `CarbonImmutable::max()` returns the later date — confirm the method name in the installed Carbon.

- [ ] **Step 4: Call it** — in `HelpDemoSeeder::run()`, after `$this->call(HelpDemoAttendanceSeeder::class);`:

```php
        $this->call(HelpDemoFinanceSeeder::class);
```

- [ ] **Step 5: Run** `php artisan test --filter=HelpDemo` → all pass (the subscriptions and attendance seeder tests too).

- [ ] **Step 6: Seed the screenshot DB once** (`npm run help:shots -- login` reseeds it) and open `/finance`, `/finance/registers`, `/transactions` in the demo to check nothing is negative or odd. Write the ids listed under "Produces" into the report.

- [ ] **Step 7: Commit**

```bash
git add database/seeders/HelpDemoFinanceSeeder.php database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoFinanceSeederTest.php
git commit -m "feat(help): demo finance accounts, seasons, entries and budget"
```

---

### Task 3: Articles 1–2 — record a transaction; find, export, import

**Files:**
- Create: `resources/help/ar/finance/record-transaction.md`, `find-export-import.md`
- Modify: `data-help` attributes in `resources/js/Pages/Transactions/Index.vue`, `Create.vue`, `Show.vue`, `Partials/TransactionForm.vue`; `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify in code before writing: `StoreTransactionRequest` (title required ≤ 150, category must match the type, amount ≥ 0, caisse optional), `TransactionForm.vue` defaults (type «مداخيل», method «نقدي», status «مدفوع», date today, caisse = club default; the caisse label switches between «الصندوق المستلم للإيراد» and «الصندوق الذي يدفع المصروف»; changing the type filters the categories; choosing a player switches the caisse), CCP/bank extra fields, attachment «الإيصال» (image or PDF ≤ 10 MB), `store` redirects to the entry's page with «تم إنشاء المعاملة بنجاح.», closed season → «الموسم {season} مغلق. أعد فتحه لتعديل معاملاته.». List: default = current season; filters `search`, type, category, caisse, season, from/to dates; «مسح الفلاتر»; export via «إجراءات إضافية» → «تصدير» → «Excel (.xlsx)» / «CSV» keeps the filters; import (add right) via «إجراءات إضافية» → «النموذج» download, then «استيراد» picks the file (`.xlsx`/`.csv`), columns = template headers, date `YYYY-MM-DD` or `dd/mm/yyyy`, unknown caisse → default caisse with a warning, **no duplicate check**, flash «تم استيراد :count معاملة بنجاح.».

**Outlines** (topic module `transactions`):
1. `record-transaction.md` «كيف أسجّل مدخولًا أو مصروفًا» — pages `[transactions.index, transactions.create, transactions.show]`; example: the coach buys goal nets: expense «شراء شباك المرمى», «معدات», 15 000, paid in cash from Football's coffre. Steps: sidebar «المالية» → «المعاملات» (phone hint) → «إضافة معاملة» → «النوع» «مصاريف» → title, «الفئة», «المبلغ» → «الصندوق الذي يدفع المصروف» + «طريقة الدفع» → «حفظ» → the entry's page (flash; print buttons). Tip: for money from a player choose him in «لاعب» — but a subscription payment is taken from the player page (link `subscriptions/take-payment` in `related`). Mention: needs the add right; a closed season refuses the date.
2. `find-export-import.md` «كيف أبحث في المعاملات وأصدّرها وأستوردها» — pages `[transactions.index]`; example: find this season's electricity bills, export them to Excel; import the year's old notebook from the template. Steps: «المعاملات» → type «كهرباء» in the search → filter «النوع»/«كل الصناديق»/season (season default = current) → «إجراءات إضافية» → «تصدير» → «Excel (.xlsx)» → for import: «النموذج» → «Excel (.xlsx)», fill it → «استيراد» → choose the file. ⚠️ Warning: importing the same file twice records every line twice (no duplicate check). Mention: import needs the add right.

**data-help targets (attribute only; grep each, confirm it lands on a real DOM element, not a component root that drops attrs):**
- `Index.vue`: existing `tx-search`, `tx-row-first`; add `tx-add` («إضافة معاملة»), `tx-filters` (wrapper of the selects + dates), `tx-filter-type`, `tx-filter-caisse`, `tx-filter-season`, `tx-more` («إجراءات إضافية» button), `tx-export` (the export section/links in the menu), `tx-template` (template links), `tx-import` (the «استيراد» item).
- `TransactionForm.vue`: `txf-type`, `txf-title`, `txf-category`, `txf-amount`, `txf-date`, `txf-caisse`, `txf-method`, `txf-player`, `txf-save` (in `Create.vue` if the button lives there).
- `Show.vue`: existing `tx-print-ticket`, `tx-print-a4`; add `tx-show-amount` (amount/summary card) if the result step needs it.

**Shots** (append to `resources/help/shots.js` under `// --- Finance --- (HelpDemoFinanceSeeder)`; ids from the Task 2 report):

```js
    { name: 'fin-tx-list', url: '/transactions', targets: ['tx-add', 'tx-search', 'tx-filters', 'tx-more'] },
    {
        // The reader's state before «حفظ»: an expense typed in, caisse and method chosen.
        name: 'fin-tx-form',
        url: '/transactions/create',
        targets: ['txf-type', 'txf-title', 'txf-category', 'txf-amount', 'txf-caisse', 'txf-method', 'txf-save'],
        before: async (page) => {
            await page.selectOption('[data-help="txf-type"]', 'expense');
            await page.fill('[data-help="txf-title"]', 'شراء شباك المرمى');
            // category «معدات», amount 15000, caisse = Football coffre: by label/value from the page
        },
    },
    {
        name: 'fin-tx-search',
        url: '/transactions?search=' + encodeURIComponent('كهرباء'),
        targets: ['tx-search', 'tx-row-first'],
    },
    {
        name: 'fin-tx-menu',
        url: '/transactions',
        targets: ['tx-export', 'tx-template', 'tx-import'],
        before: async (page) => { await page.click('[data-help="tx-more"]'); },
    },
```

Add a result shot of the saved entry's page (`fin-tx-saved`: submit the form in `before`, wait for the flash, target the print buttons) — the seeder must stay untouched, so the shot DB gains one row; that is fine because shots run after seeding, but keep it the **last** finance shot that writes, or reseed-independent (check `scripts/help-shots.mjs` order: shots run in list order on one DB). Use real selectors for the searchable/custom selects (inspect the components). Run `npm run build && npm run help:shots -- fin-tx-list fin-tx-form …` (or the full run, restoring unchanged older images afterwards); view every image light + dark; rings on target.

- [ ] **Step 1:** data-help attributes; `npm run build`.
- [ ] **Step 2:** shots; generate; view images (light and dark).
- [ ] **Step 3:** write the 2 articles.
- [ ] **Step 4:** `php artisan help:check` (OK), `php artisan test --filter=Help`.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/finance/record-transaction.md resources/help/ar/finance/find-export-import.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Transactions/Index.vue resources/js/Pages/Transactions/Partials/TransactionForm.vue
git commit -m "docs(help): record-transaction and find/export/import articles"
```

(Stage only the files you changed; add `Create.vue`/`Show.vue` if touched.)

---

### Task 4: Articles 3–4 — cancel a transaction; move money

**Files:**
- Create: `resources/help/ar/finance/cancel-transaction.md`, `move-money.md`
- Modify: `data-help` in `resources/js/Pages/Transactions/Index.vue`, `resources/js/Pages/Finance/Registers.vue`, `resources/js/Components/Finance/MoveMoneyModal.vue`; `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify: row «حذف» (trash icon) → confirm «حذف هذه المعاملة؟» «لن تُحتسب بعد الآن في المجاميع ولا في رصيد الصندوق، وتبقى في سجل النشاط.» → flash «تمت أرشفة المعاملة بنجاح.»; bulk: checkboxes (delete right only), bar «{count} محدد» with «إلغاء» / «حذف المحدد» → «حذف العمليات المحددة؟» «العمليات المحددة: {count}. سيتم تجاوز ما ينتمي منها إلى موسم مغلق.» → «تم حذف {count} معاملة.» (or «…، وتم تجاوز {skipped} (سنة مالية مغلقة).»); max 500 at once; no restore anywhere; a single entry in a closed season is refused. A subscription payment is better cancelled from the player page (2a `fix-payment`) — check whether deleting it here also updates the player's debt (`bulkDestroy` recomputes debts; single `destroy` goes through the observer) and say only what is true. Transfers: `/finance/registers`, «نقل الأموال» header button (only with > 1 active account) and per-account arrows; modal fields from / to / amount / date (today) / «ملاحظات»; preview «بعد النقل»; «لا يوجد مال كافٍ هنا.»; cannot move into the opening fund; flash «تم تحويل الأموال بنجاح.»; history «حركة الأموال»; transfers change balances, never income/expense totals.

**Outlines:**
3. `cancel-transaction.md` «كيف أحذف معاملة أو عدة معاملات» — pages `[transactions.index, transactions.show]`; example: «فاتورة الكهرباء» was entered twice — delete the second one. Steps: «المعاملات» → search «كهرباء» → on the extra row press «حذف» → read the message, press «حذف» → (many) tick the rows → «حذف المحدد» → confirm. ⚠️ Warning: deleting cannot be undone from the app; the entry leaves totals and balances and stays only in the activity log. Mention: needs the delete right; entries of a closed season are not deleted.
4. `move-money.md` «كيف أنقل المال بين الصناديق» — pages `[finance.registers.index]`; example: the U14 coach hands the 1 000 left in «صندوق أقل من 14 سنة» to the Football coffre. Steps: sidebar «المالية» → «الصناديق النقدية» (phone hint) → «نقل الأموال» → from / to → «المبلغ» + «ملاحظات» → check «بعد النقل» → save → the move appears in «حركة الأموال». Tip: a transfer changes where the money is, not the season's income or expenses. Mention: needs the finance add right.

**data-help targets:** `Index.vue`: `tx-select-first` (first row checkbox), `tx-delete-first` (first row «حذف» button — or target the row by title in shots), `tx-bulk-bar`, `tx-bulk-delete`; the confirm modal's buttons via `ConfirmModal` if it forwards attrs (else target the modal panel with a wrapper attribute in `Index.vue`). `Registers.vue`: `reg-move` (header «نقل الأموال»), `reg-add` («إضافة صندوق»), `reg-tiles`, `reg-history`, `reg-branch-football` (Football card), `reg-caisse-first` (first caisse row), `reg-edit-caisse-first`. `MoveMoneyModal.vue`: `move-from`, `move-to`, `move-amount`, `move-notes`, `move-after`, `move-save`.

**Shots:**

```js
    {
        name: 'fin-tx-duplicate',
        url: '/transactions?search=' + encodeURIComponent('كهرباء'),
        targets: ['tx-delete-first'],
    },
    {
        name: 'fin-tx-delete-confirm',
        url: '/transactions?search=' + encodeURIComponent('كهرباء'),
        targets: [/* confirm panel + its «حذف» button */],
        before: async (page) => { await page.click('[data-help="tx-delete-first"]'); },
    },
    {
        name: 'fin-tx-bulk',
        url: '/transactions?search=' + encodeURIComponent('كهرباء'),
        targets: ['tx-bulk-bar', 'tx-bulk-delete'],
        before: async (page) => { await page.click('[data-help="tx-select-first"]'); },
    },
    { name: 'fin-registers', url: '/finance/registers', targets: ['reg-tiles', 'reg-move', 'reg-branch-football', 'reg-history'] },
    {
        name: 'fin-move-modal',
        url: '/finance/registers',
        targets: ['move-from', 'move-to', 'move-amount', 'move-notes', 'move-after', 'move-save'],
        before: async (page) => {
            await page.click('[data-help="reg-move"]');
            // from = «صندوق أقل من 14 سنة», to = Football coffre, amount 1000, notes «تسليم مداخيل الاشتراكات»
        },
    },
```

Never press the final confirm/save in these shots (the screenshot DB must keep the duplicate for the cancel shots; if a shot must show a result, put it after every shot that needs the data, and say so in a comment). Generate, view every image.

- [ ] **Step 1:** data-help; build.
- [ ] **Step 2:** shots; generate; view.
- [ ] **Step 3:** write the 2 articles.
- [ ] **Step 4:** `help:check`, `test --filter=Help`.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/finance/cancel-transaction.md resources/help/ar/finance/move-money.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Transactions/Index.vue resources/js/Pages/Finance/Registers.vue resources/js/Components/Finance/MoveMoneyModal.vue
git commit -m "docs(help): cancel-transaction and move-money articles"
```

---

### Task 5: Articles 5–6 — caisses; fiscal season and budget

**Files:**
- Create: `resources/help/ar/finance/caisses.md`, `season.md`
- Modify: `data-help` in `resources/js/Pages/Finance/Registers.vue`, `resources/js/Components/Finance/CaisseFormModal.vue`, `resources/js/Pages/Finance/Settings.vue`, `resources/js/Pages/Finance/Index.vue` (settings link only); `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify: `FinanceAccountController::validateData` / `destroy` / `update`; form `CaisseFormModal.vue`: «الاسم», «لمن يتبع هذا الصندوق؟» (club level or «خزينة {branch}»), «الفئة المرتبطة (اختياري)» only with a branch (taken categories disabled «(له صندوق بالفعل)»; hint «تصل دفعات لاعبي هذه الفئة إلى هذا الصندوق تلقائياً.»), «نوع الصندوق», account number only for bank, «الرصيد الافتتاحي», «ملاحظات», «نشط» (unticking deactivates; warning when money is left); one club-level bank account only; a used caisse cannot be deleted («هذا الصندوق مستعمل ولا يمكن حذفه. يمكنك تعطيله بدلاً من ذلك.»), delete shows only for unused, empty accounts; coffres are auto-created per branch and can only be renamed / opening-balance edited. Season: `/finance/settings` (finance edit; link «إعدادات المالية» on `/finance`); seasons open by themselves with the first entry dated in them; optional «إضافة سنة» with «السنة المالية» + «الرصيد الافتتاحي»; opening balance editable inline while open (saves on leaving the field); budget card «الميزانية»: season, one amount per category, «حفظ الميزانية» («تم حفظ الميزانية.»), 0 removes a line; «إقفال السنة» → «إقفال هذه السنة؟ ستُقفل المعاملات ويُرحّل الرصيد إلى السنة التالية.» → flash «تم إقفال سنة {year}. تم ترحيل الرصيد إلى {next}.»; closed = no entry of that season can be added, edited or deleted; «إعادة فتح» reverses it (the next season keeps the opening balance it already received — check and say it only if the article needs it); «حذف السنة» only for an open empty season.

**Outlines:**
5. `caisses.md` «كيف أضيف صندوقًا أو أعدّله أو أعطّله» — module `finance`; pages `[finance.registers.index]`; example: a caisse «صندوق أقل من 17 سنة» in Football's coffre, linked to «أقل من 17 سنة», so that category's payments land there; later the season ends and it is deactivated. Steps: «الصناديق النقدية» → «إضافة صندوق» → «الاسم» → «لمن يتبع هذا الصندوق؟» «خزينة …» → «الفئة المرتبطة (اختياري)» → «حفظ» → to deactivate: the caisse's edit button, untick «نشط», «حفظ». Tip: the club bank account «الحساب البنكي للنادي» is a club-level account of type «البنك»; only one is allowed. Mention rights: add = finance add; edit/deactivate = finance edit. (No ⚠️: delete only works on unused, empty caisses — say so in one sentence.)
6. `season.md` «كيف أدير الموسم المالي والميزانية» — module `finance`; pages `[finance.index, finance.settings]`; example: the previous season is already closed and carried 120 000 into the current one; the treasurer sets this season's budget (e.g. «معدات» 30 000) and, at the season's end, closes it. Steps: «المالية» → «إعدادات المالية» → in «الميزانية» choose the season → type the planned amounts → «حفظ الميزانية» → at season end «إقفال السنة» on the current season → confirm. Warning (not irreversible — «إعادة فتح» exists): closing locks every entry of the season; reopen it with «إعادة فتح» to fix one. Tip: a new season opens by itself with its first entry; «إضافة سنة» is only needed to set its opening balance ahead.

**data-help targets:** `Registers.vue`: reuse `reg-add`, `reg-edit-caisse-first` (from Task 4 if added; add now if not). `CaisseFormModal.vue`: `caisse-name`, `caisse-place`, `caisse-category`, `caisse-type`, `caisse-opening`, `caisse-active`, `caisse-save`. `Finance/Index.vue`: `fin-settings-link`. `Settings.vue`: `fy-add-form` (add-year form), `fy-table`, `fy-close-current` (the open current season's «إقفال السنة»), `fy-reopen-previous` (closed previous season's «إعادة فتح»), `budget-card`, `budget-season`, `budget-line-eqp` (the «معدات» input), `budget-save`; the confirm modal panel/button (wrapper attr in `Settings.vue` if `ConfirmModal` drops attrs). Mark rows by season key or by status (`:data-help="fy.status === 'open' && fy.year === currentKey ? …"`), not by index.

**Shots:** `fin-caisse-add` (open «إضافة صندوق», fill name «صندوق أقل من 17 سنة», choose Football's coffre and «أقل من 17 سنة»; do not save), `fin-caisse-deactivate` (open the U14 caisse's edit, untick «نشط»; do not save), `fin-dashboard-settings-link` (`/finance`, target `fin-settings-link`), `fin-seasons` (`/finance/settings`, targets `fy-table`, `fy-close-current`, `fy-reopen-previous`), `fin-budget` (scroll to the card with `centre(page, 'budget-card')`, targets `budget-season`, `budget-line-eqp`, `budget-save`), `fin-close-confirm` (click `fy-close-current`, target the confirm; do not confirm). Generate, view every image.

- [ ] **Step 1:** data-help; build.
- [ ] **Step 2:** shots; generate; view.
- [ ] **Step 3:** write the 2 articles.
- [ ] **Step 4:** `help:check`, `test --filter=Help`.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/finance/caisses.md resources/help/ar/finance/season.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Finance/Registers.vue resources/js/Components/Finance/CaisseFormModal.vue resources/js/Pages/Finance/Settings.vue resources/js/Pages/Finance/Index.vue
git commit -m "docs(help): caisses and season/budget articles"
```

---

### Task 6: Article 7 — dashboard and season report, then phase verification

**Files:**
- Create: `resources/help/ar/finance/dashboard-report.md`
- Modify: `data-help` in `resources/js/Pages/Finance/Index.vue`; `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify: `FinanceController::index` (default = current season; `?year=` key), season buttons (closed ones with a check icon), banner (status, «الرصيد الختامي» with the closer or «الرصيد الافتتاحي»), tiles «الرصيد الافتتاحي», «إجمالي المداخيل», «إجمالي المصاريف», «الرصيد الصافي» (+ «مقارنة بالموسم السابق»), «التطور الشهري» chart, «الرصيد الحالي» card, «فئات الإيرادات» / «فئات المصاريف», «الميزانية مقابل الفعلي» (only with a budget), «كل السنوات» (click a row to switch season). PDF: the «تصدير» print button → `reports.financial?year=` (opens a new tab; needs the **reports** view right — say so). Content of the PDF: totals, donations, net, per-category breakdown.

**Outline:**
7. `dashboard-report.md` «كيف أقرأ لوحة المالية وأطبع تقرير الموسم» — module `finance`; pages `[finance.index]`; example: the board meeting asks how this season compares with the last one and how much of the equipment budget is spent. Steps: sidebar «المالية» → «المالية» (phone hint) → choose the season button → read the four tiles → «الميزانية مقابل الفعلي» (scroll; touchpad/mouse wheel) → «تصدير» → the PDF opens in a new tab: print or save it. Tip: «كل السنوات» at the bottom switches season too.

**data-help targets (`Finance/Index.vue`):** `fin-years` (season buttons), `fin-banner`, `fin-tiles`, `fin-trend`, `fin-current-balance`, `fin-budget-vs-actual`, `fin-all-years`, `fin-export` (PDF button).

**Shots:** `fin-dashboard` (`/finance`, targets `fin-years`, `fin-banner`, `fin-tiles`, `fin-export`), `fin-dashboard-budget` (`centre(page, 'fin-budget-vs-actual')`, targets `fin-budget-vs-actual`), `fin-dashboard-years` (`centre(page, 'fin-all-years')`). Generate, view every image (previous season closed with 120 000 carried; budget card shows «معدات» 30 000 planned vs 18 000 actual).

- [ ] **Step 1:** data-help; build; shots; view images.
- [ ] **Step 2:** write the article.
- [ ] **Step 3: Phase verification** — all must pass: `php artisan help:check` (`Help content OK — 31 article(s).`), `php artisan test --filter=Help`, `node --test tests/js/*.mjs`, `npm run i18n:check`, `npm run build`, and the full suite in the worktree (`php -d memory_limit=1G vendor/bin/phpunit`, no `bootstrap/cache/config.php`). Manual pass with a throwaway Playwright script (not committed): the 7 articles light/dark at 1280 and 320 px; `?` panel on `/transactions`, `/transactions/create`, `/finance/registers`, `/finance/settings`, `/finance`; Ctrl+K «مصروف» finds record-transaction; a user with only Transactions view sees articles 1–3 and not 4–7; a user with only Finance view sees 4–7.
- [ ] **Step 4: Commit**

```bash
git add resources/help/ar/finance/dashboard-report.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Finance/Index.vue
git commit -m "docs(help): finance dashboard and season report article; finance topic complete"
```
