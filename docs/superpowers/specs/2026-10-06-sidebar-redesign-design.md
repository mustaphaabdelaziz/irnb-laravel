# Sidebar redesign — design

Date: 2026-10-06 · Branch: `feat/sidebar-redesign` (from `main` @ dc9f323)

## Problem

The sidebar lists up to 26 links in 7 always-open sections. Users scroll it to reach
the lower pages. Two section names are wrong: "الإدارة / Administration" holds the
lookup lists that configure the app's entities, and "Governance / الحوكمة" is the
club's board of directors (مجلس إدارة النادي).

## Decisions (owner, 2026-10-06)

| Topic | Decision |
|---|---|
| Expand mode | Smart accordion: the section holding the current page is forced open; others open/close freely; state remembered |
| Configuration section name | AR تهيئة التطبيق · EN App Configuration · FR Paramétrage |
| Board section name | AR مجلس إدارة النادي · EN Board of Directors · FR Bureau du club |
| Board hub item | Renamed AR نظرة عامة · EN Overview · FR Vue d'ensemble |
| Settings + Backup | Move to a new System section with Users, Activity, Roles |
| Extras in this batch | Quick search (Ctrl+K), preload on hover, icon-only narrow mode |

## Structure

Dashboard is a standalone link with no section header. Item gating (`module`,
`always`, `superadminOnly`, `desktopOnly`, `badge`, `match`, `exact`) is unchanged
from today; empty sections are still dropped.

| Section key | Icon | AR / EN / FR | Items (in order) |
|---|---|---|---|
| — | dashboard | — | Dashboard |
| `members` | players | الأعضاء / Members / Membres | Players, Subscriptions, Attendance |
| `finance` | money | المالية / Finance / Finances | Transactions, Finance |
| `equipment` | equipment | المعدات / Equipment / Équipement | Equipment (catalogs), Equipment out, Inventory |
| `board` | board | مجلس إدارة النادي / Board of Directors / Bureau du club | Overview (`/board`, exact), Calendar, Meetings, Tasks |
| `config` | wrench | تهيئة التطبيق / App Configuration / Paramétrage | Categories, Branches, Positions, Player statuses, Document types, Jobs, Board roles, Equipment categories, Storage locations |
| `system` | settings | النظام / System / Système | Users (pending-approvals badge), Activity, Roles, Settings, Backup |

Moves vs today: Equipment categories and Storage locations leave Equipment for
`config`; Settings and Backup leave Administration for `system`; the "Users & Access"
section becomes `system`.

## Behaviour

### Smart accordion

- Section header: icon, label, chevron. The chevron rotates when open and mirrors in RTL.
- Header is a `<button>` with `aria-expanded` and `aria-controls`.
- Open state per section = `forcedOpen || userState[key]`, where `forcedOpen` is
  true when the section contains the active item. A forced-open section cannot be
  closed while you are on one of its pages (the click is ignored; the chevron shows open).
- `userState` defaults to all closed. It is a `{ [key]: boolean }` map kept in module
  scope (survives Inertia remounts) and persisted to `localStorage` key
  `sidebar.sections`. Every read and write is wrapped in try/catch; on failure the
  sidebar still works with the defaults.
- Closed section indicators: a small primary dot when it holds the active page
  (only possible in the narrow mode, since forced-open applies otherwise), and the
  sum of its item badges (the pending approvals count on `system`).
- Expand/collapse animates height with the `grid-template-rows: 0fr → 1fr` technique,
  200 ms ease-out. `motion-reduce:transition-none` disables it.
- The existing sidebar scroll position memory stays.

### Quick search (command palette)

- Opened by a search button at the top of the nav ("Search…" + `Ctrl K` hint), or by
  Ctrl+K / ⌘K anywhere in the authenticated layout. The shortcut calls
  `preventDefault` so the browser's own Ctrl+K does nothing.
- Modal with one text input and a result list. Results come from the same
  permission-filtered navigation as the sidebar, so it never lists a page the user
  cannot open.
- Each result shows the item icon, label, and section name. Matching is
  case-insensitive substring against `label + ' ' + sectionLabel` in the current
  locale, after normalising: lowercase, strip Latin diacritics (NFD), unify
  أ إ آ ٱ → ا, ة → ه, ى → ي, and remove Arabic tashkeel and tatweel.
