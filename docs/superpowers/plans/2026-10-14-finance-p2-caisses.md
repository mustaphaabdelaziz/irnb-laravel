# Finance P2 — Simpler Caisses — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Caisse management becomes one plain page: each branch has a coffre (safe) created with the branch; caisses are created only by hand, optionally linked to a member category; money moves through a "Move money" modal; the Fonds Initial disappears from view.

**Architecture:** Backend keeps the `finance_accounts` table and the treasury roll-up (`FinanceService::recomputeAccountBalances`). Provisioning shrinks to "branch → coffre". `FinanceAccountController` gains an optional member-category link and one consistent delete/deactivate rule set. `DefaultRegisterResolver` and every picker skip the Fonds Initial. `CashRegisterController@index` sends a page-shaped payload (coffres with their caisses, club level, totals); the Vue page is rebuilt around it with two modals. Settings loses its duplicate account list.

**Tech Stack:** Laravel 13, PHPUnit (`#[Test]`, `RefreshDatabase`), Inertia + Vue 3 `<script setup>`, Tailwind, vue-i18n flat dotted keys.

Spec: `docs/superpowers/specs/2026-10-14-finance-simplify-design.md` §P2.

## Global Constraints

- Branch `feat/finance-caisses` from `main` (worktree `D:\irnb-season`). Stage explicit file paths only; never commit `.claude/`, `.superpowers/`, `public/build`, `*.zip`.
- **Owner decisions:** creating a member category creates NO caisse; existing caisses (incl. swimming's two) are left as they are; Fonds Initial hidden everywhere (kept in DB; shown read-only only while its balance ≠ 0).
- Vocabulary (ar / fr / en): coffre = "خزينة {branch}" / "Coffre — {branch}" / "{branch} safe"; caisse = "صندوق" / "Caisse" / "Caisse"; club level = "على مستوى النادي" / "Niveau club" / "Club level"; move money = "نقل الأموال" / "Déplacer de l'argent" / "Move money".
- Permissions are route-name derived (`config/permissions.php`): page = `finance/view`; create caisse / transfer = `finance/add`; edit = `finance/edit`; delete = `finance/delete`. UI hides actions with `can('finance', '<action>')`.
- Flash format `['key' => 'flash.x', 'params' => [...]]`; every new key in `resources/js/i18n/{ar,fr,en}.json`; `npm run i18n:check` passes. RTL-safe classes only (`ms-/me-/ps-/pe-`, `text-start/end`, `rtl:rotate-180` on back arrows). Use `ConfirmModal`, never `window.confirm`.
- Offline-only. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Tests: no Player factory (create players directly); `User::factory()->admin()`; Inertia page tests need `public/build` (`npm run build`). If tests fail en masse with "Cached config detected", run `php artisan config:clear`.

## File map

| File | Change |
|---|---|
| `app/Services/Finance/CashRegisterProvisioner.php` | `forBranch` → coffre only; delete `forCategory`, `categoryRegister`, `registerName` |
| `app/Observers/CategoryObserver.php` | no provisioning, no renaming; `deleting` unlinks |
| `app/Observers/BranchObserver.php` | docblock |
| `app/Http/Controllers/FinanceAccountController.php` | optional `category_id`; delete/deactivate rules |
| `app/Services/Finance/DefaultRegisterResolver.php` | never Fonds Initial / orphan coffre |
| `app/Models/FinanceAccount.php` | `selectable()` skips Fonds Initial; cast `is_opening_fund` |
| `app/Models/Transaction.php` | `FINANCE_ACCOUNT_LABEL` adds `is_opening_fund` |
| `app/Http/Controllers/TransactionController.php`, `FinanceController.php` | hide Fonds Initial in lists |
| `app/Models/FinanceTransfer.php` | `transfer_date` serializes `Y-m-d` |
| `app/Http/Controllers/CashRegisterController.php` | page payload |
| `resources/js/Composables/useFinanceAccountLabel.js` | coffre / caisse / Fonds Initial labels |
| `resources/js/Components/Finance/CaisseFormModal.vue` (new), `MoveMoneyModal.vue` (new) | modals |
| `resources/js/Pages/Finance/Registers.vue` | rebuilt page |
| `resources/js/Composables/useNavigation.js` | sidebar entry |
| `resources/js/Pages/Finance/Settings.vue`, `Finance/Index.vue` | duplicates removed |
| `resources/js/i18n/{ar,fr,en}.json` | keys |
| `tests/Feature/CashRegisterManagementTest.php`, `tests/Feature/CaissesPageTest.php` (new) | tests |

---

### Task 1: A branch gets a coffre; a category gets nothing

**Files:**
- Modify: `app/Services/Finance/CashRegisterProvisioner.php`, `app/Observers/CategoryObserver.php`, `app/Observers/BranchObserver.php`
- Test: `tests/Feature/CashRegisterManagementTest.php`

**Interfaces — Produces:** `CashRegisterProvisioner::forBranch(Branch): FinanceAccount` (coffre only). `forCategory`, `registerName` no longer exist (grep callers: `FinanceAccountController::prepareHierarchy` uses `forBranch` only; fix any other caller found).

- [ ] **Step 1: Rewrite the provisioning tests**

In `CashRegisterManagementTest`, delete `every_branch_gets_one_treasury_and_one_register_per_category` and `a_new_category_gets_one_register_in_every_existing_branch`, and add:

```php
    #[Test]
    public function a_new_branch_gets_only_its_coffre(): void
    {
        Category::create(['name' => 'Junior']);
        $branch = Branch::create(['name' => 'Football']);

        $accounts = FinanceAccount::where('branch_id', $branch->id)->get();

        $this->assertCount(1, $accounts);
        $this->assertTrue($accounts->first()->is_treasury);
        $this->assertNull($accounts->first()->category_id);
    }

    #[Test]
    public function a_new_category_creates_no_caisse(): void
    {
        Branch::create(['name' => 'Football']);
        $before = FinanceAccount::count();

        Category::create(['name' => 'Cadet']);

        $this->assertSame($before, FinanceAccount::count());
    }

    #[Test]
    public function deleting_a_category_unlinks_its_caisses_but_keeps_them_active(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $category = Category::create(['name' => 'Cadet']);
        $caisse = FinanceAccount::create([
            'branch_id' => $branch->id, 'category_id' => $category->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Caisse cadets', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);

        $category->delete();

        $caisse->refresh();
        $this->assertNull($caisse->category_id);
        $this->assertTrue($caisse->is_active);
        $this->assertSame('Caisse cadets', $caisse->name);
    }

    #[Test]
    public function renaming_a_category_does_not_rename_a_hand_named_caisse(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $category = Category::create(['name' => 'Cadet']);
        $caisse = FinanceAccount::create([
            'branch_id' => $branch->id, 'category_id' => $category->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Caisse cadets', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);

        $category->update(['name' => 'Cadets U17']);

        $this->assertSame('Caisse cadets', $caisse->fresh()->name);
    }
```

Other existing tests in this file that relied on auto-created category registers (`treasury_rolls_up_…`, `a_player_payment_defaults_…`, `selecting_a_subscription_defaults_…`, `income_and_expense_…`, `money_can_move_…`, `a_transfer_cannot_exceed_…`): create the category caisse they need explicitly with `FinanceAccount::create([... 'parent_account_id' => $branch->treasury->id ...])` right after creating branch + category. Keep every assertion.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=CashRegisterManagementTest`
Expected: FAIL — `a_new_branch_gets_only_its_coffre` counts 2+ accounts; `a_new_category_creates_no_caisse` count grows.

- [ ] **Step 3: Shrink the provisioner**

`app/Services/Finance/CashRegisterProvisioner.php` becomes:

```php
<?php

namespace App\Services\Finance;

use App\Models\Branch;
use App\Models\FinanceAccount;

class CashRegisterProvisioner
{
    /**
     * Ensure a branch has its coffre (the branch treasury). Caisses inside it
     * are created by hand — a new member category never creates one.
     */
    public function forBranch(Branch $branch): FinanceAccount
    {
        return FinanceAccount::firstOrCreate(
            ['branch_id' => $branch->id, 'is_treasury' => true],
            fn () => [
                'name' => $branch->name.' Treasury',
                'type' => 'cash',
                'opening_balance' => 0,
                'current_balance' => 0,
                'currency' => 'DZD',
                'is_active' => true,
                'sort_order' => ((int) FinanceAccount::max('sort_order')) + 1,
            ],
        );
    }
}
```

`app/Observers/CategoryObserver.php` becomes:

```php
<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\FinanceAccount;

class CategoryObserver
{
    /**
     * A caisse linked to a deleted category keeps its money, name and history;
     * it simply stops being the category's default caisse.
     */
    public function deleting(Category $category): void
    {
        FinanceAccount::where('category_id', $category->id)->update(['category_id' => null]);
    }
}
```

`BranchObserver::created` docblock: `Every branch starts with its coffre (treasury); caisses are added by hand.`

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="CashRegisterManagementTest|Category|Branch"`
Expected: PASS. Then grep `grep -rn "forCategory\|registerName\|categoryRegister" app tests database` — no live references remain (old migrations that inline their own logic are fine; if a migration calls the removed methods, stop and report NEEDS_CONTEXT).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/CashRegisterProvisioner.php app/Observers/CategoryObserver.php app/Observers/BranchObserver.php tests/Feature/CashRegisterManagementTest.php
git commit -m "feat(finance): a branch gets its coffre; a new category no longer creates caisses"
```

---

### Task 2: Caisse create/edit with optional category link; one delete/deactivate rule set

**Files:**
- Modify: `app/Http/Controllers/FinanceAccountController.php`
- Modify: `resources/js/i18n/{ar,fr,en}.json` (flash keys)
- Test: `tests/Feature/CaisseRulesTest.php` (new)

**Interfaces — Produces:** POST `finance.accounts.store` / PUT `finance.accounts.update` accept `category_id` (nullable, member category id). Flash keys `flash.caisse_category_taken`, `flash.coffre_cannot_be_deleted`, `flash.coffre_has_caisses`, `flash.caisse_used_deactivate`, `flash.opening_fund_required`.

Rules (single source — the controller):

| Account | Delete | Deactivate | Branch change | Category link |
|---|---|---|---|---|
| Fonds Initial (`is_opening_fund`) | never (`flash.opening_fund_required`) | never | never | never |
| Coffre (`is_treasury`) | only when it has no caisses (`flash.coffre_has_caisses`) and no transactions/transfers (`flash.coffre_cannot_be_deleted`) | never | never | never |
| Caisse | only with no transactions/transfers (`flash.caisse_used_deactivate`) | yes | yes (moves under that branch's coffre; empty = club level) | optional, only when under a coffre; one caisse per (branch, category) (`flash.caisse_category_taken`) |

- [ ] **Step 1: Write the failing tests** — `tests/Feature/CaisseRulesTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaisseRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->branch = Branch::create(['name' => 'Football']);
    }

    private function caisse(array $extra = []): FinanceAccount
    {
        return FinanceAccount::create([
            'branch_id' => $this->branch->id, 'parent_account_id' => $this->branch->treasury->id,
            'name' => 'Caisse', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ] + $extra);
    }

    #[Test]
    public function a_caisse_can_be_created_under_a_coffre_and_linked_to_a_category(): void
    {
        $category = Category::create(['name' => 'Cadet']);

        $this->actingAs($this->admin)->post(route('finance.accounts.store'), [
            'name' => 'Caisse cadets', 'type' => 'cash', 'branch_id' => $this->branch->id,
            'category_id' => $category->id, 'opening_balance' => 0,
        ])->assertSessionHas('success', 'flash.account_added');

        $caisse = FinanceAccount::where('name', 'Caisse cadets')->sole();
        $this->assertSame($this->branch->treasury->id, $caisse->parent_account_id);
        $this->assertSame($category->id, $caisse->category_id);
        $this->assertFalse($caisse->is_treasury);
    }

    #[Test]
    public function a_category_has_at_most_one_caisse_per_branch(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $this->caisse(['category_id' => $category->id]);

        $this->actingAs($this->admin)->post(route('finance.accounts.store'), [
            'name' => 'Second', 'type' => 'cash', 'branch_id' => $this->branch->id, 'category_id' => $category->id,
        ])->assertSessionHasErrors(['category_id' => __('flash.caisse_category_taken')]);
    }

    #[Test]
    public function a_club_level_account_cannot_be_linked_to_a_category(): void
    {
        $category = Category::create(['name' => 'Cadet']);

        $this->actingAs($this->admin)->post(route('finance.accounts.store'), [
            'name' => 'Bank', 'type' => 'bank', 'category_id' => $category->id,
        ])->assertSessionHasErrors('category_id');
    }

    #[Test]
    public function an_unused_caisse_can_be_deleted(): void
    {
        $caisse = $this->caisse();

        $this->actingAs($this->admin)->delete(route('finance.accounts.destroy', $caisse))
            ->assertSessionHas('success', 'flash.account_deleted');
        $this->assertModelMissing($caisse);
    }

    #[Test]
    public function a_used_caisse_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $caisse = $this->caisse();
        Transaction::create(['amount' => 100, 'transaction_date' => '2026-10-01', 'transaction_type' => 'income', 'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid', 'finance_account_id' => $caisse->id]);

        $this->actingAs($this->admin)->delete(route('finance.accounts.destroy', $caisse))
            ->assertSessionHas('error', 'flash.caisse_used_deactivate');

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $caisse), [
            'name' => 'Caisse', 'type' => 'cash', 'branch_id' => $this->branch->id, 'is_active' => false,
        ])->assertSessionHas('success', 'flash.account_updated');
        $this->assertFalse($caisse->fresh()->is_active);
    }

    #[Test]
    public function a_coffre_with_caisses_cannot_be_deleted_and_never_deactivates(): void
    {
        $coffre = $this->branch->treasury;
        $this->caisse();

        $this->actingAs($this->admin)->delete(route('finance.accounts.destroy', $coffre))
            ->assertSessionHas('error', 'flash.coffre_has_caisses');

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $coffre), [
            'name' => 'Safe', 'type' => 'cash', 'is_active' => false, 'opening_balance' => 500,
        ]);
        $coffre->refresh();
        $this->assertTrue($coffre->is_active);
        $this->assertSame($this->branch->id, $coffre->branch_id);
        $this->assertEquals(500, (float) $coffre->opening_balance);
    }

    #[Test]
    public function a_used_coffre_cannot_be_deleted(): void
    {
        $coffre = $this->branch->treasury;
        $other = $this->caisse();
        FinanceTransfer::create(['from_account_id' => $coffre->id, 'to_account_id' => $other->id, 'amount' => 0.01, 'transfer_date' => '2026-10-01']);
        $other->transactions()->delete();
        FinanceTransfer::query()->where('to_account_id', $other->id)->update(['to_account_id' => $coffre->id]);
        $other->delete();

        $this->actingAs($this->admin)->delete(route('finance.accounts.destroy', $coffre))
            ->assertSessionHas('error', 'flash.coffre_cannot_be_deleted');
    }

    #[Test]
    public function the_opening_fund_cannot_be_deleted_or_deactivated(): void
    {
        $fund = FinanceAccount::where('is_opening_fund', true)->first()
            ?? FinanceAccount::create(['name' => 'Opening Fund', 'type' => 'cash', 'is_opening_fund' => true, 'is_active' => true, 'currency' => 'DZD']);

        $this->actingAs($this->admin)->delete(route('finance.accounts.destroy', $fund))
            ->assertSessionHas('error', 'flash.opening_fund_required');

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $fund), ['name' => 'Opening Fund', 'type' => 'cash', 'is_active' => false]);
        $this->assertTrue($fund->fresh()->is_active);
    }
}
```

Note on `a_used_coffre_cannot_be_deleted`: the goal is a coffre with history but no caisses. If the setup above is awkward, build it more simply: create a transfer *from* the coffre *to* a club-level account (`FinanceAccount::create(['name' => 'Bank', 'type' => 'bank', 'is_active' => true, 'currency' => 'DZD'])`), with the coffre's opening balance raised first so the data is coherent. Keep the assertion.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=CaisseRulesTest`
Expected: FAIL (category_id not accepted; delete rules differ; flash keys).

