# Help Center Part 2 — Subscriptions, Attendance, Finance, Settings & users

Date: 2026-11-08 · Status: approved in brainstorming · Builds on
`2026-11-08-help-center-design.md` (Part 1, merged 656cada).

## Goal

Extend the in-app manual with ~29 task articles for four modules, same style and
tooling as Part 1: Arabic, step cards with ringed/magnified screenshots, one
worked example per article, permission-filtered, offline.

## Decisions (owner, 2026-11-08)

| Topic | Decision |
|---|---|
| Modules | All four: Subscriptions & payments, Attendance, Finance & transactions, Settings & users. |
| Order | Four phases built in sequence: 2a Subscriptions → 2b Attendance → 2c Finance → 2d Settings & users. Each phase: its own plan, reviews, merge to local main. |
| Article list | As below (7 + 7 + 7 + 8). |
| Superadmin-only tasks | Visible to superadmins only (new `module: superadmin` value). |
| Left out | Finance reset gets no how-to (warning mention only); Backups (desktop + superadmin); simple settings lists (positions, venues, jobs…). |
| Push | Nothing pushed until the owner asks; owner reviews wording after each phase. |

## Articles

Slugs are kebab-case under `resources/help/ar/<topic>/`. `module` is the
article's gate unless the topic's default applies.

### 2a · topic `subscriptions` (topic module `subscriptions`)
1. `create-annual` — create an annual subscription (season, student/worker price, categories, branch scope, category price override).
2. `create-exceptional` — create an exceptional charge (no season, counts as debt).
3. `assign-players` — put players on a subscription (bulk by category/all, one player, outside-category players).
4. `take-payment` — record a payment from the player page (module `players`): one or several subscriptions, tick order = pay order, exempt, destination caisse, overpayment → donation, one grouped receipt.
5. `fix-payment` — edit/cancel a payment, discount or exempt an obligation (module `players`).
6. `previous-debt` — add a debt from before the app (module `players`).
7. `receipts` — print 80 mm / A4 receipt and verify a paper receipt (module `transactions`).

### 2b · topic `attendance` (topic module `attendance`)
1. `take-attendance` — mark a session (statuses, minutes, reasons, guests, save).
2. `add-session` — extra or pre-season session.
3. `month-grid` — fill a month from the paper sheet (codes like R15).
4. `cancel-delete-session` — cancel, erase marks, delete (irreversible warning).
5. `timetable` — weekly timetable and club closures (Settings; needs edit).
6. `codes-rules` — codes, colours, points, rules, alerts, custom codes.
7. `follow-up` — stats, ranking + certificates, alerts + parent letter, injuries.

### 2c · topic `finance` (topic module `transactions`; articles override)
1. `record-transaction` — income or expense (module `transactions`).
2. `find-export-import` — filters, export, import (module `transactions`).
3. `cancel-transaction` — archive one or many (module `transactions`).
4. `move-money` — transfer between caisses (module `finance`).
5. `caisses` — add / edit / deactivate a caisse (module `finance`).
6. `season` — fiscal season: open, opening balance, budget, close, reopen (module `finance`).
7. `dashboard-report` — finance dashboard + season PDF (module `finance`).

### 2d · topic `settings` (no topic module; every article sets its own)
1. `club-identity` — name, colour, logo (module `settings`).
2. `add-branch` — add a branch, main branch, members (module `branches`).
3. `add-category` — category inside a branch (module `categories`).
4. `branch-documents` — required documents per branch (module `document_types`).
5. `create-user` — staff account (module `users`).
6. `roles-rights` — roles matrix and per-user extra rights (module `superadmin`).
7. `manage-users` — approve, disable/enable, delete; password reset noted as superadmin-only (module `users`).
8. `log-in-as-activity` — log in as another user and read activity (module `users`).

## Framework changes (phase 2a only)

- `HelpLibrary::canSee`: module value `superadmin` → `$user->isSuperadmin()`.
  Feature test for a superadmin vs an admin with every module right.
- `resources/help/topics.php`: add `subscriptions` (icon `subscriptions`),
  `attendance` (icon `calendar`), `finance` (icon `money`, module
  `transactions`) in 2a–2c as each phase lands; `settings` (icon `settings`,
  module null) in 2d. Topic titles `help.topic.<slug>` in ar/fr/en.
- No other framework change.

## Demo club data

One focused seeder per module, called from `HelpDemoSeeder::run()` after the
existing data (the guard stays only in `HelpDemoSeeder`):
`HelpDemoSubscriptionsSeeder` (2a), `HelpDemoAttendanceSeeder` (2b),
`HelpDemoFinanceSeeder` (2c), `HelpDemoUsersSeeder` (2d). Data goes through the
app's own models/observers/services so totals, debts and balances are real
(`Subscription::assignTo`, `RecalculatePlayerDebtService`, `Transaction` +
`TransactionObserver`, `MarkRecorder::save`, `SessionGenerator`,
`FinanceService::recomputeAccountBalances`, `ReceiptPrinter::record`).

- 2a: caisse linked to a category; annual «اشتراك سنوي» current season
  (student 3000 / worker 5000, one category override); exceptional «رحلة
  صيفية»; assignments; full, partial, two-subscription grouped (shared
  `payment_group`) and overpayment-donation payments; unpaid debts; one previous
  debt; one discount; one receipt print.
- 2b: weekly schedules for 2 categories; held sessions with late (minutes),
  excused injury, a 3-unexcused streak, a not-training streak; one cancelled
  session; one closure; one pre-season target + session.
- 2c: ~15 incomes/expenses across months, caisses and methods; club bank
  account; 2 transfers; budget lines; previous season closed, current open.
- 2d: users (coach, accountant, pending approval, disabled, one with grant
  overrides, `logged_in_at`); one custom role; club logo; activity entries.

A seeder test per phase asserts the numbers the articles quote (counts, a debt,
a balance) so content and data can't drift silently.

## Writing rules (carried from Part 1 + its reviews)

Simple MSA, singular imperative, ≤ 15 words a sentence, one action per step
(outcomes, not extra clicks), every clicked element ringed, phone hint where a
step relies on gestures/sidebar/shortcuts, touchpad alongside mouse wheel, exact
labels from `ar.json` in «…», no emoji standing in for icons, tooltip-only text
not quoted as visible, every fact checked in code, ⚠️ warning for irreversible
actions, 3–7 steps, ≤ 1 tip, ≤ 1 warning, worked example followed through.

## Definition of done (per phase)

`php artisan help:check` OK (runs in the suite), `php artisan test --filter=Help`,
full suite (`php -d memory_limit=1G vendor/bin/phpunit` in a worktree without a
cached config), `node --test tests/js/*.mjs`, `npm run i18n:check`,
`npm run build`; light + dark screenshots generated and viewed; per-task and
whole-phase reviews clean; merged to local main; owner wording review.

## Risks

- Seeded payments/transactions violating rules (closed season dates, register
  activity) — seed through the real code paths; seeder test checks numbers.
- Long pages — shot list scrolls targets into view (script rejects off-screen
  boxes).
- Modal steps (payment, add session, move money, caisse form) — shot `before`
  hooks open the dialog before capture.
- Shared files across phases (`HelpDemoSeeder`, `shots.js`, `topics.php`, i18n)
  — phases are sequential, never parallel.
