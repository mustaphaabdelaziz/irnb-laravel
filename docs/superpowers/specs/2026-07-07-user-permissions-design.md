# User Permissions (Roles + Per-Module Access) — Design

**Date:** 2026-07-07
**Status:** Approved (brainstorm)

## Goal

Replace the coarse all-or-nothing `admin` gate with fine-grained access control.
An administrator (superadmin) defines **roles** with a per-module × per-action
permission matrix, assigns a role to each user, and optionally overrides
individual cells per user. Menus and actions the user lacks are hidden and
server-enforced.

## Decisions (locked)

| Question              | Decision                                                                           |
|-----------------------|------------------------------------------------------------------------------------|
| Grant model           | **Roles + per-user override** (reusable roles, optional per-user tweaks)            |
| Actions               | **4 canonical**: `view`, `add`, `edit`, `delete`. Specials fold into `edit`        |
| Default (no role)     | **Deny-by-default** — profile + dashboard only; nothing else until a role is granted |
| Resolution            | **Live role + overrides** — editing a role updates everyone holding it instantly   |
| Role administration   | **Superadmin only** manages roles and assigns them                                 |

---

## Modules (11)

The permission-controlled menus. Dashboard and own-profile are always visible.

| Module          | Covers (route-name prefixes)                                                            |
|-----------------|-----------------------------------------------------------------------------------------|
| `players`       | `players.*` incl. import, card, and per-player transactions                              |
| `subscriptions` | `subscriptions.*`                                                                        |
| `transactions`  | `transactions.*`                                                                         |
| `finance`       | `finance.*` (index, settings, years, budget, finance-categories, accounts)              |
| `reports`       | `reports.*` (financial summary and other standalone reports)                            |
| `equipment`     | `equipment.catalogs.*`, `equipment.items.*`, `equipment.inventory`                       |
| `inventory`     | `inventory.*` (stock-take sessions)                                                     |
| `board`         | `board.*`, `board-roles.*` (governance: board, meetings, tasks, calendar, terms, members)|
| `users`         | `users.*` (member administration + approvals)                                           |
| `categories`    | `categories.*`, `jobs.*`, `positions.*`, `equipment-categories.*`, `storage-locations.*` |
| `settings`      | `settings.*` (website config, theme)                                                    |

`roles.*` (the new role manager) is **not** a module — it is guarded directly by
the superadmin check.

## Actions (4) and the derivation rule

Every mapped route resolves to `[module, action]`. Action is derived from the
route-name suffix so the map stays small and consistent:

- **view** — `*.index`, `*.show`, `*.export`, `*.template`, `*.preview*`,
  `*.card`, `*.history`, `*.report`, `*.receipt`, `*.minutes`, `*.inventory`,
  `finance.index`, `reports.*`
- **add** — `*.store`, `*.import.store`
- **edit** — `*.update`, and every special verb:
  `*.approve`, `*.assign*`, `*.rent`, `*.return*`, `*.repair`,
  `*.complete-repair`, `*.mark-lost`, `*.close`, `*.reopen`, `*.counts`,
  `*.participants`, `*.attendance`, `*.attachment*`, `*.budget`, `finance.settings`
- **delete** — `*.destroy`, `*.attachment.delete`

Cross-module note: per-player transaction routes (`players.transactions.*`) map
to the **`players`** module (they live on the player page), not `transactions`.
Routes with no map entry (`dashboard`, `profile.*`, `lang.switch`,
`media.public`, auth) are always allowed.

---

## Section 1 — Data model

### `roles` table
```
id            bigint pk
key           string unique          // slug: superadmin, administrator, accountant…
name          json                   // { ar, fr, en } (matches app i18n convention)
permissions   json                   // { "players": ["view","edit"], "finance": ["view"] , … }
is_system     boolean default false  // superadmin + administrator; cannot be deleted
timestamps
```
Model `App\Models\Role` (fillable `key`, `name`, `permissions`, `is_system`;
casts `name`→array, `permissions`→array, `is_system`→bool). `hasMany` users.

### `users` table additions
```
role_id                nullable FK -> roles.id  (nullOnDelete)
permission_overrides   json nullable   // { "grant": {mod:[act]}, "revoke": {mod:[act]} }
```
`User belongsTo Role`. The legacy `privileges` column is **retained only** as the
superadmin god-marker (`in_array('superadmin', privileges)`). The `admin`
privilege value is retired — replaced by the seeded **Administrator** role.

### Effective-permission resolution
`User::effectivePermissions(): array` returns `{ module: [actions] }`:

1. **Superadmin** (`privileges` contains `superadmin`) → all modules × all actions.
2. Else start from `role?->permissions ?? []`.
3. Union `permission_overrides.grant`.
4. Subtract `permission_overrides.revoke`.
5. No role and no grants → empty (deny-by-default).