- Empty query shows all items in sidebar order. No match shows a "No results" line.
- ↑/↓ moves the highlight (wraps), Enter visits the highlighted item and closes,
  Esc or a click on the backdrop closes. Focus returns to the element that opened it.
- No network calls; works offline.

### Preload on hover

- `SidebarLink` passes `prefetch` with `cacheFor: '30s'` to Inertia's `<Link>`
  (Inertia v2 hover prefetch, which waits 75 ms before fetching).
- Command palette results prefetch the highlighted item the same way.
- Inertia drops prefetched pages after a non-GET visit, so a page is never shown stale
  after a form save.

### Icon-only narrow mode

- Applies at `lg` and up. A toggle button in the sidebar footer switches between
  wide (`w-64`) and narrow (`w-16`). The main column offset follows (`lg:ms-64` /
  `lg:ms-16`) with a 200 ms transition.
- Persisted to `localStorage` key `sidebar.narrow` with the same try/catch rule.
- In narrow mode the nav shows: a search icon, the Dashboard icon, then one icon
  per section. Section icons show the active dot and badge sum described above.
- Hovering a section icon (after 100 ms) or clicking or focusing it opens a flyout
  panel to the side (`end` side, so it mirrors in RTL) with the section title and its
  links. The flyout closes on mouse leave (150 ms grace), Esc, outside click, or after
  a navigation.
- In narrow mode the crest shows only the logo, and the footer shows the avatar,
  a compact language switcher, and the toggle.
- Below `lg`, the mobile drawer always renders the wide layout, whatever the stored
  preference.

## Code structure

Frontend only. No routes, controllers or migrations change.

- `resources/js/Layouts/navigation.js`: exports `useNavigation()`. Returns
  `{ dashboard, sections }` (computed, permission-filtered, translated), plus
  `isActive(item)`. It is the single source for the sidebar and the palette.
- `resources/js/Composables/useSidebarState.js`: `userState`, `narrow`, and
  `toggleSection(key)` / `toggleNarrow()`. Module-scoped refs with safe
  `localStorage` persistence.
- `resources/js/Components/Sidebar/SidebarSection.vue`: header plus collapsible list.
- `resources/js/Components/Sidebar/SidebarFlyout.vue`: narrow-mode section icon
  plus flyout.
- `resources/js/Components/CommandPalette.vue`: the modal search and the global
  Ctrl+K listener (added on mount, removed on unmount).
- `resources/js/Components/SidebarLink.vue`: adds prefetch, plus a `compact` prop for
  the flyout.
- `resources/js/Layouts/AuthenticatedLayout.vue`: replaces the inline `sections`
  array and nav markup with the pieces above.

## i18n

New keys in `ar.json`, `en.json` and `fr.json` (flat dotted keys, `t()` only; see the
project's i18n notes):

- `nav.config`, `nav.system`, `nav.board_of_directors`, `nav.board_overview`
- `nav.search`, `nav.search_placeholder`, `nav.no_results`
- `nav.collapse_sidebar`, `nav.expand_sidebar`

The existing `nav_governance`, `administration` and `nav_access` keys are no longer
used by the sidebar. They are removed only if `npm run i18n:check` and a grep show no
other users. `npm run i18n:check` must pass.

## Testing

- Pest feature test (existing pattern): an authenticated page responds 200. No
  backend behaviour changes, so there are no new backend tests.
- No JS test runner is configured, and this batch does not add one. These are checked
  by hand:
  - Search normalisation: "اعدادات" matches "الإعدادات"; "parametrage" matches
    "Paramétrage".
  - `useNavigation` filtering: a user with only `board:view` in `config` sees only
    Board roles there; a user with no visible item in a section does not see that
    section.
- Manual QA at 127.0.0.1:2026 in all three locales (Arabic for RTL):
  - Forced-open section follows navigation.
  - Opened and closed state survives a reload.
  - Narrow mode flyouts work, and the stored narrow preference is ignored on mobile.
  - Ctrl+K works, including the keyboard flow.
  - Hover prefetch shows in the network tab.
  - The pending-approvals badge shows on a closed System section.
- `npm run build` is clean.

## Out of scope

- Reordering items within sections beyond the table above.
- Per-user, server-stored sidebar preferences.
- Searching records (players and so on) from the palette. Pages only.

## Merge note

`feat/users-batch-oct` changes `AuthenticatedLayout.vue` (impersonation banner, about
10 lines in the main column). A small, mechanical conflict is expected when the two
branches meet.
