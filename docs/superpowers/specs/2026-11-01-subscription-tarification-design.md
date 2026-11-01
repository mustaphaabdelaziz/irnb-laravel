# Subscription tarification — design

Date: 2026-11-01. Status: approved by owner (decision rounds 2026-11-01), branch `feat/subscription-tarification` (worktree `D:\irnb-tarification`).

## 1. Goal

When setting a subscription's price, the owner sees whether that price pays the coach, the staff and transport: win, even or lose, and the price or player count that breaks even.

It is a forecast. Nothing here records an expense or creates a transaction; paying a coach stays a normal transaction.

## 2. What exists and what is missing

| Need | Today |
|---|---|
| Price per player | `Subscription::amountFor` (student/worker rate, per-category override) |
| Who may be charged | `Player::scopeEligibleFor`, archived players left out |
| Money really paid | `player_subscriptions.amount_paid`, `PlayerSubscription::money()` |
| Sessions per week | `TrainingSchedule` (dev DB has 0 rows, so it can only be a default) |
| Coach pay | Nothing. `training_sessions.coach` is a name; `team_coaches` has no money field |
| Transport cost | Nothing |

## 3. Owner decisions

| Question | Decision |
|---|---|
| Where | Section on each subscription, saved with it |
| Real money | Forecast plus real income. Real expenses are not used: an expense has no link to a subscription |
| Sessions | Sessions/week × weeks, default from the training schedule |
| Transport and other costs | Free cost lines with a unit |
| Players | Auto count, can be overridden |
| Unpaid | Expected payment % field, real rate shown beside it |
| Several categories | One cost list and one result per subscription |
| Access | Seeing needs Finance → view; changing also needs Subscriptions → edit or add |

Added by Claude and shown to the owner with the design: a **per player** unit (insurance, a t-shirt on an exceptional subscription).

## 4. Data

Two new tables, not columns on `subscriptions`. Subscriptions are serialised to many pages; separate tables keep coach pay out of every prop unless it is loaded on purpose.

`subscription_tarifications`, one row per subscription:

| Column | Type | Meaning |
|---|---|---|
| `subscription_id` | FK, unique, cascade on delete | |
| `sessions_per_week` | unsigned tiny int, nullable | Blank = the schedule's count |
| `weeks` | unsigned tiny int, default 40 | |
| `expected_players` | unsigned int, nullable | Blank = the auto count |
| `expected_payment_rate` | unsigned tiny int, default 100 | Percent, 0–100 |

`subscription_cost_lines`, many per subscription:

| Column | Type | Meaning |
|---|---|---|
| `subscription_id` | FK, cascade on delete | |
| `label` | string(100) | "Coach Cadet", "Bus" |
| `unit` | string(20) | `per_session`, `per_trip`, `per_month`, `per_season`, `per_player` |
| `amount` | decimal(12,2) | |
| `quantity` | unsigned int, default 1 | People, trips, months or count, by unit |
| `sort_order` | unsigned int | Order typed in the form |

Blank means "use the automatic value", the same convention as the category price overrides. Models: `SubscriptionTarification`, `SubscriptionCostLine`, both with `SerializesClubDates` (guard test `ModelsSerializeClubDatesTest`). `Subscription` gets `tarification()` and `costLines()`, never added to `$with` or `$appends`.

`FinanceResetService` deletes subscriptions and their pivots explicitly, so it deletes both new tables explicitly too.

## 5. The calculation

One pure module, `resources/js/lib/tarification.js`, tested by `npm run test:js`. There is no PHP copy: the result must react on each keystroke, and nothing on the server needs it.

Inputs: cost lines, sessions, players `P`, payment rate `r` (0–1), average price `A`.

```
line cost   per_session  amount × quantity × sessions
            per_player   amount × quantity × P
            other units  amount × quantity

F (fixed)   sum of every line except per_player
V           sum over per_player lines of amount × quantity
Income      P × A × r
Costs       F + V × P
Margin      Income − Costs
```

| Output | Formula | When undefined |
|---|---|---|
| Status | win if margin > 0, lose if < 0, otherwise even | |
| Margin per player | Margin ÷ P | P = 0 |
| Break-even price | (F + V×P) ÷ (P×r), rounded up to 1 | P = 0 or r = 0 |
| Break-even players | F ÷ (A×r − V), rounded up to a whole player | A×r ≤ V: the page says no number of players can cover the costs |

If F and V are both 0 the section shows the prompt to add costs, not a win.

**Sessions** = sessions/week × weeks. Exceptional subscriptions have no sessions: those fields and the `per_session` unit are hidden, and the request refuses `per_session` for them.

