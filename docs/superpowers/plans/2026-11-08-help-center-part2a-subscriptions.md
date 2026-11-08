# Help Center Part 2a — Subscriptions & payments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the `subscriptions` help topic (7 Arabic articles with screenshots), the `superadmin` article gate, and the demo subscriptions/payments data the screenshots need.

**Architecture:** Same Help Center engine as Part 1 (`resources/help/ar/<topic>/<slug>.md` → `HelpArticleParser` → `HelpLibrary` → Vue). Payment-recording code moves from `PlayerTransactionController` into a `PlayerPaymentRecorder` service (pure move) so the demo seeder records payments through the exact production path. A new `HelpDemoSubscriptionsSeeder` is called from the guarded `HelpDemoSeeder`.

**Tech Stack:** Laravel 13, Inertia 2 + Vue 3, PHPUnit-style tests (`#[Test]`), Playwright screenshot script (`npm run help:shots`).

Spec: `docs/superpowers/specs/2026-11-08-help-center-part2-design.md` (phase 2a). Part 1 references: `resources/help/README.md` (authoring guide), `resources/help/ar/players/*.md` (house style).

## Global Constraints

- Offline only; no CDN. Screenshots in `public/help-shots/<locale>/` (never `public/help/` — it shadows the `/help` route).
- Help UI uses app tokens (`primary-*`, `dark:`), dark text `primary-300`; markers `amber-500`.
- Articles Arabic only; UI strings in ar/fr/en, flat dotted keys, insert-only, no key segment named after a JS String method (`search`, `link`, `replace`, `split`, `match`…).
- Visibility: article `module:` else topic module; `null` = every signed-in user; new value `superadmin` = superadmins only. Gate = `User::hasPermission($module, 'view')`.
- Cache stores plain arrays only (config/cache.php `serializable_classes=false`); `HelpLibrary::CACHE_VERSION` bump only if the parser/enrich output shape changes (it does not in 2a).
- Writing rules: simple MSA, singular imperative, ≤ 15 words a sentence, one action per step (outcomes, not extra clicks), every clicked element ringed, phone hint where a step relies on gestures/sidebar/shortcuts («في الهاتف، اسحب الشاشة بإصبعك إلى الأعلى»), touchpad alongside mouse wheel, exact labels from `resources/js/i18n/ar.json` in «…», no emoji standing in for app icons, tooltip-only text not quoted as visible, every fact checked in code, ⚠️ warning for irreversible actions, 3–7 steps, ≤ 1 tip, ≤ 1 warning, worked example followed through.
- Demo data only in the throwaway screenshot DB; the guard stays in `HelpDemoSeeder` (`canRunOn`).
- Git: stage explicit files only (main folder has unrelated untracked `.claude/`, `ASIRAM.png`, `HANDOFF.md`, `brag-output/`). Never `git add -A`.
- Tests: `php artisan test --filter=<Name>`; full suite only in a worktree without `bootstrap/cache/config.php`: `php -d memory_limit=1G vendor/bin/phpunit`.

## File map

| File | Responsibility |
|---|---|
| `app/Services/Help/HelpLibrary.php` (modify `canSee`) | `superadmin` module value |
| `resources/help/topics.php` (modify) | add `subscriptions` topic |
| `resources/js/i18n/{ar,fr,en}.json` (modify) | `help.topic.subscriptions` |
| `app/Services/Finance/PlayerPaymentRecorder.php` (create) | subscription / group / player-level payment creation (moved verbatim) |
| `app/Http/Controllers/PlayerTransactionController.php` (modify) | delegate to the recorder |
| `database/seeders/HelpDemoSubscriptionsSeeder.php` (create) | demo caisse, subscriptions, assignments, payments, debts, receipt print |
| `database/seeders/HelpDemoSeeder.php` (modify) | call the new seeder |
| Subscriptions/Players/Transactions/Receipts Vue pages (modify) | `data-help` attributes only |
| `resources/help/shots.js` (modify) | new shots |
| `resources/help/ar/subscriptions/*.md` (create) | 7 articles |
| `public/help-shots/ar/**` (generated) | screenshots + boxes |

---

### Task 1: `superadmin` gate and the `subscriptions` topic

**Files:**
- Modify: `app/Services/Help/HelpLibrary.php` (`canSee`)
- Modify: `resources/help/topics.php`
- Modify: `resources/js/i18n/ar.json`, `fr.json`, `en.json`
- Test: `tests/Feature/Help/HelpLibraryTest.php`

