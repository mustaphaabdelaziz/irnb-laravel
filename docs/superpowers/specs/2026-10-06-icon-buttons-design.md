# Icon buttons that reveal their name on hover — design

Date: 2026-10-06 · Branch: `feat/icon-buttons`

## Ask
"Change buttons to icons; when the user hovers, it changes to the name — everywhere in the app."

## Decisions (owner, 2026-10-06)
1. **Hover style: expand inline.** Icon-only at rest; on hover / keyboard focus the button grows and the name slides in beside the icon. `title` + `aria-label` carry the name for touch and screen readers.
2. **Scope: actions, not form submits.** Converted: table/card row actions, page-header actions (New X, export, print, import), toolbars, bulk-action bars, clear-filters, view toggles. **Kept as text:** Save / Cancel / Confirm / Submit in forms and modals, login, pagination, tab/segment controls whose label *is* the content (Active/Archived), dropdown menu items, sidebar/nav links.
3. **Approach: shared `IconButton` component** (`resources/js/Components/IconButton.vue`) — renders `<button>`, Inertia `<Link>` (`href`) or `<a>` (`href` + `external`).
4. **Destructive actions** become icon-only too, red (`variant="danger"`); their confirm modals keep full text.

## Component API
| prop | values |
|---|---|
| `icon` | name from `Icon.vue` |
| `label` | translated name (required) |
| `variant` | `neutral` (default) · `primary` · `danger` · `success` · `ghost` |
| `plain` | no ring/background — dense rows |
| `size` | `md` (default, h-9) · `sm` (h-7) |
| `href` / `external` / `method` / `as` | link modes |
| `pressed` | toggle state (view switchers) |

Icon mapping: details→`eye`, edit→`pencil`, delete→`trash`, restore→`restore`, add/new→`plus`, print→`print`, export/download→`download`, import/upload→`upload`, archive→`archive`, clear filters→`filteroff`, copy/duplicate→`copy`, log-in-as→`login`, password→`key`, erase marks→`eraser`, close→`close`, send→`send`.

Reference conversion: `resources/js/Pages/Players/Index.vue`.
