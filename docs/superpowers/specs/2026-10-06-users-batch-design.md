# Users batch — October 2026

Owner request (2026-10-06): rename "members" to users, computed name, searchable role,
required-field marks, "log in as" a user, richer activity statistics.

## Owner decisions

| Topic | Decision |
|---|---|
| Who may log in as a user | Admin + superadmin (the `admin`/`superadmin` privileges, i.e. `isGodAdmin()`) |
| Write mode while logged in as someone | Full access; every recorded event also stores the real person (`impersonator_id`) |
| Name | Computed from firstname + lastname; both required on the users forms and profile |
| Extra activity | Edits + deletes, logins/logouts, user & access administration, settings changes |
| Charts | Activity per day by area, share by area, top users, top actions |

## Defaults chosen (not owner-asked)

**Rename.** Only where "members" means app accounts: sidebar entry, users list title, activity
back-link, roles card count, and the sidebar role caption (`member` → `user`). Branches and Jobs keep
"members" (players). Uses the existing `users`/`user` keys (Users / Utilisateurs / المستخدمون).

**Name.** `users.name` stays as a stored column (sorting, reports, search) but is never typed: a model
`saving` hook sets it to "firstname lastname" whenever either part is filled. A migration splits the
current name of users with no firstname (first word → firstname, rest → lastname). Lastname may stay
empty for such old one-word names until someone edits the user, then it is required.

**Role field.** `SearchableSelect` (existing component) on create and edit.

**Required marks.** `InputLabel` gains a `required` prop that renders a red asterisk.

**Log in as.**
- `POST /users/{user}/impersonate` (`users.impersonate`), allowed for `isGodAdmin()` actors only.
- Refused: yourself, a superadmin target, an inactive or unapproved target, and while already
  logged in as someone (no chains).
- The session keeps `impersonator_id`; `POST /impersonate/leave` (`impersonate.leave`, auth only)
  returns to the original account. A banner on every page says "Viewing as X — back to my account".
- Logging out while impersonating ends the whole session.
- Recorded as `user_impersonated` by the real person, subject = target.

**Activity storage.** `activity_logs.impersonator_id` (nullable FK users, nullOnDelete). The recorder
fills it from the session. Entries show "by X as Y".

**Recording edits, deletes and settings.** A route-name map `config/activity.php` (route → action
code, optional `kind` and `count` source) plus one `RecordRouteActivity` middleware in the
authenticated group. After the response, it records only when the request succeeded: status < 400,
no validation errors and no `error` flash. The subject is the most specific (last) bound Eloquent model of the route.
This keeps the P5 rule's intent — imports, migrations, seeders and console fixes never pass through
web routes, so nothing is inflated — without editing ~60 controllers. Routes that already record an
explicit event are left out of the map. Logins and logouts are recorded explicitly in the session
controller (`logged_in_at` is updated too).

New areas: `access` (logins, users, roles, profile) and `settings` (club settings and reference data).
Reference-data changes share three codes — `setting_item_created/updated/deleted` — with a `kind`
property (the i18n key of the list: `categories`, `branches`, …).

**Charts.** Chart.js through the existing `ChartCard`. Per-day buckets (per week beyond 92 days).
Built from GROUP BY queries (`DATE(occurred_at)`), never per-event loops.