**Interfaces:**
- Consumes: `User::isSuperadmin(): bool`, `User::hasPermission(string, string): bool`.
- Produces: article/topic `module: superadmin` visible only when `isSuperadmin()`; topic `subscriptions` (icon `subscriptions`, module `subscriptions`, article order `create-annual, create-exceptional, assign-players, take-payment, fix-payment, previous-debt, receipts`); i18n key `help.topic.subscriptions`.

- [ ] **Step 1: Write the failing test** — add to `tests/Feature/Help/HelpLibraryTest.php`:

```php
    #[Test]
    public function superadmin_articles_are_visible_to_superadmins_only(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        File::copyDirectory(base_path('tests/Fixtures/help'), $dir);
        File::put("{$dir}/ar/players/roles.md", "---\ntitle: الأدوار\nmodule: superadmin\n---\nنص\n");

        try {
            $lib = $this->library($dir);
            $everything = collect(\App\Models\Role::MODULES)
                ->mapWithKeys(fn (string $m) => [$m => ['view', 'add', 'edit', 'delete']])
                ->all();

            $this->assertArrayNotHasKey('players/roles', $lib->visibleFor($this->userWith($everything), 'ar'));
            $this->assertArrayNotHasKey('players/roles', $lib->visibleFor(User::factory()->admin()->create(), 'ar'));

            $super = User::factory()->create(['privileges' => ['superadmin']]);
            $this->assertArrayHasKey('players/roles', $lib->visibleFor($super, 'ar'));
        } finally {
            File::deleteDirectory($dir);
        }
    }
```

(`User::factory()->admin()` sets `privileges => ['admin']`, a god admin that is **not** a superadmin — `isSuperadmin()` checks `superadmin` only. If `Role::MODULES` is not a public constant, read it from `app/Models/Role.php` and build the list the same way.)

- [ ] **Step 2: Run it — expect FAIL** (`players/roles` visible to everyone with all modules / god admin, because `superadmin` is treated as a module name and god admins pass every module):

Run: `php artisan test --filter=superadmin_articles_are_visible_to_superadmins_only`

- [ ] **Step 3: Implement** — in `HelpLibrary::canSee`:

```php
    private function canSee(User $user, HelpArticle $article): bool
    {
        $module = $article->module ?? ($this->topics()[$article->topic]['module'] ?? null);

        if ($module === 'superadmin') {
            return $user->isSuperadmin();
        }

        return $module === null || $user->hasPermission($module, 'view');
    }
```

`resources/help/topics.php` — add after `players`:

```php
        'subscriptions' => ['icon' => 'subscriptions', 'module' => 'subscriptions', 'articles' => ['create-annual', 'create-exceptional', 'assign-players', 'take-payment', 'fix-payment', 'previous-debt', 'receipts']],
```

i18n (insert right after `help.topic.players` in each file; text edits, no re-serialising):

| key | ar | fr | en |
|---|---|---|---|
| `help.topic.subscriptions` | الاشتراكات والدفع | Abonnements et paiements | Subscriptions & payments |

- [ ] **Step 4: Run** `php artisan test --filter=Help` (all pass — `HelpContentTest` stays green because `help:check` ignores topics with no articles yet) and `npm run i18n:check`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Help/HelpLibrary.php resources/help/topics.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Help/HelpLibraryTest.php
git commit -m "feat(help): superadmin-only articles; subscriptions topic"
```

---

### Task 2: Move payment creation into `PlayerPaymentRecorder` (pure refactor)

**Files:**
- Create: `app/Services/Finance/PlayerPaymentRecorder.php`
- Modify: `app/Http/Controllers/PlayerTransactionController.php`
- Test: existing `tests/Feature/MultiSubscriptionPaymentTest.php`, `tests/Feature/PlayerLevelPaymentTest.php`, `tests/Feature/PlayerPaymentFlowTest.php` (+ new `tests/Unit/Finance/PlayerPaymentRecorderTest.php`)

**Interfaces:**
- Produces (`App\Services\Finance\PlayerPaymentRecorder`, container-resolved, no constructor args beyond what the moved code already uses):
  - `subscription(PlayerSubscription $sub, Player $player, float $amount, ?string $method, ?string $description, ?int $userId, ?int $financeAccountId, ?DateTimeInterface $date = null): array` — list<Transaction>, subscription payment first, excess as donation.
  - `group(Collection $subs, Player $player, float $amount, ?string $method, ?string $description, ?int $userId, ?int $financeAccountId): array` — pays in order, shared `payment_group` UUID when > 1 transaction.
  - `playerLevel(Player $player, string $category, float $amount, ?string $method, ?string $description, ?int $userId, ?int $financeAccountId, ?DateTimeInterface $date = null): Transaction`.
- The controller keeps everything else (validation, resolvePayables, exempt handling, activity recording, `RecalculatePlayerDebtService::forPlayer`). Callers (seeder, Task 3) must call `RecalculatePlayerDebtService::forPlayer($player)` afterwards, exactly as the controller does.

- [ ] **Step 1: Write a unit test pinning the moved behaviour** — `tests/Unit/Finance/PlayerPaymentRecorderTest.php`:

```php
<?php

