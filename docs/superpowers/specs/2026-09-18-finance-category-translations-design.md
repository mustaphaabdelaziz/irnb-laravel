# Finance category translations — design

**Date:** 2026-09-18
**Status:** Approved (layout: expand-row editing)

## Problem

Financial categories (`finance_categories`, managed from /transactions → "Manage categories")
store a single `name`. Switching the app language (ar / fr / en) leaves every category label in
whatever language it was typed in. Player categories, branches and player statuses already solve
this with per-locale columns; finance categories do not.

## Goal

Every place a finance category name is shown follows the active app language, falling back to the
base `name` when a translation is empty. Users can enter translations from the Manage categories
modal.

## Approach

Reuse the existing per-locale column pattern (`App\Models\Category`, `Branch`, `PlayerStatus`):

- `name` stays the base/default name, the uniqueness key (per `type`) and the source of the legacy
  `transactions.category` slug. Nothing that reads `name` today breaks.
- New nullable columns `name_ar`, `name_fr`, `name_en`.
- Appended accessor `localized_name` = `name_{locale}` ?: `name`, resolved server-side from the
  locale set by `SetLocale` middleware. A language switch reloads the page, so labels re-render.

Rejected: i18n keys per category `code` (cannot translate user-created categories); a JSON
`translations` column (diverges from the codebase pattern).

## Changes

### Database

Migration `add_locale_names_to_finance_categories`:

- Add `name_ar`, `name_fr`, `name_en` (nullable string) after `name`.
- Backfill translations for the built-in categories, only into columns that are still null.
  Match by `code` first, otherwise by case-insensitive `name` against the seeded English name or a
  singular alias ("Subscription", "Donation", …). Unmatched custom categories are left untouched.
- `down()` drops the three columns.

Built-in translations (en / fr / ar):

| Code | English | Français | العربية |
|------|---------|----------|---------|
| SUB | Subscriptions | Abonnements | اشتراكات |
| MEM | Membership Fees | Cotisations | رسوم العضوية |
| DON | Donations | Dons | تبرعات |
| GRT | Grants / Subsidies | Subventions | منح / إعانات |
| ARR | Arrears / Debts | Arriérés / Dettes | متأخرات / ديون |
| EVR | Events Revenue | Recettes des événements | إيرادات التظاهرات |
| OIN | Other Income | Autres recettes | إيرادات أخرى |
| EQP | Equipment | Équipement | معدات |
| MNT | Maintenance & Repairs | Maintenance et réparations | صيانة وإصلاحات |
| SUP | Supplies | Fournitures | لوازم |
| WRK | Works / Construction | Travaux / Construction | أشغال / بناء |
| UTL | Utilities | Charges (eau, électricité) | فواتير الخدمات |
| SAL | Salaries / Wages | Salaires | رواتب وأجور |
| EVE | Events Expenses | Dépenses des événements | مصاريف التظاهرات |
| ADM | Administrative | Frais administratifs | مصاريف إدارية |
| OEX | Other Expense | Autres dépenses | مصاريف أخرى |

### Backend

- `FinanceCategory`: add the three columns to `$fillable`, append `localized_name` with the same
  accessor as `Category`.
- `FinanceCategoryController::validateData`: accept `name_ar`, `name_fr`, `name_en`
  (`nullable|string|max:120`).
- Explicit column lists that feed the UI include the locale columns so the accessor works:
  `TransactionController` index filter list and `formOptions()`
  (`get(['id','type','name','name_ar','name_fr','name_en','color'])`).
- `TransactionController::show`: eager-load `financeCategory` so the detail page can show the
  localized label instead of the legacy slug.
- `TransactionController::export`: category column uses `localized_name`.
- `FinanceController::yearDetail`:
  - category breakdown query also selects/groups `c.name_ar, c.name_fr, c.name_en`, then maps each
    row to a localized `name` (same fallback) before returning;
  - budget-vs-actual rows use `$c->localized_name`.

### Frontend

- `Components/CategoryManager.vue` (expand-row editing):
  - Each row: color swatch, `localized_name` label, ✎ edit button, × delete.
  - ✎ expands the row in place with inputs: Default (`name`, required), العربية, Français,
    English, plus Save / Cancel. Only one row open at a time. Color still saves on change.
  - Add form: name + color + "+" as today, with a collapsible "Translations" toggle revealing the
    three locale inputs.
  - Validation errors keep using the existing error banner.
- Display `localized_name || name` instead of `name` in:
  - `Pages/Transactions/Index.vue` (income/expense filter options, table badge)
  - `Pages/Transactions/Partials/TransactionForm.vue` (category select)
  - `Pages/Transactions/Show.vue` (category field, falls back to legacy `transaction.category`)
  - `Pages/Finance/Settings.vue` (budget grid, chart-of-accounts lists)
- `Pages/Finance/Index.vue` needs no change: it already reads `r.name` / `b.name`, which the
  backend now localizes.
- i18n: new key `translations` in `en.json`, `fr.json`, `ar.json` (verified by
  `npm run i18n:check`).

## Error handling

- Empty translation → falls back to `name`; never shows a blank label.
- Base `name` stays required and unique per type; translations are not unique-checked.

## Testing

Feature tests (Pest/PHPUnit, alongside `tests/Feature/TransactionFinanceCategoryTest.php`):

1. `localized_name` returns the locale column for ar / fr / en and falls back to `name` when empty.
2. Store and update persist `name_ar` / `name_fr` / `name_en`.
3. Transactions index props expose `localized_name` for the active locale.
4. Finance dashboard breakdown returns the localized name for the active locale.

Plus `npm run i18n:check` and `npm run build`.

## Out of scope

- Translating free-text transaction descriptions.
- Porting to the generic `D:\sportclub-laravel` template (separate follow-up).