- [ ] **Step 3: Implement in `FinanceAccountController`**

Replace `store`, `update`, `destroy`, `validateData`, `prepareHierarchy` with:

```php
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        // Only one club-wide bank account.
        if ($data['type'] === 'bank' && empty($data['branch_id'])
            && FinanceAccount::where('type', 'bank')->whereNull('branch_id')->exists()) {
            return back()->with('error', 'flash.club_bank_account_already_exists');
        }

        FinanceAccount::create($this->prepareHierarchy($data));
        $this->finance->recomputeAccountBalances();

        return back()->with('success', 'flash.account_added');
    }

    public function update(Request $request, FinanceAccount $financeAccount): RedirectResponse
    {
        $data = $this->validateData($request, $financeAccount);

        // The Fonds Initial and a coffre are structural: renamable, opening
        // balance editable, but never moved, linked or switched off.
        if ($financeAccount->is_opening_fund || $financeAccount->is_treasury) {
            unset($data['branch_id'], $data['category_id']);
            $data['is_active'] = true;
        } else {
            $data = $this->prepareHierarchy($data);
        }

        $financeAccount->update($data);
        $this->finance->recomputeAccountBalances();

        return back()->with('success', 'flash.account_updated');
    }

    public function destroy(FinanceAccount $financeAccount): RedirectResponse
    {
        if ($financeAccount->is_opening_fund) {
            return back()->with('error', 'flash.opening_fund_required');
        }

        if ($financeAccount->is_treasury && $financeAccount->childAccounts()->exists()) {
            return back()->with('error', 'flash.coffre_has_caisses');
        }

        $used = $financeAccount->transactions()->exists()
            || $financeAccount->incomingTransfers()->exists()
            || $financeAccount->outgoingTransfers()->exists();

        if ($used) {
            return back()->with('error', $financeAccount->is_treasury ? 'flash.coffre_cannot_be_deleted' : 'flash.caisse_used_deactivate');
        }

        $financeAccount->delete();

        return back()->with('success', 'flash.account_deleted');
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request, ?FinanceAccount $account = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['cash', 'bank', 'other'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            // A member category this caisse collects for by default — only inside a coffre.
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'account_number' => ['nullable', 'string', 'max:120'],
            'opening_balance' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! empty($data['category_id']) && empty($data['branch_id'])) {
            // Only a caisse inside a coffre collects for a category.
            throw ValidationException::withMessages(['category_id' => __('validation.prohibited', ['attribute' => __('category')])]);
        }

        if (! empty($data['category_id'])) {
            $taken = FinanceAccount::where('branch_id', $data['branch_id'])
                ->where('category_id', $data['category_id'])
                ->when($account, fn ($q) => $q->whereKeyNot($account->id))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['category_id' => __('flash.caisse_category_taken')]);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function prepareHierarchy(array $data): array
    {
        $data['is_treasury'] = false;

        if (empty($data['branch_id'])) {
            $data['parent_account_id'] = null;
            $data['category_id'] = null;

            return $data;
        }

        $branch = Branch::findOrFail($data['branch_id']);
        $data['parent_account_id'] = ($branch->treasury()->first()
            ?? app(CashRegisterProvisioner::class)->forBranch($branch))->id;
        $data['category_id'] ??= null;

        return $data;
    }
```