namespace Tests\Unit\Finance;

use App\Models\Category;
use App\Models\Player;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\Finance\PlayerPaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerPaymentRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function player(): Player
    {
        $category = Category::create(['name' => 'U15']);

        return Player::create([
            'membership_id' => '8000000001', 'firstname' => 'P', 'lastname' => 'Test',
            'category_id' => $category->id, 'outstanding_debt' => 0,
        ]);
    }

    private function subscription(Player $player, float $amount): \App\Models\PlayerSubscription
    {
        $catalog = Subscription::create([
            'name' => 'S'.uniqid(), 'year' => 2026, 'amount_student' => $amount, 'amount_worker' => $amount,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $catalog->categories()->attach($player->category_id);

        return $catalog->assignTo($player);
    }

    #[Test]
    public function an_overpayment_splits_into_payment_and_donation(): void
    {
        $player = $this->player();
        $sub = $this->subscription($player, 3000);

        $created = app(PlayerPaymentRecorder::class)->subscription($sub, $player, 3500, 'cash', null, null, null);

        $this->assertCount(2, $created);
        $this->assertSame('subscription', $created[0]->category);
        $this->assertEquals(3000, $created[0]->amount);
        $this->assertSame('donation', $created[1]->category);
        $this->assertEquals(500, $created[1]->amount);
    }

    #[Test]
    public function a_group_payment_pays_in_order_and_shares_one_group(): void
    {
        $player = $this->player();
        $first = $this->subscription($player, 3000);
        $second = $this->subscription($player, 2000);

        $created = app(PlayerPaymentRecorder::class)->group(collect([$first, $second]), $player, 4000, 'cash', null, null, null);

        $this->assertCount(2, $created);
        $groups = Transaction::whereKey(collect($created)->pluck('id'))->pluck('payment_group')->unique();
        $this->assertCount(1, $groups);
        $this->assertNotNull($groups->first());
        $this->assertEquals(3000, $created[0]->amount);
        $this->assertEquals(1000, $created[1]->amount);
    }
}
```

If `Subscription::assignTo` needs the player registered in a branch the catalog targets, mirror `tests/Feature/MultiSubscriptionPaymentTest.php`'s setup instead of the helper above.

- [ ] **Step 2: Run it — expect FAIL** (`Class "App\Services\Finance\PlayerPaymentRecorder" not found`):

Run: `php artisan test --filter=PlayerPaymentRecorderTest`

- [ ] **Step 3: Move the code.** Create `app/Services/Finance/PlayerPaymentRecorder.php` containing the three methods currently private in `PlayerTransactionController` — `createSubscriptionPayment` (→ `subscription`), `createGroupPayment` (→ `group`), `createPlayerLevelPayment` (→ `playerLevel`) — **verbatim bodies**, made `public`, with internal calls renamed (`$this->createSubscriptionPayment(` → `$this->subscription(`, `$this->createPlayerLevelPayment(` → `$this->playerLevel(`), the same docblocks, and the imports those bodies use (`Player`, `PlayerSubscription`, `Transaction`, `ResolvePaymentStatusService`, `DateTimeInterface`, `Collection`, `DB`, `Str`). In the controller: add `public function __construct(private readonly PlayerPaymentRecorder $payments) {}`, replace every call of the three private methods with `$this->payments->subscription(` / `->group(` / `->playerLevel(` (same arguments), delete the three private methods, and drop imports that became unused. No behaviour change.

- [ ] **Step 4: Run** `php artisan test --filter="PlayerPaymentRecorderTest|MultiSubscriptionPaymentTest|PlayerLevelPaymentTest|PlayerPaymentFlowTest|PlayerTransaction"` — all pass. `vendor/bin/pint app/Services/Finance/PlayerPaymentRecorder.php app/Http/Controllers/PlayerTransactionController.php tests/Unit/Finance/PlayerPaymentRecorderTest.php`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/PlayerPaymentRecorder.php app/Http/Controllers/PlayerTransactionController.php tests/Unit/Finance/PlayerPaymentRecorderTest.php
git commit -m "refactor(finance): player payment creation in PlayerPaymentRecorder"
```

---

### Task 3: Demo subscriptions & payments seeder

**Files:**
- Create: `database/seeders/HelpDemoSubscriptionsSeeder.php`
- Modify: `database/seeders/HelpDemoSeeder.php` (call it at the end of `run()`)
- Test: `tests/Feature/Help/HelpDemoSubscriptionsSeederTest.php`

**Interfaces:**
- Consumes: `HelpDemoSeeder` data (user `demo`, branches `Football`/`Swimming`, categories incl. «أقل من 14 سنة» on Football and «أشبال» on Swimming, player 1 أحمد بن علي, player 2 ياسين بوزيد football+swimming), `PlayerPaymentRecorder` (Task 2), `RecalculatePlayerDebtService::forPlayer(Player)`, `ReceiptPrinter::record(Transaction, string $format, ?User)`, `ReceiptPrint::FORMAT_THERMAL`, `Season::current()->end()->year`.
- Produces constants on `HelpDemoSubscriptionsSeeder`: `ANNUAL = 'اشتراك سنوي'`, `TRIP = 'رحلة صيفية'`, `CAISSE = 'صندوق أقل من 14 سنة'`; demo facts later articles quote:
  - annual: current season (end year), student 3000 / worker 5000, both branches, every demo category, «أشبال» override student 2500 / worker 4000;
  - trip: exceptional, 1500 (student and worker), Football only, «أقل من 14 سنة» only;
  - player 1 أحمد: annual paid in full (3000, cash, into the caisse) → receipt printed once (thermal);
  - player 2 ياسين: one grouped payment of annual + trip;
  - player 3 أمين: partial annual payment 1000;
  - player 4 رياض: overpayment annual 3000 + 500 donation… (if رياض is a worker, use his worker amount + 500 — assert from the data, not a literal);
  - player 5 إسلام: unpaid annual (debt);
  - player 6 أيوب: previous debt «دين موسم 2023/2024» 2000;
  - player 7 عبد الرحمن: annual with a 10 % discount, unpaid.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Help/HelpDemoSubscriptionsSeederTest.php`:

```php
<?php

namespace Tests\Feature\Help;

use App\Models\FinanceAccount;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\ReceiptPrint;
use App\Models\Subscription;
use App\Models\Transaction;
use Database\Seeders\HelpDemoSeeder;
use Database\Seeders\HelpDemoSubscriptionsSeeder as Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoSubscriptionsSeederTest extends TestCase
{
    use RefreshDatabase;

    private function obligation(int $playerId, string $name): PlayerSubscription
    {
        return PlayerSubscription::where('player_id', $playerId)
            ->whereHas('subscription', fn ($q) => $q->where('name', $name))
            ->firstOrFail();
    }

    #[Test]
    public function the_demo_club_has_subscriptions_payments_and_debts(): void
    {
        $this->seed(HelpDemoSeeder::class);

        $annual = Subscription::where('name', Demo::ANNUAL)->firstOrFail();
        $this->assertSame(Subscription::KIND_ANNUAL, $annual->kind);
        $this->assertEquals(3000, $annual->amount_student);
        $this->assertEquals(5000, $annual->amount_worker);
        $this->assertSame(Subscription::KIND_EXCEPTIONAL, Subscription::where('name', Demo::TRIP)->value('kind'));
        $this->assertNotNull(FinanceAccount::where('name', Demo::CAISSE)->value('category_id'));

        // أحمد paid the annual in full; his receipt was printed once.
        $ahmed = $this->obligation(1, Demo::ANNUAL);
        $this->assertEquals(0, $ahmed->remaining_amount);
        $paid = Transaction::where('player_subscription_id', $ahmed->id)->firstOrFail();
        $this->assertSame(1, ReceiptPrint::where('transaction_id', $paid->id)->count());

        // ياسين: one grouped payment covering two subscriptions.
        $groups = Transaction::where('related_entity_id', 2)->where('category', 'subscription')->pluck('payment_group')->unique();
        $this->assertCount(1, $groups);
        $this->assertNotNull($groups->first());

        // أمين: partial; رياض: a donation exists; إسلام: unpaid.
        $this->assertEquals(1000, $this->obligation(3, Demo::ANNUAL)->amount_paid);
        $this->assertTrue(Transaction::where('related_entity_id', 4)->where('category', 'donation')->exists());
        $this->assertEquals(0, $this->obligation(5, Demo::ANNUAL)->amount_paid);
        $this->assertGreaterThan(0, Player::find(5)->outstanding_debt);

        // أيوب's previous debt; عبد الرحمن's 10 % discount.
        $this->assertTrue(PlayerSubscription::where('player_id', 6)->where('is_legacy', true)->where('amount_owed', 2000)->exists());
        $discounted = $this->obligation(7, Demo::ANNUAL);
        $this->assertSame('percent', $discounted->discount_type);
        $this->assertEquals(10, $discounted->discount_value);
    }
}
```

Check `PlayerSubscription` for the exact discount type literal (`percent` vs `percentage`) and the relation name `subscription`; adjust the test to the model, not the other way round.

- [ ] **Step 2: Run — expect FAIL** (`Class "Database\Seeders\HelpDemoSubscriptionsSeeder" not found`).

Run: `php artisan test --filter=HelpDemoSubscriptionsSeederTest`

- [ ] **Step 3: Implement** `database/seeders/HelpDemoSubscriptionsSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\ReceiptPrint;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Finance\PlayerPaymentRecorder;
use App\Services\Finance\ReceiptPrinter;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Support\Season;
use Illuminate\Database\Seeder;

/**
 * Subscriptions & payments for the Help Center demo club. Called only from the
 * guarded HelpDemoSeeder. Payments go through PlayerPaymentRecorder — the same
 * code the player page uses — so debts, statuses and receipts are real.
 */
class HelpDemoSubscriptionsSeeder extends Seeder
{
    public const ANNUAL = 'اشتراك سنوي';

    public const TRIP = 'رحلة صيفية';

    public const CAISSE = 'صندوق أقل من 14 سنة';

    public function run(): void
    {
        $demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();
        $football = Branch::where('name', 'Football')->firstOrFail();
        $swim = Branch::where('name', 'Swimming')->firstOrFail();
        $u14 = Category::where('branch_id', $football->id)->where('name', 'أقل من 14 سنة')->firstOrFail();
        $cubs = Category::where('branch_id', $swim->id)->where('name', 'أشبال')->firstOrFail();

        $coffre = FinanceAccount::where('branch_id', $football->id)->where('is_treasury', true)->firstOrFail();
        $caisse = FinanceAccount::create([
            'branch_id' => $football->id,
            'category_id' => $u14->id,
            'parent_account_id' => $coffre->id,
            'name' => self::CAISSE,
            'type' => 'cash',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $annual = Subscription::create([
            'name' => self::ANNUAL,
            'kind' => Subscription::KIND_ANNUAL,
            'year' => Season::current()->end()->year,
            'amount_student' => 3000,
            'amount_worker' => 5000,
            'is_mandatory' => true,
            'is_active' => true,
        ]);
        $annual->branches()->attach([$football->id, $swim->id]);
        $annual->categories()->attach(
            Category::whereIn('branch_id', [$football->id, $swim->id])->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => $id === $cubs->id
                    ? ['amount_student' => 2500, 'amount_worker' => 4000]
                    : ['amount_student' => null, 'amount_worker' => null]])
                ->all(),
        );

        $trip = Subscription::create([
            'name' => self::TRIP,
            'kind' => Subscription::KIND_EXCEPTIONAL,
            'year' => null,
            'amount_student' => 1500,
            'amount_worker' => 1500,
            'is_mandatory' => false,
            'is_active' => true,
        ]);
        $trip->branches()->attach($football->id);
        $trip->categories()->attach($u14->id, ['amount_student' => null, 'amount_worker' => null]);

        $payments = app(PlayerPaymentRecorder::class);
        $debts = app(RecalculatePlayerDebtService::class);
        $player = fn (int $id): Player => Player::findOrFail($id);

        // 1 أحمد — annual paid in full into the U14 caisse; receipt printed once.
        $ahmed = $player(1);
        $ahmedAnnual = $annual->assignTo($ahmed);
        $created = $payments->subscription($ahmedAnnual, $ahmed, (float) $ahmedAnnual->remaining_amount, 'cash', null, $demo->id, $caisse->id);
        app(ReceiptPrinter::class)->record($created[0], ReceiptPrint::FORMAT_THERMAL, $demo);
        $debts->forPlayer($ahmed);

        // 2 ياسين — annual + trip in one grouped payment.
        $yassine = $player(2);
        $yAnnual = $annual->assignTo($yassine);
        $yTrip = $trip->assignTo($yassine);
        $payments->group(collect([$yAnnual, $yTrip]), $yassine,
            (float) $yAnnual->remaining_amount + (float) $yTrip->remaining_amount, 'cash', null, $demo->id, $caisse->id);
        $debts->forPlayer($yassine);

        // 3 أمين — partial payment.
        $amine = $player(3);
        $payments->subscription($annual->assignTo($amine), $amine, 1000, 'baridimob', null, $demo->id, null);
        $debts->forPlayer($amine);

        // 4 رياض — pays 500 more than owed: the excess becomes a donation.
        $riad = $player(4);
        $riadAnnual = $annual->assignTo($riad);
        $payments->subscription($riadAnnual, $riad, (float) $riadAnnual->remaining_amount + 500, 'cash', null, $demo->id, null);
        $debts->forPlayer($riad);

        // 5 إسلام — assigned, unpaid.
        $islam = $player(5);
        $annual->assignTo($islam);
        $debts->forPlayer($islam);

        // 6 أيوب — a debt from before the app.
        $ayoub = $player(6);
        PlayerSubscription::create([
            'player_id' => $ayoub->id,
            'subscription_id' => null,
            'label' => 'دين موسم 2023/2024',
            'year' => 2024,
            'amount_owed' => 2000,
            'amount_paid' => 0,
            'is_legacy' => true,
            'is_mandatory' => false,
            'is_exempt' => false,
        ]);
        $debts->forPlayer($ayoub);

        // 7 عبد الرحمن — 10 % discount, unpaid.
        $abderrahmane = $player(7);
        $annual->assignTo($abderrahmane)->update(['discount_type' => 'percent', 'discount_value' => 10]);
        $debts->forPlayer($abderrahmane);
    }
}
```

Before running, verify against the code and adapt (record each adaptation in the report):
- `FinanceAccountController::store` / `validateData` for required caisse fields (e.g. `currency`, `sort_order`) and whether a caisse under a coffre sets `parent_account_id`.
- `SubscriptionController::store` for how category overrides are stored (null vs omitted pivot amounts) and whether exceptional subscriptions need branch/category rows.
- `players.subscriptions.store` (`PlayerSubscriptionController` or similar) for the exact fields a previous debt gets (`status_at_time`, `due_date`, `is_legacy`).
- The discount type literal in `PlayerSubscription` (`percent` vs `percentage`).

Then in `HelpDemoSeeder::run()`, as the last line: `$this->call(HelpDemoSubscriptionsSeeder::class);`.

- [ ] **Step 4: Run** `php artisan test --filter="HelpDemoSubscriptionsSeederTest|HelpDemoSeederTest"` — all pass. `vendor/bin/pint` on the two seeders and the test.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/HelpDemoSubscriptionsSeeder.php database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoSubscriptionsSeederTest.php
git commit -m "feat(help): demo subscriptions, payments and debts"
```

---

### Task 4: Articles 1–3 — creating subscriptions and assigning players

**Files:**
- Create: `resources/help/ar/subscriptions/create-annual.md`, `create-exceptional.md`, `assign-players.md`
- Modify: `data-help` attributes in `resources/js/Pages/Subscriptions/Index.vue`, `Create.vue`, `Partials/SubscriptionForm.vue`, `Show.vue`; `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Writing rules: Global Constraints + `resources/help/README.md`; house style = `resources/help/ar/players/*.md`. Every label grepped in `ar.json`; every fact checked in code (`app/Http/Requests/Subscription/StoreSubscriptionRequest.php`, `SubscriptionController`, `Subscription::amountFor`).

**Outlines:**
1. `create-annual.md` «كيف أنشئ اشتراكاً سنوياً» — pages `[subscriptions.index, subscriptions.create]`; example: «اشتراك سنوي» for the current season, 3000 student / 5000 worker, both branches, «أشبال» cheaper (2500/4000). Steps: open «الاشتراكات» → new subscription button → kind «اشتراك سنوي» + name + season → student/worker prices → branches + categories (leave branches empty = whole club) → category price override → save. Warning: the name must be unique within the season.
2. `create-exceptional.md` «كيف أنشئ مبلغاً استثنائياً» — pages `[subscriptions.create]`; example «رحلة صيفية» 1500 for «أقل من 14 سنة». Steps: kind «استثنائي» → name/amount → branch/category → save. Tip/warning: no season, counts as the player's debt until paid; kind can't change once players are assigned.
3. `assign-players.md` «كيف أضيف اللاعبين إلى اشتراك» — pages `[subscriptions.show]`; example: put «أقل من 14 سنة» on «اشتراك سنوي», then add one swimmer from another category. Steps: open the subscription → «تعيين الاشتراك» + category (or «جميع الفئات») → «تعيين» → «إضافة لاعب» + pick → read «سيكون المبلغ:» → confirm → outside-category checkbox. Tip: players already on it are skipped.

**data-help targets (attribute only):** `subscriptions-new` (create button on Index), `sub-kind`, `sub-name`, `sub-season`, `sub-prices`, `sub-branches`, `sub-categories`, `sub-category-price` (first override input), `sub-save`, `sub-assign`, `sub-assign-category`, `sub-add-player`, `sub-amount-will-be`, `sub-outside-category`, `sub-stats` (stat tiles on Show). Find each with grep; confirm attrs reach a real DOM element.

**Shots (append to `resources/help/shots.js`;** subscription ids: annual = 1, trip = 2 on the fresh demo DB — verify in the seeder order):

```js
    { name: 'subscriptions-index', url: '/subscriptions', targets: ['subscriptions-new'] },
    { name: 'subscription-create', url: '/subscriptions/create', targets: ['sub-kind', 'sub-name', 'sub-season', 'sub-prices'] },
    {
        name: 'subscription-create-scope',
        url: '/subscriptions/create',
        targets: ['sub-branches', 'sub-categories', 'sub-save'],
        before: async (page) => { await page.evaluate(() => document.querySelector('[data-help="sub-categories"]')?.scrollIntoView({ block: 'center', behavior: 'instant' })); },
    },
    { name: 'subscription-show', url: '/subscriptions/1', targets: ['sub-stats', 'sub-assign', 'sub-add-player'] },
```

Add shots for the assign dialog / add-player picker / exceptional kind as the articles need (open dialogs in `before`, scroll with `behavior: 'instant'`). Run `npm run build && npm run help:shots`; view every image; rings on target.

- [ ] **Step 1:** data-help attributes; `npm run build`.
- [ ] **Step 2:** shots; `npm run help:shots`; view images.
- [ ] **Step 3:** write the 3 articles.
- [ ] **Step 4:** `php artisan help:check` (OK), `php artisan test --filter=Help`.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/subscriptions/create-annual.md resources/help/ar/subscriptions/create-exceptional.md resources/help/ar/subscriptions/assign-players.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Subscriptions
git commit -m "docs(help): subscription creation and assignment articles"
```

---

### Task 5: Articles 4–6 — payments from the player page

**Files:**
- Create: `resources/help/ar/subscriptions/take-payment.md`, `fix-payment.md`, `previous-debt.md`
- Modify: `data-help` in `resources/js/Pages/Players/Show.vue` and its payment/subscription partials (grep `add_payment`, `add_previous_debt`, `edit_payment`, `edit_subscription`); `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify in code before writing: `PlayerTransactionController::store` (tick order, exempt, default caisse, overpayment donation, closed season), `PlayerPaymentRecorder`, edit = cancel + recreate (`update`), soft archive on delete, `players.subscriptions.update` discount rules (percent ≤ 100), `players.subscriptions.destroy` refused with payments.

**Outlines** (each `module: players`):
4. `take-payment.md` «كيف أسجّل دفعة» — pages `[players.show]`; example: ياسين pays «اشتراك سنوي» and «رحلة صيفية» together (one receipt). Steps: open the player → «إضافة دفعة» → type «اشتراك» → tick subscriptions in order (#1 paid first) → amount + method → caisse («الصندوق المستلم للإيراد») → «حفظ». Tip: paying more than owed creates a donation. Warning: dates in a closed season are refused.
5. `fix-payment.md` «كيف أصحح دفعة أو أمنح تخفيضاً» — pages `[players.show]`; example: أمين's 1000 was really 1500; عبد الرحمن gets 10 %. Steps: history row pencil (edit = cancel + new record, same date and group) → change amount → save; subscriptions card «تعديل الاشتراك» → discount type/value → «المبلغ الصافي» → save. Warning: cancelling a payment removes it from totals (kept in the activity log).
6. `previous-debt.md` «كيف أضيف ديناً قديماً» — pages `[players.show]`; example: أيوب owes 2000 from 2023/2024. Steps: «إضافة دين سابق» → label + amount + year → save → it appears as «دين سابق» → pay it with «إضافة دفعة».

**data-help targets:** `player-add-payment`, `payment-category`, `payment-subscriptions`, `payment-amount`, `payment-method`, `payment-register`, `payment-save`, `payment-donation-warning` (if shown), `player-history-edit` (first row pencil), `player-sub-edit` (first obligation edit), `sub-discount`, `sub-net-owed`, `player-add-previous-debt`, `debt-label`, `debt-amount`, `debt-year`, `debt-save`, `player-subscriptions-card`. Player ids: 2 ياسين, 3 أمين, 6 أيوب, 7 عبد الرحمن.

**Shots:** open modals in `before` (click the button by its data-help, wait for the dialog), e.g.:

```js
    {
        name: 'payment-modal',
        url: '/players/2',
        targets: ['payment-category', 'payment-subscriptions', 'payment-amount', 'payment-register', 'payment-save'],
        before: async (page) => {
            await page.click('[data-help="player-add-payment"]');
            await page.waitForSelector('[data-help="payment-subscriptions"]');
        },
    },
```

plus shots for the player page button, history row, edit-obligation dialog, previous-debt dialog. View every image.

- [ ] **Step 1:** data-help attributes; build.
- [ ] **Step 2:** shots; generate; view.
- [ ] **Step 3:** write the 3 articles.
- [ ] **Step 4:** `help:check`, `test --filter=Help`.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/subscriptions/take-payment.md resources/help/ar/subscriptions/fix-payment.md resources/help/ar/subscriptions/previous-debt.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Players resources/js/Components
git commit -m "docs(help): payment, correction and previous-debt articles"
```

(Stage only the Vue files you actually changed, listed explicitly — the directory paths above are shorthand for the files under them that carry new `data-help` attributes.)

---

### Task 6: Article 7 — receipts, then phase verification

**Files:**
- Create: `resources/help/ar/subscriptions/receipts.md`
- Modify: `data-help` in the player history ticket button (`Players/Show.vue`), `resources/js/Pages/Transactions/Show.vue`, `resources/js/Pages/Receipts/Verify.vue`; `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify: `ReceiptController` (thermal/A4, copy numbering, 60-second reuse, statuses unknown/deleted/cancelled/modified/valid), `ReceiptPrinter::normalizeCode` (Arabic digits, AZERTY), nav item «التحقق من وصل» under Finance (transactions view).

**Outline** (`module: transactions`): `receipts.md` «كيف أطبع وصلاً وأتحقق من وصل ورقي» — pages `[players.show, transactions.show, receipts.verify]`; example: أحمد's paid annual receipt (printed once in the demo). Steps: ticket icon on the payment row (80 mm) → or open the transaction and choose A4 → a later print says «نسخة رقم …» → open «التحقق من وصل» → type the 12 digits from the paper → «تحقق» → read the result. Tip: the code can be typed with Arabic digits.

**data-help targets:** `player-history-ticket`, `tx-print-ticket`, `tx-print-a4`, `receipt-code`, `receipt-check`, `receipt-result`. Verify shot: use the demo receipt's code — read it in the shot's `before` from the transaction page or query it via the verify URL (`/receipts/verify?code=<code>`); the simplest robust way is a tiny `before` that opens `/transactions/<id>` (أحمد's payment id), reads the displayed code element, then navigates to `/receipts/verify?code=…`. Document what you did in shots.js comments.

- [ ] **Step 1:** data-help; build; shots; view images.
- [ ] **Step 2:** write the article.
- [ ] **Step 3: Phase verification** — all must pass: `php artisan help:check` (`Help content OK — 16 article(s).`), `php artisan test --filter=Help`, `node --test tests/js/*.mjs`, `npm run i18n:check`, `npm run build`, and the full suite in the worktree (`php -d memory_limit=1G vendor/bin/phpunit`, no `bootstrap/cache/config.php`). Manual pass with a throwaway Playwright script (not committed): the 7 articles light/dark at 1280 and 320 px, `?` panel on `/subscriptions`, `/subscriptions/1`, `/players/2`, Ctrl+K «دفعة» finds take-payment; a user with only Players view sees take-payment/fix-payment/previous-debt but not create-annual.
- [ ] **Step 4: Commit**

```bash
git add resources/help/ar/subscriptions/receipts.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Transactions/Show.vue resources/js/Pages/Receipts/Verify.vue resources/js/Pages/Players/Show.vue
git commit -m "docs(help): receipts article; subscriptions topic complete"
```