`User::hasPermission(string $module, string $action): bool` reads the resolved
matrix. Result is memoized per request.

---

## Section 2 — Backend enforcement

- **Route→permission map**: `config/permissions.php` returns
  `['module_prefixes' => [...], 'action_rules' => [...]]` plus explicit
  overrides for the handful of cross-module / irregular routes. A small
  `PermissionMap` resolver turns a route name into `[module, action]` (or `null`
  = unguarded).
- **`permission` middleware** (single, no args): resolves the current route
  name via `PermissionMap`; if mapped, calls `hasPermission` and `abort(403)` on
  failure. Registered as an alias and appended to the existing
  `auth / verified / approved` group in `bootstrap/app.php`. This **replaces**
  the coarse `admin` middleware on module routes.
- **`Gate::before`**: superadmin → return `true` (god bypass, preserves existing
  behavior). Role-manager routes (`/roles*`) stay behind a dedicated
  **superadmin-only** middleware (reuse/rename the current admin middleware or a
  new `EnsureUserIsSuperadmin`).
- Controllers additionally guard non-route-derived branches where needed (e.g. a
  single controller action that both lists and exports).

---

## Section 3 — Frontend

- **Shared props** (`HandleInertiaRequests`): add
  `auth.permissions` (the effective matrix), `auth.isSuperadmin`; redefine
  `auth.isAdmin` as `isSuperadmin || can('users','view')` so existing
  admin-only UI checks (e.g. `pendingApprovals`) keep working during transition.
- **`useCan()` composable** → `can(module, action)` reads `auth.permissions`;
  superadmin short-circuits to `true`.
- **Sidebar** (`AuthenticatedLayout.vue`): each nav item gains a `module` field.
  Items filtered by `can(module, 'view')`; sections with no visible items are
  hidden. Removes the current hard-coded `isAdmin` branching.
- **Page-level buttons**: Add / Edit / Delete controls wrapped in
  `v-if="can(module, action)"` across the module pages.

---

## Section 4 — Admin UI (superadmin only)

- **Role manager** `/roles`:
  - `RoleController` (`index`, `store`, `update`, `destroy`), Inertia pages
    `Roles/Index.vue` + a create/edit form (modal or page).
  - Editor = **modules (rows) × [view/add/edit/delete] (checkbox columns)** grid,
    plus the localized role name. "Select all row / all column" conveniences.
  - System roles (`superadmin`, `administrator`) cannot be deleted; superadmin
    role is not editable down from full access.
- **User edit** (`Users/Edit.vue`): replace the current `user`/`admin`
  checkboxes with:
  - a **Role** dropdown (roles list + "No role"), and
  - a collapsible **Advanced → per-user override** grid showing the role's
    matrix with grant/revoke deltas highlighted.
  - Visible only to superadmin. Non-superadmin admins keep managing member
    profile data but not access.

---

## Section 5 — Migration, seed, backward-compat

- **Migrations**: create `roles`; add `role_id` + `permission_overrides` to
  `users`.
- **Seeder** (`RoleSeeder`, idempotent): system roles `superadmin` and
  `administrator` (all modules × all actions, `is_system = true`). Optional
  starter presets — `accountant`, `coach`, `receptionist` — as non-system,
  editable/deletable examples.
- **Data migration** (one-off, safe to re-run):
  - users whose `privileges` contain `admin` → assign **Administrator** role.
  - users whose `privileges` contain `superadmin` → keep the god-marker (and
    Administrator role for completeness).
  - all other users → `role_id = null` (deny-by-default, per decision).
- No destructive change to `privileges`; we simply stop writing `admin` to it.

---

## Section 6 — Testing (TDD)

**Unit — resolution (`User`/resolver):**
- role matrix returned as-is; `grant` unions; `revoke` subtracts.
- superadmin → full matrix regardless of role/overrides.
- no role + no overrides → empty (deny-by-default).
- deleting a role nulls `role_id` and drops those users to deny-by-default.

**Feature — enforcement:**
- `permission` middleware allows a mapped route when the user has the action and
  `403`s when they do not (parametrized over view/add/edit/delete for a sample
  module).
- unmapped routes (dashboard/profile) always pass.
- sidebar/shared props expose exactly the effective matrix.

**Feature — admin UI + guards:**
- superadmin can CRUD roles; system roles undeletable.
- non-superadmin cannot reach `/roles` (403) nor assign roles.
- assigning a role changes a user's effective perms; editing the role
  propagates live.
- data migration maps a legacy `admin` user to Administrator with full access.

---

## Out of scope (YAGNI)

- Per-record / ownership permissions (row-level).
- Multiple roles per user (single role + overrides covers the stated need).
- Per-module custom action lists beyond the canonical 4.
- Time-boxed or delegated grants.