Add `use Illuminate\Validation\ValidationException;`. Note: on update of a structural account (coffre / Fonds Initial) the form sends no `branch_id`, so a stray `category_id` would trip the club-level check — `update` unsets both before use, but validation runs first: pass the request through `validateData` only after dropping `category_id` for structural accounts (e.g. `$request->merge(['category_id' => null])` when `$financeAccount->is_treasury || $financeAccount->is_opening_fund`).

The `__('flash.caisse_category_taken')` in a validation message must resolve on the server: add the key to `lang/ar.json`, `lang/fr.json` (and it falls back to the key in en — so also add `lang/en.json` if that file exists; otherwise use a plain English sentence as the `__()` key instead and translate that in lang/ar.json + lang/fr.json; adjust the test's expected message accordingly).

- [ ] **Step 4: i18n (resources/js/i18n, flat keys, alphabetical position)**

| key | en | fr | ar |
|---|---|---|---|
| `flash.caisse_category_taken` | This category already has a caisse in this branch. | Cette catégorie a déjà une caisse dans cette section. | لهذه الفئة صندوق في هذا الفرع بالفعل. |
| `flash.coffre_cannot_be_deleted` | A safe that has been used cannot be deleted. | Un coffre déjà utilisé ne peut pas être supprimé. | لا يمكن حذف خزينة سبق استعمالها. |
| `flash.coffre_has_caisses` | Move or delete the caisses in this safe first. | Déplacez ou supprimez d'abord les caisses de ce coffre. | انقل صناديق هذه الخزينة أو احذفها أولاً. |
| `flash.caisse_used_deactivate` | This caisse has been used, so it cannot be deleted. Deactivate it instead. | Cette caisse a déjà été utilisée : elle ne peut pas être supprimée. Désactivez-la plutôt. | هذا الصندوق مستعمل ولا يمكن حذفه. يمكنك تعطيله بدلاً من ذلك. |
| `flash.opening_fund_required` | The opening fund is required and cannot be deleted. | Le fonds initial est obligatoire et ne peut pas être supprimé. | صندوق الرصيد الافتتاحي ضروري ولا يمكن حذفه. |

Remove `flash.system_register_required`, `flash.treasury_has_child_accounts`, `flash.account_has_transactions`, `flash.account_has_transfers` if `grep -rn` finds no other use.

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter="CaisseRulesTest|CashRegisterManagementTest"` then `npm run i18n:check`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/FinanceAccountController.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/CaisseRulesTest.php
# plus lang/*.json if edited
git commit -m "feat(finance): caisses link to a category by hand; one clear rule for deleting and deactivating"
```

---

### Task 3: The Fonds Initial disappears from defaults and pickers

**Files:**
- Modify: `app/Services/Finance/DefaultRegisterResolver.php` (`__construct` clubWide filter, `load()` select)
- Modify: `app/Models/FinanceAccount.php` (`scopeSelectable`, casts)
- Modify: `app/Models/Transaction.php` (`FINANCE_ACCOUNT_LABEL`)
- Modify: `app/Http/Controllers/TransactionController.php` (index `financeAccounts`, ~:80-85), `app/Http/Controllers/FinanceController.php` (index `accounts`, ~:43-49)
- Test: `tests/Feature/CashRegisterManagementTest.php` (append)

- [ ] **Step 1: Failing tests** (append)

```php
    #[Test]
    public function the_default_register_is_never_the_opening_fund(): void
    {
        FinanceAccount::query()->delete();
        FinanceAccount::create(['name' => 'Opening Fund', 'type' => 'cash', 'is_opening_fund' => true, 'is_active' => true, 'currency' => 'DZD', 'sort_order' => 0]);
        $cash = FinanceAccount::create(['name' => 'Cash', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD', 'sort_order' => 5]);

        $this->assertSame($cash->id, \App\Services\Finance\DefaultRegisterResolver::load()->for(null, null)?->id);
    }

    #[Test]
    public function an_orphaned_coffre_is_never_the_club_default(): void
    {
        FinanceAccount::query()->delete();
        FinanceAccount::create(['name' => 'Old Treasury', 'type' => 'cash', 'is_treasury' => true, 'branch_id' => null, 'is_active' => true, 'currency' => 'DZD', 'sort_order' => 0]);
        $cash = FinanceAccount::create(['name' => 'Cash', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD', 'sort_order' => 5]);

        $this->assertSame($cash->id, \App\Services\Finance\DefaultRegisterResolver::load()->for(null, null)?->id);
    }

    #[Test]
    public function pickers_and_lists_hide_the_opening_fund(): void
    {
        $fund = FinanceAccount::where('is_opening_fund', true)->first()
            ?? FinanceAccount::create(['name' => 'Opening Fund', 'type' => 'cash', 'is_opening_fund' => true, 'is_active' => true, 'currency' => 'DZD']);

        $this->assertFalse(FinanceAccount::selectable()->get()->contains('id', $fund->id));

        $this->actingAs($this->admin())->get(route('transactions.index'))
            ->assertInertia(fn ($page) => $page->where('financeAccounts', fn ($accounts) => collect($accounts)->doesntContain('id', $fund->id)));
        $this->actingAs($this->admin())->get(route('finance.index'))
            ->assertInertia(fn ($page) => $page->where('accounts', fn ($accounts) => collect($accounts)->doesntContain('id', $fund->id)));
    }
```

(If the `FinanceAccount::query()->delete()` hits FK constraints from seeded rows, delete transfers/transactions first or create the accounts on a fresh state another way — keep assertions.)

- [ ] **Step 2: Run — expect FAIL.** `php artisan test --filter=CashRegisterManagementTest`

- [ ] **Step 3: Implement**

`DefaultRegisterResolver::__construct` clubWide:

```php
        // Club level: cash accounts outside any branch — never the Fonds Initial,
        // never a coffre whose branch was deleted.
        $this->clubWide = $accounts
            ->filter(fn (FinanceAccount $account) => $account->type === 'cash'
                && ! $account->branch_id && ! $account->is_treasury && ! $account->is_opening_fund)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->first();
```

and add `'is_opening_fund'` to the `load()` column list. Update the class docblock's third fallback to "else the first club-level cash account".

`FinanceAccount`: cast `'is_opening_fund' => 'boolean'`; in `scopeSelectable` add `->where('is_opening_fund', false)` and `'is_opening_fund'` to its select list.

`Transaction::FINANCE_ACCOUNT_LABEL[0]`: `'financeAccount:id,name,branch_id,category_id,is_treasury,is_opening_fund'`.

`TransactionController@index` and `FinanceController@index`: add `->where('is_opening_fund', false)` to the accounts queries (FinanceController: keep it visible only when non-zero — `->where(fn ($q) => $q->where('is_opening_fund', false)->orWhere('current_balance', '!=', 0))`).

- [ ] **Step 4: Run** `php artisan test --filter="CashRegisterManagementTest|Transaction|Finance"` — PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/DefaultRegisterResolver.php app/Models/FinanceAccount.php app/Models/Transaction.php app/Http/Controllers/TransactionController.php app/Http/Controllers/FinanceController.php tests/Feature/CashRegisterManagementTest.php
git commit -m "fix(finance): the opening fund is never a default caisse and leaves the pickers"
```

---

### Task 4: Caisses page payload

**Files:**
- Modify: `app/Http/Controllers/CashRegisterController.php::index`
- Modify: `app/Models/FinanceTransfer.php` (cast `'transfer_date' => 'date:Y-m-d'`)
- Test: `tests/Feature/CaissesPageTest.php` (new)

**Interfaces — Produces (Inertia `Finance/Registers` props):**
- `coffres: list<{branch: Branch, coffre: FinanceAccount|null, own_balance: float, caisses: list<FinanceAccount>}>` — every account carries `branch` and `category` (with `localized_name`) and `transactions_count`.
- `clubLevel: list<FinanceAccount>` — no branch, no parent, not Fonds Initial (includes coffres orphaned by a deleted branch).
- `openingFund: FinanceAccount|null` — only when its balance ≠ 0.
- `totals: {cash: float, active: int}`.
- `branches`, `categories` (id, names, `localized_name`) for the form.
- `transfers` — paginated, as today, `transfer_date` = `Y-m-d`.

- [ ] **Step 1: Failing test** — `tests/Feature/CaissesPageTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaissesPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_page_groups_caisses_under_their_coffre_and_shows_club_level_accounts(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $coffre = $branch->treasury;
        $coffre->update(['opening_balance' => 1000]);
        $category = Category::create(['name' => 'Senior']);
        $caisse = FinanceAccount::create(['branch_id' => $branch->id, 'parent_account_id' => $coffre->id, 'category_id' => $category->id, 'name' => 'Caisse seniors', 'type' => 'cash', 'opening_balance' => 300, 'is_active' => true, 'currency' => 'DZD']);
        $bank = FinanceAccount::create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 50, 'is_active' => true, 'currency' => 'DZD']);
        FinanceTransfer::create(['from_account_id' => $coffre->id, 'to_account_id' => $caisse->id, 'amount' => 100, 'transfer_date' => '2026-10-05']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.registers.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Registers')
                ->where('coffres.0.branch.id', $branch->id)
                ->where('coffres.0.coffre.id', $coffre->id)
                ->where('coffres.0.own_balance', 900)
                ->where('coffres.0.caisses.0.id', $caisse->id)
                ->where('coffres.0.caisses.0.category.id', $category->id)
                ->where('clubLevel', fn ($accounts) => collect($accounts)->pluck('id')->contains($bank->id))
                ->where('openingFund', null)
                ->where('totals.cash', 1350)
                ->where('transfers.data.0.transfer_date', '2026-10-05'));
    }
}
```

(`own_balance`: coffre own = 1000 − 100 = 900; caisse = 300 + 100 = 400; branch total 1300; + bank 50 = 1350. If the seeded club "Cash" account exists in a fresh DB it adds 0 — fine.)

- [ ] **Step 2: Run — expect FAIL.** `php artisan test --filter=CaissesPageTest`

- [ ] **Step 3: Implement `CashRegisterController::index`**

```php
    public function index(): Response
    {
        $this->finance->recomputeAccountBalances();

        $accounts = FinanceAccount::query()
            ->with(['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
            ->withCount('transactions')
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        $branches = Branch::query()->orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']);

        $coffres = $branches->map(function (Branch $branch) use ($accounts) {
            $coffre = $accounts->first(fn (FinanceAccount $a) => $a->is_treasury && $a->branch_id === $branch->id);
            $caisses = $coffre ? $accounts->where('parent_account_id', $coffre->id)->values() : collect();

            return [
                'branch' => $branch,
                'coffre' => $coffre,
                // The coffre's balance is a roll-up; this is the cash held in it directly.
                'own_balance' => $coffre ? round((float) $coffre->current_balance - $caisses->sum(fn ($c) => (float) $c->current_balance), 2) : 0.0,
                'caisses' => $caisses,
            ];
        })->values();

        $clubLevel = $accounts
            ->filter(fn (FinanceAccount $a) => ! $a->branch_id && ! $a->parent_account_id && ! $a->is_opening_fund)
            ->values();
        $openingFund = $accounts->first(fn (FinanceAccount $a) => $a->is_opening_fund && (float) $a->current_balance != 0.0);

        return Inertia::render('Finance/Registers', [
            'coffres' => $coffres,
            'clubLevel' => $clubLevel,
            'openingFund' => $openingFund,
            'totals' => [
                'cash' => round(
                    $coffres->sum(fn ($g) => (float) ($g['coffre']?->current_balance ?? 0))
                    + $clubLevel->sum(fn ($a) => (float) $a->current_balance)
                    + (float) ($openingFund?->current_balance ?? 0),
                    2,
                ),
                'active' => $accounts->where('is_active', true)->where('is_opening_fund', false)->count(),
            ],
            'branches' => $branches,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'transfers' => /* keep the existing FinanceTransfer query unchanged */,
        ]);
    }
```

Keep the existing `transfers` query verbatim (copy it in place of the comment); add `'fromAccount.category'…` relations unchanged; add `is_opening_fund` already present. Add `use App\Models\Category;`. In `FinanceTransfer` casts set `'transfer_date' => 'date:Y-m-d'`.

- [ ] **Step 4: Run** `php artisan test --filter="CaissesPageTest|CashRegisterManagementTest"` — PASS (the page test needs `public/build`; build first if missing).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/CashRegisterController.php app/Models/FinanceTransfer.php tests/Feature/CaissesPageTest.php
git commit -m "feat(finance): Caisses page data grouped by coffre, with club-level accounts and totals"
```

---

### Task 5: The Caisses page, its two modals, and the sidebar entry

**Files:**
- Create: `resources/js/Components/Finance/CaisseFormModal.vue`, `resources/js/Components/Finance/MoveMoneyModal.vue`
- Rewrite: `resources/js/Pages/Finance/Registers.vue`
- Modify: `resources/js/Composables/useFinanceAccountLabel.js`, `resources/js/Composables/useNavigation.js`
- Modify: `resources/js/i18n/{ar,fr,en}.json`

**Interfaces — Consumes:** Task 4 props; routes `finance.accounts.store|update|destroy`, `finance.transfers.store` (fields `from_account_id, to_account_id, amount, transfer_date, reference, notes`).

- [ ] **Step 1: i18n keys** (flat, alphabetical position, all three files)

| key | en | fr | ar |
|---|---|---|---|
| `caisses.add` | Add caisse | Ajouter une caisse | إضافة صندوق |
| `caisses.edit` | Edit caisse | Modifier la caisse | تعديل الصندوق |
| `caisses.edit_coffre` | Edit safe | Modifier le coffre | تعديل الخزينة |
| `caisses.coffre_of` | {branch} safe | Coffre — {branch} | خزينة {branch} |
| `caisses.coffre_own_cash` | Cash kept in the safe | Argent gardé dans le coffre | المال الموجود في الخزينة |
| `caisses.branch_total` | Branch total | Total de la section | مجموع الفرع |
| `caisses.club_level` | Club level | Niveau club | على مستوى النادي |
| `caisses.club_level_hint` | Accounts of the whole club, such as the bank account. | Comptes de tout le club, comme le compte bancaire. | حسابات تخص النادي كله، مثل الحساب البنكي. |
| `caisses.move_money` | Move money | Déplacer de l'argent | نقل الأموال |
| `caisses.from` | From | De | من |
| `caisses.to` | To | Vers | إلى |
| `caisses.after_move` | After the move | Après le mouvement | بعد النقل |
| `caisses.not_enough` | Not enough money in this caisse. | Pas assez d'argent dans cette caisse. | لا يوجد مال كافٍ في هذا الصندوق. |
| `caisses.place` | Where does it belong? | Où est-elle rattachée ? | أين يتبع هذا الصندوق؟ |
| `caisses.linked_category` | Linked category (optional) | Catégorie liée (facultatif) | الفئة المرتبطة (اختياري) |
| `caisses.linked_category_hint` | Player payments of this category land here by default. | Les paiements des joueurs de cette catégorie arrivent ici par défaut. | تصل دفعات لاعبي هذه الفئة إلى هذا الصندوق تلقائياً. |
| `caisses.no_category` | No category | Aucune catégorie | بدون فئة |
| `caisses.no_caisses` | No caisse yet — money goes straight into the safe. | Aucune caisse — l'argent va directement au coffre. | لا يوجد صندوق بعد — يدخل المال مباشرة إلى الخزينة. |
| `caisses.inactive` | Inactive | Inactive | غير نشط |
| `caisses.delete_title` | Delete this caisse? | Supprimer cette caisse ? | حذف هذا الصندوق؟ |
| `caisses.delete_message` | A caisse that has been used can only be deactivated. | Une caisse déjà utilisée peut seulement être désactivée. | الصندوق المستعمل يمكن تعطيله فقط. |
| `caisses.opening_fund_left` | Money from before the app, not yet placed in a caisse | Argent d'avant l'application, pas encore placé dans une caisse | مال سابق لاستعمال التطبيق لم يوضع بعد في صندوق |
| `caisses.history` | Money moves | Mouvements d'argent | حركة الأموال |
| `caisses.no_moves` | No money has been moved yet. | Aucun mouvement pour l'instant. | لم يتم نقل أي مال بعد. |
| `caisses.manage` | Manage caisses | Gérer les caisses | إدارة الصناديق |
| `caisses.subtitle` | Where the club's money is kept, and how it moves. | Où est gardé l'argent du club, et comment il circule. | أين يوجد مال النادي وكيف يتنقل. |

Delete keys made unused by the rewrite (`tip`, `transfer_opening_fund_to_register`, `treasury_rollup_hint`, `no_category_registers`, `other_accounts`, `category_cash_register`, `category_registers_created_automatically`, `pre_register_money`, `adjust_opening_fund`, …) only after `grep -rn "'<key>'" resources/js app` shows no use.

- [ ] **Step 2: `useFinanceAccountLabel.js`**

```js
import { useI18n } from 'vue-i18n';

/**
 * One label for a money account on every page: "Football safe" for a coffre,
 * "Caisse seniors — Football" for a caisse, the name alone at club level.
 * Caisses created automatically before 2026-10 carry an English name
 * ("Senior Cash Register"); those show their category's localized name.
 */
export function useFinanceAccountLabel() {
    const { t } = useI18n();

    const branchLabel = (branch) => branch?.localized_name || branch?.name || t('caisses.club_level');

    const caisseName = (account) => {
        const category = account.category;
        if (category && account.name === `${category.name} Cash Register`) {
            return category.localized_name || category.name;
        }
        return account.name;
    };

    const accountLabel = (account) => {
        if (!account) return '-';
        if (account.is_opening_fund) return t('opening_fund');
        if (account.is_treasury) return t('caisses.coffre_of', { branch: branchLabel(account.branch) });
        return account.branch ? `${caisseName(account)} — ${branchLabel(account.branch)}` : caisseName(account);
    };

    return { accountLabel, branchLabel, caisseName };
}
```

- [ ] **Step 3: `MoveMoneyModal.vue`**

```vue
<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { useFormatMoney } from '@/Composables/useFormatMoney';

// accounts: [{ id, label, balance }] — active accounts the money can move between.
const props = defineProps({
    show: { type: Boolean, default: false },
    accounts: { type: Array, default: () => [] },
    fromId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const form = useForm({
    from_account_id: '',
    to_account_id: '',
    amount: '',
    transfer_date: new Date().toISOString().slice(0, 10),
    reference: '',
    notes: '',
});

watch(() => props.show, (open) => {
    if (!open) return;
    form.reset();
    form.clearErrors();
    form.from_account_id = props.fromId ?? '';
});

const byId = (id) => props.accounts.find((a) => String(a.id) === String(id));
const from = computed(() => byId(form.from_account_id));
const to = computed(() => byId(form.to_account_id));
const amount = computed(() => Number(form.amount) || 0);
const tooMuch = computed(() => !!from.value && amount.value > Number(from.value.balance));
const destinations = computed(() => props.accounts.filter((a) => String(a.id) !== String(form.from_account_id)));

function submit() {
    form.post(route('finance.transfers.store'), { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form class="space-y-4 p-6" @submit.prevent="submit">
            <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('caisses.move_money') }}</h2>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('caisses.from') }}</span>
                <select v-model="form.from_account_id" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="" disabled>—</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }} · {{ formatMoney(a.balance) }}</option>
                </select>
                <InputError :message="form.errors.from_account_id" class="mt-1" />
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('caisses.to') }}</span>
                <select v-model="form.to_account_id" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="" disabled>—</option>
                    <option v-for="a in destinations" :key="a.id" :value="a.id">{{ a.label }} · {{ formatMoney(a.balance) }}</option>
                </select>
                <InputError :message="form.errors.to_account_id" class="mt-1" />
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('amount') }}</span>
                    <input v-model="form.amount" type="number" min="0.01" step="0.01" required class="w-full rounded-lg border-slate-200 bg-white text-sm tabular-nums dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="tooMuch" class="mt-1 text-xs text-rose-600">{{ t('caisses.not_enough') }}</p>
                    <InputError :message="form.errors.amount" class="mt-1" />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('date') }}</span>
                    <input v-model="form.transfer_date" type="date" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <InputError :message="form.errors.transfer_date" class="mt-1" />
                </label>
            </div>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('notes') }}</span>
                <input v-model="form.notes" type="text" maxlength="1000" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>

            <div v-if="from && to && amount > 0" class="rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                <p class="mb-1 text-xs font-semibold text-slate-500">{{ t('caisses.after_move') }}</p>
                <p class="tabular-nums">{{ from.label }}: {{ formatMoney(from.balance) }} → <strong>{{ formatMoney(Number(from.balance) - amount) }}</strong></p>
                <p class="tabular-nums">{{ to.label }}: {{ formatMoney(to.balance) }} → <strong>{{ formatMoney(Number(to.balance) + amount) }}</strong></p>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="emit('close')">{{ t('cancel') }}</button>
                <button type="submit" :disabled="form.processing || tooMuch" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('caisses.move_money') }}</button>
            </div>
        </form>
    </Modal>
</template>
```

Check `Modal.vue`'s `maxWidth` allowed values and the existing keys `amount`, `date`, `notes`, `cancel` (`grep -n '"amount"\|"date"\|"notes"\|"cancel"' resources/js/i18n/en.json`); use the existing equivalents if names differ.

- [ ] **Step 4: `CaisseFormModal.vue`**

```vue
<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';

// account: the account being edited, or null to add a caisse.
// presetBranchId: branch pre-selected when adding from a coffre card.
const props = defineProps({
    show: { type: Boolean, default: false },
    account: { type: Object, default: null },
    presetBranchId: { type: [Number, null], default: null },
    branches: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const form = useForm({
    name: '', type: 'cash', branch_id: '', category_id: '', opening_balance: 0,
    account_number: '', currency: 'DZD', is_active: true, notes: '',
});

const structural = computed(() => !!(props.account?.is_treasury || props.account?.is_opening_fund));
const label = (x) => x?.localized_name || x?.name;

watch(() => props.show, (open) => {
    if (!open) return;
    form.clearErrors();
    const a = props.account;
    form.name = a?.name ?? '';
    form.type = a?.type ?? 'cash';
    form.branch_id = a ? (a.branch_id ?? '') : (props.presetBranchId ?? '');
    form.category_id = a?.category_id ?? '';
    form.opening_balance = a?.opening_balance ?? 0;
    form.account_number = a?.account_number ?? '';
    form.currency = a?.currency ?? 'DZD';
    form.is_active = a ? !!a.is_active : true;
    form.notes = a?.notes ?? '';
});

watch(() => form.branch_id, (branch) => { if (!branch) form.category_id = ''; });

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    const payload = (data) => ({ ...data, branch_id: data.branch_id || null, category_id: data.category_id || null });
    if (props.account) form.transform(payload).put(route('finance.accounts.update', props.account.id), options);
    else form.transform(payload).post(route('finance.accounts.store'), options);
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form class="space-y-4 p-6" @submit.prevent="submit">
            <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">
                {{ account?.is_treasury ? t('caisses.edit_coffre') : (account ? t('caisses.edit') : t('caisses.add')) }}
            </h2>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('name') }}</span>
                <input v-model="form.name" type="text" required maxlength="120" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                <InputError :message="form.errors.name" class="mt-1" />
            </label>

            <template v-if="!structural">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('caisses.place') }}</span>
                    <select v-model="form.branch_id" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t('caisses.club_level') }}</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ t('caisses.coffre_of', { branch: label(b) }) }}</option>
                    </select>
                    <InputError :message="form.errors.branch_id" class="mt-1" />
                </label>

                <label v-if="form.branch_id" class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('caisses.linked_category') }}</span>
                    <select v-model="form.category_id" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t('caisses.no_category') }}</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ label(c) }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ t('caisses.linked_category_hint') }}</p>
                    <InputError :message="form.errors.category_id" class="mt-1" />
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('account_type') }}</span>
                    <select v-model="form.type" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="cash">{{ t('cash') }}</option>
                        <option value="bank">{{ t('bank') }}</option>
                        <option value="other">{{ t('other') }}</option>
                    </select>
                </label>

                <label v-if="form.type === 'bank'" class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('account_number') }}</span>
                    <input v-model="form.account_number" type="text" maxlength="120" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                </label>
            </template>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('opening_balance') }}</span>
                <input v-model="form.opening_balance" type="number" step="0.01" class="w-full rounded-lg border-slate-200 bg-white text-sm tabular-nums dark:border-slate-700 dark:bg-slate-800" />
                <InputError :message="form.errors.opening_balance" class="mt-1" />
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('notes') }}</span>
                <textarea v-model="form.notes" rows="2" maxlength="1000" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>

            <label v-if="account && !structural" class="flex items-center gap-2 text-sm">
                <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                <span>{{ t('active') }}</span>
            </label>

            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="emit('close')">{{ t('cancel') }}</button>
                <button type="submit" :disabled="form.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('save') }}</button>
            </div>
        </form>
    </Modal>
</template>
```

Verify the keys `name`, `account_type`, `cash`, `bank`, `other`, `account_number`, `opening_balance`, `notes`, `active`, `cancel`, `save` exist (`npm run i18n:check` won't catch template keys — grep en.json for each); add any missing one to all three files.

- [ ] **Step 5: Rewrite `Pages/Finance/Registers.vue`**

Script:

```vue
<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import CaisseFormModal from '@/Components/Finance/CaisseFormModal.vue';
import MoveMoneyModal from '@/Components/Finance/MoveMoneyModal.vue';
import { useCan } from '@/Composables/useCan';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';

const props = defineProps({
    coffres: { type: Array, default: () => [] },
    clubLevel: { type: Array, default: () => [] },
    openingFund: { type: Object, default: null },
    totals: { type: Object, default: () => ({ cash: 0, active: 0 }) },
    branches: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    transfers: { type: Object, required: true },
});

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useFormatMoney();
const { accountLabel, branchLabel, caisseName } = useFinanceAccountLabel();

// Every active account money can move between, labelled for the pickers.
const movable = computed(() => {
    const list = [];
    if (props.openingFund) list.push(props.openingFund);
    props.coffres.forEach((g) => { if (g.coffre) list.push({ ...g.coffre, current_balance: g.own_balance }); list.push(...g.caisses); });
    list.push(...props.clubLevel);
    return list.filter((a) => a.is_active).map((a) => ({ id: a.id, label: accountLabel(a), balance: Number(a.current_balance) }));
});

const formOpen = ref(false);
const formAccount = ref(null);
const presetBranchId = ref(null);
function addCaisse(branchId = null) { formAccount.value = null; presetBranchId.value = branchId; formOpen.value = true; }
function editAccount(account) { formAccount.value = account; presetBranchId.value = null; formOpen.value = true; }

const moveOpen = ref(false);
const moveFrom = ref(null);
function moveMoney(accountId = null) { moveFrom.value = accountId; moveOpen.value = true; }

const deleting = ref(null);
function confirmDelete() {
    router.delete(route('finance.accounts.destroy', deleting.value.id), { preserveScroll: true, onFinish: () => { deleting.value = null; } });
}

const balanceClass = (value) => (Number(value) >= 0 ? 'text-slate-900 dark:text-white' : 'text-rose-600 dark:text-rose-400');
const categoryLabel = (category) => category?.localized_name || category?.name;
const formatDate = (value) => (value ? new Date(`${value}T00:00:00`).toLocaleDateString(document.documentElement.lang || undefined) : '');
</script>
```

Template — sections in this order (follow the existing card styles: `card`, `rounded-xl`, slate palette, `dark:` variants, `ms-/me-`, `text-start/end`):

1. **Header slot**: back `Link` to `finance.index` with `<Icon name="back" class="rtl:rotate-180" />`; title `t('cash_registers')`, subtitle `t('caisses.subtitle')`; on the end: `IconButton icon="transfer"` (or another existing arrows icon — check `Icon.vue` names) label `t('caisses.move_money')` `v-if="can('finance','add') && movable.length > 1"` `@click="moveMoney()"`; `IconButton icon="plus" variant="primary"` label `t('caisses.add')` `v-if="can('finance','add')"` `@click="addCaisse()"`.
2. **Totals**: two cards in `grid gap-4 sm:grid-cols-2`: `t('total_cash')` → `formatMoney(totals.cash)`; `t('active_registers')` → `totals.active`. Values `text-2xl sm:text-3xl font-extrabold tabular-nums break-words` (no clipping on phones).
3. **Opening fund notice** `v-if="openingFund"`: amber card, one line `t('caisses.opening_fund_left')` + `formatMoney(openingFund.current_balance)` + `IconButton` move money `@click="moveMoney(openingFund.id)"` (when `can('finance','add')`).
4. **One card per `coffres` item** (`v-for="g in coffres" :key="g.branch.id"`):
   - Header row: icon `money`; title `t('caisses.coffre_of', { branch: branchLabel(g.branch) })`; under it `t('caisses.coffre_own_cash')`: `formatMoney(g.own_balance)`; on the end `t('caisses.branch_total')` with `formatMoney(g.coffre?.current_balance ?? 0)` big; actions (`plain size="sm"`): move money from coffre (`moveMoney(g.coffre.id)`, `can('finance','add')`), add caisse here (`addCaisse(g.branch.id)`, `can('finance','add')`), edit coffre (`editAccount(g.coffre)`, `can('finance','edit')`).
   - Body: `v-if="g.caisses.length"` a list `divide-y` of rows; each row: name `caisseName(c)`; category chip (`categoryLabel(c.category)`) when `c.category`; `t('caisses.inactive')` badge when `!c.is_active`; `{{ c.transactions_count }} {{ t('transactions') }}` muted; balance end-aligned `tabular-nums` with `balanceClass`; actions: move money (`moveMoney(c.id)`, only when active + `can('finance','add')`), edit (`editAccount(c)`, `can('finance','edit')`), delete (`deleting = c`, `variant="danger"`, only when `c.transactions_count === 0` and `can('finance','delete')`). Rows stack on small screens (`flex flex-wrap items-center gap-x-4 gap-y-2`).
   - Else: muted line `t('caisses.no_caisses')`.
5. **Club level card** `v-if="clubLevel.length"`: title `t('caisses.club_level')`, hint `t('caisses.club_level_hint')`, same row layout using `accountLabel(a)` for the name (and `t('bank')` chip when `a.type === 'bank'`).
6. **Money moves**: card titled `t('caisses.history')`; `v-if="transfers.data.length"` a table (`overflow-x-auto`) with columns date (`formatDate(tr.transfer_date)`), from → to (`accountLabel(tr.from_account)` `<Icon name="arrow" class="rtl:rotate-180" />` `accountLabel(tr.to_account)` — check icon names), amount `tabular-nums text-end`, notes/reference muted, recorded by `tr.created_by?.name`; else `t('caisses.no_moves')`. `<Pagination :links="transfers.links" />` below.
7. Modals at the end: `<CaisseFormModal :show="formOpen" :account="formAccount" :preset-branch-id="presetBranchId" :branches="branches" :categories="categories" @close="formOpen = false" />`, `<MoveMoneyModal :show="moveOpen" :accounts="movable" :from-id="moveFrom" @close="moveOpen = false" />`, `<ConfirmModal :show="!!deleting" :title="t('caisses.delete_title')" :message="t('caisses.delete_message')" :confirm-label="t('delete')" @confirm="confirmDelete" @cancel="deleting = null" />` (check ConfirmModal's danger/variant prop name and use it).

Transfers' `from_account` / `to_account` are serialized snake_case by Inertia (`fromAccount` relation → `from_account`): verify in the browser devtools or with a quick `dd` in a test and use the right key.

- [ ] **Step 6: Sidebar** — `useNavigation.js` finance section items:

```js
                { label: t('transactions'), href: '/transactions', icon: 'transactions', prefix: '/transactions', module: 'transactions' },
                { label: t('cash_registers'), href: '/finance/registers', icon: 'wallet', prefix: '/finance/registers', module: 'finance' },
                // Not highlighted on the Caisses page, which has its own entry.
                { label: t('finance'), href: '/finance', icon: 'money', prefix: '/finance', match: /^\/finance(?!\/registers)/, module: 'finance' },
```

(Check `lib/navigation.js` `isActive` uses `item.match` against the path without query string; adjust the regex if it receives the full URL.)

- [ ] **Step 7: Build and test**

Run: `npm run build`, `npm run i18n:check`, `php artisan test --filter="CaissesPageTest|CashRegisterManagementTest|Navigation|Sidebar"`.
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Components/Finance/CaisseFormModal.vue resources/js/Components/Finance/MoveMoneyModal.vue resources/js/Pages/Finance/Registers.vue resources/js/Composables/useFinanceAccountLabel.js resources/js/Composables/useNavigation.js resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(finance): one Caisses page — coffres with their caisses, move-money and caisse modals, sidebar entry"
```

---

### Task 6: Remove the duplicates from Settings and the Finance page

**Files:**
- Modify: `app/Http/Controllers/FinanceController.php::settings` (drop `accounts`, `branches` props)
- Modify: `resources/js/Pages/Finance/Settings.vue` (accounts section → link; year actions use `ConfirmModal`)
- Modify: `resources/js/Pages/Finance/Index.vue` (close/reopen buttons → link to Settings; `window.confirm` gone)
- Test: `tests/Feature/FinanceSeasonPageTest.php` or a new `tests/Feature/FinanceSettingsPageTest.php`

- [ ] **Step 1: Failing test**

```php
    #[Test]
    public function finance_settings_no_longer_lists_accounts(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.settings'))
            ->assertInertia(fn ($page) => $page->missing('accounts')->missing('branches')->has('years')->has('categories'));
    }
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

- `FinanceController::settings`: remove the `accounts` and `branches` entries (and now-unused imports).
- `Settings.vue`: delete the accounts section and its script (account form, `addAccount`, `deleteAccount`, `bank` option); in its place a small card: title `t('cash_registers')`, text `t('caisses.subtitle')`, `IconButton :href="route('finance.registers.index')" icon="wallet" :label="t('caisses.manage')"`. Replace `window.confirm` in `closeYear`/`reopenYear`/`deleteYear` with one `ConfirmModal` driven by a `pending = ref({ action, year })` state (title/message from the existing `close_year_confirm`, `reopen_year_confirm`, `delete_fiscal_year_confirm` keys). Back arrow `rtl:rotate-180`.
- `Finance/Index.vue`: remove `closeYear`/`reopenYear` and their two `IconButton`s; in the open/closed banner add `IconButton v-if="can('finance','edit')" :href="route('finance.settings')" icon="settings" :label="t('finance_settings')" plain size="sm"`. Remove the now-unused `router` import if nothing else uses it.

- [ ] **Step 4: Run** `npm run build`, `php artisan test --filter="Finance"`, `npm run i18n:check` — PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/FinanceController.php resources/js/Pages/Finance/Settings.vue resources/js/Pages/Finance/Index.vue tests/Feature/FinanceSettingsPageTest.php
git commit -m "refactor(finance): caisses live on one page; seasons close from Settings only"
```

---

### Task 7: Verification

- [ ] **Step 1:** `php artisan test` — all green.
- [ ] **Step 2:** `vendor/bin/pint --test` on changed PHP files; `npm run i18n:check`; `npm run build`.
- [ ] **Step 3: Leftovers grep** — `grep -rn "forCategory\|registerName\|is_opening_fund" app resources/js` and review each hit is intended; `grep -rn "window.confirm" resources/js/Pages/Finance` → none.
- [ ] **Step 4: Browser QA (controller does this, not a subagent):** with a copy of the live data, in ar (RTL) and fr, desktop and phone widths: Caisses page shows Football coffre (own 13 610, total 96 610) with "اكابر"/Senior caisse 83 000, swimming coffre with its two caisses, club level "صندوق استثمار"; Fonds Initial hidden (balance 0); add caisse linked to Cadet; move money modal shows before/after; delete of used caisse refused; sidebar highlights correctly.
- [ ] **Step 5:** commit any fixes (explicit paths) and report.