**Auto player count and `A`** come from roster groups the server sends, grouped by category, student flag and branch set with a count, so a big club stays a small prop and a player in two branches counts once. In the browser a group matches when the subscription has no categories or includes its category, and has no branches or shares one. A group's price is the category override if set, else the default: the same result as `amountFor`, recalculated so it follows unsaved edits in the form. `A` is the count-weighted average. With no matching players, `A` is the mean of the two default prices.

Roster groups leave out archived players and, on an existing subscription, players marked exempt on it. Discounts and hand-added players from another category are not forecast; the override covers them.

**Default sessions/week** comes from schedule rows the server sends: rows with `valid_to` empty or today or later, whose branch is empty or one of the chosen branches. They are counted per branch and category, and the highest count among chosen categories is used. The highest, not the sum, because lines are per group. With no schedule rows the default is 0 and the section says so.

## 6. Screens

**Create and Edit form** (`SubscriptionForm.vue`): a Tarification card below the existing one, in its own component `Subscriptions/Partials/TarificationCard.vue`.

- Inputs: sessions/week and weeks, expected players, expected payment %. Blank inputs show the automatic value as placeholder, like category prices.
- Cost lines: label, unit, amount, quantity, computed total, remove button, "add line" button. The quantity label follows the unit (people, trips, months, count; hidden for per player).
- Result panel, updated live as prices, categories, branches or costs change: expected income, each line, total costs, win/lose with margin and margin per player, break-even price and players.
- Real rate beside the payment % on Edit: collected ÷ the net owed by non-exempt assigned players. "No payments yet" while it is 0.

**Subscription page** (`Show.vue`): a read-only card with the same result panel, plus paid so far and a link to the edit form. With no costs saved, it shows a one-line prompt instead, the link only for users who may edit.

The status never relies on colour alone: the word win/even/lose is always shown. Money uses `useCurrency` and `formatNumber`. Layout works in RTL, dark mode and phone width.

## 7. Server

`App\Services\Finance\TarificationData::for(?Subscription)` builds the prop: roster groups, schedule rows, saved values, money collected and the real rate. Its queries select only the columns needed, and it never loads the relations onto the subscription, which would serialise them into the `subscription` prop.

Controller changes:

- `create`, `edit`, `show` pass `tarification` only when the user has Finance → view. Without it the prop is absent and the card does not render.
- `store`, `update` save the block inside the existing `DB::transaction`: `updateOrCreate` the settings row, then replace the cost lines in order.

`StoreSubscriptionRequest` rules for `tarification` (only when the user has Finance → view):

| Field | Rule |
|---|---|
| `tarification` | nullable array |
| `sessions_per_week` | nullable integer, 0–21 |
| `weeks` | nullable integer, 0–53; blank = 40 |
| `expected_players` | nullable integer, 0–100000 |
| `expected_payment_rate` | nullable integer, 0–100; blank = 100 |
| `lines` | array, at most 100 |
| `lines.*.label` | required string, max 100 |
| `lines.*.unit` | in the five units; not `per_session` when the subscription is exceptional |
| `lines.*.amount` | required numeric, 0–9999999999.99 |
| `lines.*.quantity` | required integer, 1–100000 |

A save from a user without Finance → view ignores the block, so costs stay as they were. If the block has no lines and every setting is still at its default, no rows are written.

If an existing subscription is switched to exceptional, a `per_session` line keeps that option on screen, flagged with the validation error, until the owner changes or removes it.

Route permissions do not change: the form routes already need Subscriptions → add or edit.

## 8. Limits, told to the owner

- A coach shared by two subscriptions counts in full on each, unless only that subscription's share is entered.
- All `per_session` lines use one session count, so categories with different rhythms under one subscription need the quantity or amount adjusted by hand.
- With different student and worker prices, the break-even price is an average across players.
- No automatic copy of costs to next season's subscription.

## 9. Tests

- `tests/js/tarification.test.mjs`: the worked example below, every unit, per-player costs, 0 players, 0%, all-free price, no lines, roster matching (no category, no branch, several branches), category overrides, schedule default.
- Feature test: saving and replacing lines, order kept, cascade on delete, validation, `per_session` refused on exceptional.
- Feature test: no `tarification` prop without Finance → view on create, edit and show; a save without it leaves costs untouched.
- `FinanceResetService` test: both tables emptied.
- `npm run i18n:check`, all three languages. Keys under `tarification.*`, inserted without reformatting the files; no segment named after a String method (`link`, `search`).

Worked example, the owner's rates with assumed volumes: 20 players at 15,000 at 85%, head coach 800 × 1, assistants 700 × 2, 120 sessions, bus 15,000 × 10 trips. Income 255,000; costs 414,000; loss 159,000, or 7,950 per player; break-even price 24,353; break-even players 33.

## 10. Deploy

`php artisan migrate` (two new tables, no data change) and `npm run build`. Desktop backs up its database before pending migrations as usual.
