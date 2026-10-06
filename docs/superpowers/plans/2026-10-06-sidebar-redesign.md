# Sidebar Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Group the sidebar into collapsible sections (smart accordion), rename the board and configuration sections, and add Ctrl+K quick search, hover prefetch, and an icon-only narrow mode.

**Architecture:** Pure helpers (filtering, active matching, search normalisation, safe storage) go in `resources/js/lib/` with no Vue or `@/` imports, so `node --test` can test them directly. Thin Vue pieces sit on top of them:
- `useNavigation`: the menu definition.
- `useSidebarState`: open/closed and narrow state, persisted.
- `SidebarSection`, `SidebarFlyout`, `CommandPalette`.

`AuthenticatedLayout.vue` only composes these pieces. Frontend only: no routes, controllers or migrations change.

**Tech Stack:** Vue 3.4 `<script setup>`, Inertia v2.3.18 (`<Link prefetch>`, `router.prefetch`, `router.flushAll`), vue-i18n 12 with flat keys, Tailwind 3.4 (logical `start-*`/`ms-*`, `rtl:` variant), Node 24 `node:test`.

**Spec:** `docs/superpowers/specs/2026-10-06-sidebar-redesign-design.md`

## Global Constraints

- Work only in the worktree `D:\irnb-sidebar` on branch `feat/sidebar-redesign`. Never touch `D:\irnb-laravel`, which holds the owner's uncommitted work.
- Stage explicit file paths only, never directories. After each commit, run `git show --stat HEAD` and check that the files and line counts match.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Offline only: no CDN, no remote fonts or scripts.
- i18n: flat dotted keys, read with `t()` only (never `te()`). All three catalogs `ar`, `en` and `fr` change together through `scripts/i18n-add.mjs`.
- Exact UI strings:
  - Configuration section: AR `تهيئة التطبيق`, EN `App Configuration`, FR `Paramétrage`.
  - Board section: AR `مجلس إدارة النادي`, EN `Board of Directors`, FR `Bureau du club`.
  - Board hub item: AR `نظرة عامة`, EN `Overview`, FR `Vue d'ensemble`.
  - System section: AR `النظام`, EN `System`, FR `Système`.
- `localStorage` keys: `sidebar.sections` and `sidebar.narrow`. Every read and write goes through try/catch (`lib/safeStorage.js`).
- Animations: 200 ms ease-out, with `motion-reduce:transition-none`.
- Prefetch: `cacheFor` is `'30s'`. The prefetch cache is flushed before any non-GET visit.
- RTL must work: use logical properties only (`start`/`end`, `ms`/`me`, `ps`/`pe`, `text-start`), never `left`/`right`.

---

## File Structure

| File | Status | Responsibility |
|---|---|---|
| `resources/js/lib/navigation.js` | create | Pure: `normalize`, `isActive`, `visibleSections`, `sectionHasActive`, `sectionBadge`, `flattenForSearch`, `searchItems` |
| `resources/js/lib/safeStorage.js` | create | Pure: `readJson`, `writeJson` with try/catch |
| `tests/js/navigation.test.mjs` | create | node:test for `navigation.js` |
| `tests/js/safeStorage.test.mjs` | create | node:test for `safeStorage.js` |
| `tests/js/navI18n.test.mjs` | create | node:test: every `nav.*` key resolves in ar/en/fr |
| `package.json` | modify | Add the `test:js` script |
| `resources/js/i18n/{ar,en,fr}.json` | modify | Add the `nav.*` keys; remove the 3 unused old keys |
| `resources/js/Composables/useNavigation.js` | create | Menu definition, translated and permission-filtered |
| `resources/js/Composables/useSidebarState.js` | create | Module-scoped open/narrow state plus the `lg` media query |
| `resources/js/Components/SidebarLink.vue` | modify | Prefetch, `compact` and `iconOnly` props |
| `resources/js/Components/Sidebar/SidebarSection.vue` | create | Accordion section |
| `resources/js/Components/Sidebar/SidebarFlyout.vue` | create | Narrow-mode section icon plus flyout |
| `resources/js/Components/CommandPalette.vue` | create | Ctrl+K quick search |
| `resources/js/Layouts/AuthenticatedLayout.vue` | modify | Compose the pieces; wide and narrow modes |
| `resources/js/app.js` | modify | Flush prefetch cache before non-GET visits |

Refinement of the spec's "`Layouts/navigation.js` exports `useNavigation()`": the pure part goes in `lib/navigation.js` so Node can test it, and the Vue composable goes in `Composables/useNavigation.js`.

---

### Task 0: Worktree setup

**Files:** none committed.

- [ ] **Step 1: Copy the dependencies into the worktree.** Copy, never junction (a junctioned `vendor` autoloads the main folder's `app/`).

```bash
cd /d/irnb-sidebar
cp -r ../irnb-laravel/vendor ./vendor
cp ../irnb-laravel/.env ./.env
npm ci
```

Expected: `npm ci` ends with `added N packages`. `vendor/` and `.env` are gitignored, so `git status` shows them as neither untracked nor modified.

- [ ] **Step 2: Baseline check**

Run: `php artisan test tests/Feature/Dashboard/DashboardPageTest.php`
Expected: PASS.

---

### Task 1: Pure navigation helpers + JS test runner

**Files:**
- Create: `resources/js/lib/navigation.js`
- Create: `tests/js/navigation.test.mjs`
- Modify: `package.json` (`scripts`)

**Interfaces:**
- Produces, all named exports of `resources/js/lib/navigation.js`:
  - `normalize(text: string): string`
  - `isActive(item: {prefix, exact?, match?}, url: string): boolean`
  - `visibleSections(raw: Section[], ctx: {can(module, ability): boolean, isSuperadmin: boolean, isDesktop: boolean}): Section[]`
  - `sectionHasActive(section: Section, url: string): boolean`
  - `sectionBadge(section: Section): number`
  - `flattenForSearch(sections: Section[]): Entry[]`, where `Entry = item & { section: string|null, haystack: string }`
  - `searchItems(entries: Entry[], query: string): Entry[]`
- `Section = { key: string, label?: string, icon?: string, standalone?: boolean, items: Item[] }`
- `Item = { label, href, icon, prefix, exact?, match?, badge?, module?, always?, superadminOnly?, desktopOnly? }`

- [ ] **Step 1: Add the test script to `package.json`.** Inside `"scripts"`, after the `"i18n:check"` line, add:

```json
        "test:js": "node --test tests/js/",
```

- [ ] **Step 2: Write the failing test** `tests/js/navigation.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    normalize, isActive, visibleSections, sectionHasActive,
    sectionBadge, flattenForSearch, searchItems,
} from '../../resources/js/lib/navigation.js';

test('normalize unifies Arabic letter variants and strips marks', () => {
    assert.equal(normalize('الإعدادات'), 'الاعدادات');
    assert.equal(normalize('أآإٱ'), 'اااا');
    assert.equal(normalize('مدرسة'), 'مدرسه');
    assert.equal(normalize('مستوى'), 'مستوي');
    assert.equal(normalize('مُدَرِّب'), 'مدرب');
    assert.equal(normalize('تـــهيئة'), 'تهيئه');
});

test('normalize lowercases and strips Latin accents', () => {
    assert.equal(normalize('  Paramétrage '), 'parametrage');
    assert.equal(normalize(null), '');
});

test('isActive honours match, exact and prefix', () => {
    const users = { prefix: '/users', match: /^\/users(?!\/(\d+\/)?activity)/ };
    assert.equal(isActive(users, '/users/5/edit'), true);
    assert.equal(isActive(users, '/users/activity'), false);

    const hub = { prefix: '/board', exact: true };
    assert.equal(isActive(hub, '/board'), true);
    assert.equal(isActive(hub, '/board?year=2026'), true);
    assert.equal(isActive(hub, '/board/tasks'), false);

    assert.equal(isActive({ prefix: '/players' }, '/players/3'), true);
    assert.equal(isActive({ prefix: '/players' }, undefined), false);
});

const raw = [
    { key: 'dashboard', standalone: true, items: [{ label: 'Dashboard', prefix: '/dashboard', always: true }] },
    { key: 'config', label: 'App Configuration', items: [
        { label: 'Categories', prefix: '/categories', module: 'categories' },
        { label: 'Board roles', prefix: '/board-roles', module: 'board' },
    ] },
    { key: 'system', label: 'System', items: [
        { label: 'Users', prefix: '/users', module: 'users', badge: 3 },
        { label: 'Roles', prefix: '/roles', superadminOnly: true },
        { label: 'Backup', prefix: '/backups', superadminOnly: true, desktopOnly: true },
    ] },
];

test('visibleSections keeps only permitted items and drops empty sections', () => {
    const can = (module) => module === 'board';
    const out = visibleSections(raw, { can, isSuperadmin: false, isDesktop: false });
    assert.deepEqual(out.map((s) => s.key), ['dashboard', 'config']);
    assert.deepEqual(out[1].items.map((i) => i.label), ['Board roles']);
});

test('visibleSections: superadmin sees superadminOnly items, desktopOnly needs desktop', () => {
    const can = () => true;
    const web = visibleSections(raw, { can, isSuperadmin: true, isDesktop: false });
    assert.deepEqual(web[2].items.map((i) => i.label), ['Users', 'Roles']);
    const desk = visibleSections(raw, { can, isSuperadmin: true, isDesktop: true });
    assert.deepEqual(desk[2].items.map((i) => i.label), ['Users', 'Roles', 'Backup']);
});

test('sectionHasActive and sectionBadge', () => {
    assert.equal(sectionHasActive(raw[2], '/roles'), true);
    assert.equal(sectionHasActive(raw[2], '/players'), false);
    assert.equal(sectionBadge(raw[2]), 3);
    assert.equal(sectionBadge(raw[1]), 0);
});

test('search matches label or section, accent- and hamza-insensitive', () => {
    const sections = [
        { key: 'config', label: 'تهيئة التطبيق', items: [{ label: 'الإعدادات', href: '/settings' }] },
        { key: 'cfg-fr', label: 'Paramétrage', items: [{ label: 'Catégories', href: '/categories' }] },
    ];
    const entries = flattenForSearch(sections);
    assert.equal(entries[0].section, 'تهيئة التطبيق');
    assert.deepEqual(searchItems(entries, 'اعدادات').map((e) => e.href), ['/settings']);
    assert.deepEqual(searchItems(entries, 'parametrage').map((e) => e.href), ['/categories']);
    assert.deepEqual(searchItems(entries, 'CATEG').map((e) => e.href), ['/categories']);
    assert.equal(searchItems(entries, '   ').length, 2);
    assert.equal(searchItems(entries, 'zzz').length, 0);
});

test('flattenForSearch gives standalone items a null section', () => {
    const entries = flattenForSearch([raw[0]]);
    assert.equal(entries[0].section, null);
    assert.equal(entries[0].haystack, 'dashboard');
});
```

- [ ] **Step 3: Run it and check that it fails**

Run: `npm run test:js`
Expected: FAIL with `ERR_MODULE_NOT_FOUND ... resources/js/lib/navigation.js`.

- [ ] **Step 4: Implement** `resources/js/lib/navigation.js`:

```js
// Pure helpers behind the sidebar and the quick search. No Vue and no `@/`
// imports, so `node --test` can load this file directly.

// Arabic marks: tashkeel, the hamza/madda marks NFD splits off أ إ آ,
// superscript alef, and tatweel.
const ARABIC_MARKS = /[\u064B-\u065F\u0670\u0640]/g;
const LATIN_MARKS = /[\u0300-\u036f]/g;

/** Fold text so "اعدادات" finds "الإعدادات" and "parametrage" finds "Paramétrage". */
export function normalize(text) {
    return String(text ?? '')
        .toLowerCase()
        .normalize('NFD')
        .replace(LATIN_MARKS, '')
        .replace(ARABIC_MARKS, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي')
        .trim();
}

export function isActive(item, url) {
    const current = url ?? '';
    // `match` items decide with their own pattern (Users vs its Activity pages).
    if (item.match) return item.match.test(current);
    // `exact` items (the Board hub) must not stay highlighted on their own
    // sub-pages, which have their own menu entries.
    return item.exact
        ? current === item.prefix || current.startsWith(item.prefix + '?')
        : current.startsWith(item.prefix);
}

// Items show only when the user can view their module (superadmin sees all).
// `always` items are ungated; `superadminOnly` items need the god flag;
// `desktopOnly` items need the desktop app. Empty sections are dropped.
export function visibleSections(raw, { can, isSuperadmin, isDesktop }) {
    return raw
        .map((section) => ({
            ...section,
            items: section.items.filter((i) =>
                (!i.desktopOnly || isDesktop)
                && (i.always || (i.superadminOnly ? isSuperadmin : can(i.module, 'view')))),
        }))
        .filter((section) => section.items.length > 0);
}

export function sectionHasActive(section, url) {
    return section.items.some((item) => isActive(item, url));
}

/** Sum of item badges, shown on a section that is closed. */
export function sectionBadge(section) {
    return section.items.reduce((sum, item) => sum + (Number(item.badge) || 0), 0);
}

export function flattenForSearch(sections) {
    return sections.flatMap((section) => section.items.map((item) => ({
        ...item,
        section: section.label ?? null,
        haystack: normalize(`${item.label} ${section.label ?? ''}`),
    })));
}

export function searchItems(entries, query) {
    const q = normalize(query);
    if (q === '') return entries;
    return entries.filter((entry) => entry.haystack.includes(q));
}
```

- [ ] **Step 5: Run it and check that it passes**

Run: `npm run test:js`
Expected: `# pass 8`, `# fail 0`.

- [ ] **Step 6: Commit**

```bash
git add package.json resources/js/lib/navigation.js tests/js/navigation.test.mjs
git commit -m "feat(sidebar): pure navigation helpers for filtering, active state and search

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 2: Safe storage helper

**Files:**
- Create: `resources/js/lib/safeStorage.js`
- Create: `tests/js/safeStorage.test.mjs`

**Interfaces:**
- Produces:
  - `readJson(key: string, fallback: any, storage = globalThis.localStorage): any`
  - `writeJson(key: string, value: any, storage = globalThis.localStorage): void`
- Neither function ever throws.

- [ ] **Step 1: Write the failing test** `tests/js/safeStorage.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readJson, writeJson } from '../../resources/js/lib/safeStorage.js';

const memory = () => {
    const data = new Map();
    return { getItem: (k) => (data.has(k) ? data.get(k) : null), setItem: (k, v) => data.set(k, String(v)) };
};
const broken = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('quota'); } };

test('round-trips JSON', () => {
    const s = memory();
    writeJson('k', { a: true }, s);
    assert.deepEqual(readJson('k', null, s), { a: true });
});

test('returns the fallback for a missing key, bad JSON, broken or absent storage', () => {
    const s = memory();
    assert.equal(readJson('missing', 'fb', s), 'fb');
    s.setItem('bad', '{not json');
    assert.equal(readJson('bad', 'fb', s), 'fb');
    assert.equal(readJson('k', 'fb', broken), 'fb');
    assert.equal(readJson('k', 'fb', undefined), 'fb');
});

test('writeJson never throws', () => {
    assert.doesNotThrow(() => writeJson('k', 1, broken));
    assert.doesNotThrow(() => writeJson('k', 1, undefined));
});
```

- [ ] **Step 2: Run it and check that it fails**

Run: `npm run test:js`
Expected: FAIL with `ERR_MODULE_NOT_FOUND ... safeStorage.js`.

- [ ] **Step 3: Implement** `resources/js/lib/safeStorage.js`:

```js
// localStorage can be missing or throw (private window, blocked site data,
// quota). These helpers never throw: a preference that can't be kept just
// falls back to its default.

export function readJson(key, fallback, storage = globalThis.localStorage) {
    try {
        const raw = storage?.getItem(key);
        return raw == null ? fallback : JSON.parse(raw);
    } catch {
        return fallback;
    }
}

export function writeJson(key, value, storage = globalThis.localStorage) {
    try {
        storage?.setItem(key, JSON.stringify(value));
    } catch {
        // Not kept; the in-memory state still works for this session.
    }
}
```

- [ ] **Step 4: Run it and check that it passes**

Run: `npm run test:js`
Expected: `# fail 0` (11 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/lib/safeStorage.js tests/js/safeStorage.test.mjs
git commit -m "feat(sidebar): storage helpers that never throw

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 3: i18n keys

**Files:**
- Modify: `resources/js/i18n/ar.json`, `en.json`, `fr.json`
- Create: `tests/js/navI18n.test.mjs`

**Interfaces:**
- Produces these keys: `nav.config`, `nav.system`, `nav.board_of_directors`, `nav.board_overview`, `nav.search`, `nav.search_placeholder`, `nav.no_results`, `nav.collapse_sidebar`, `nav.expand_sidebar`.

- [ ] **Step 1: Write the failing test** `tests/js/navI18n.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createI18n } from 'vue-i18n';

const load = (l) => JSON.parse(readFileSync(new URL(`../../resources/js/i18n/${l}.json`, import.meta.url)));
const messages = { ar: load('ar'), en: load('en'), fr: load('fr') };

const KEYS = [
    'nav.config', 'nav.system', 'nav.board_of_directors', 'nav.board_overview',
    'nav.search', 'nav.search_placeholder', 'nav.no_results',
    'nav.collapse_sidebar', 'nav.expand_sidebar',
];

test('every sidebar key resolves through t() in ar, en and fr', () => {
    const i18n = createI18n({ legacy: false, locale: 'ar', messages });
    for (const locale of ['ar', 'en', 'fr']) {
        i18n.global.locale.value = locale;
        for (const key of KEYS) assert.notEqual(i18n.global.t(key), key, `${locale}: ${key}`);
    }
});

test('owner-chosen section names', () => {
    assert.equal(messages.ar['nav.config'], 'تهيئة التطبيق');
    assert.equal(messages.en['nav.config'], 'App Configuration');
    assert.equal(messages.fr['nav.config'], 'Paramétrage');
    assert.equal(messages.ar['nav.board_of_directors'], 'مجلس إدارة النادي');
    assert.equal(messages.en['nav.board_of_directors'], 'Board of Directors');
    assert.equal(messages.fr['nav.board_of_directors'], 'Bureau du club');
});
```

- [ ] **Step 2: Run it and check that it fails**

Run: `npm run test:js`
Expected: FAIL `ar: nav.config`.

- [ ] **Step 3: Add the keys.** Write the scratch file `$SCRATCH/nav-keys.json`, where `$SCRATCH` is the session scratchpad directory, and do not commit it:

```json
{
    "nav.config": { "ar": "تهيئة التطبيق", "en": "App Configuration", "fr": "Paramétrage" },
    "nav.system": { "ar": "النظام", "en": "System", "fr": "Système" },
    "nav.board_of_directors": { "ar": "مجلس إدارة النادي", "en": "Board of Directors", "fr": "Bureau du club" },
    "nav.board_overview": { "ar": "نظرة عامة", "en": "Overview", "fr": "Vue d'ensemble" },
    "nav.search": { "ar": "بحث…", "en": "Search…", "fr": "Rechercher…" },
    "nav.search_placeholder": { "ar": "ابحث عن صفحة…", "en": "Search for a page…", "fr": "Rechercher une page…" },
    "nav.no_results": { "ar": "لا توجد نتائج", "en": "No results", "fr": "Aucun résultat" },
    "nav.collapse_sidebar": { "ar": "تصغير القائمة", "en": "Collapse sidebar", "fr": "Réduire le menu" },
    "nav.expand_sidebar": { "ar": "توسيع القائمة", "en": "Expand sidebar", "fr": "Agrandir le menu" }
}
```

Run: `node scripts/i18n-add.mjs "$SCRATCH/nav-keys.json"`
Expected: `✓ 9 key(s) written to ar/en/fr`

- [ ] **Step 4: Run the tests and the i18n check**

Run: `npm run test:js && npm run i18n:check`
Expected: `# fail 0`, then `✓ all … flash keys … resolve in ar/fr/en`.

- [ ] **Step 5: Check the catalog diff is only additions**

Run: `git diff --stat resources/js/i18n/`
Expected: `9 insertions` in each of the 3 files and no deletions. If deletions appear, `i18n-add.mjs` re-sorted keys that were out of order. Stop and report this rather than committing a reorder.

- [ ] **Step 6: Commit**

```bash
git add resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/js/navI18n.test.mjs
git commit -m "feat(sidebar): labels for the new sections, quick search and narrow mode

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

The old keys `nav_governance`, `nav_access` and `administration` are removed in Task 5, after the layout stops using them.

---

### Task 4: State composables + prefetching SidebarLink

**Files:**
- Create: `resources/js/Composables/useNavigation.js`
- Create: `resources/js/Composables/useSidebarState.js`
- Modify: `resources/js/Components/SidebarLink.vue` (whole file)
- Modify: `resources/js/app.js` (add prefetch flush)

**Interfaces:**
- Consumes: `visibleSections` (Task 1), `readJson`/`writeJson` (Task 2), the `nav.*` keys (Task 3), and `useCan()` → `{ can(module, ability), isSuperadmin: Ref<boolean> }`.
- Produces:
  - `useNavigation(): { sections: ComputedRef<Section[]>, url: ComputedRef<string> }`
  - `useSidebarState(): { isOpen(key): boolean, toggleSection(key): void, compact: ComputedRef<boolean>, toggleNarrow(): void }`
  - `SidebarLink` props: `href` (required), `active`, `icon`, `badge`, `compact: Boolean`, `iconOnly: Boolean`. Non-prop attributes (`title`, `aria-label`) fall through to the `<a>`.

No automated test: these are Vue/Inertia wrappers around the tested helpers. They are checked through the build in Task 5.

- [ ] **Step 1: Create** `resources/js/Composables/useNavigation.js`:

```js
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan.js';
import { visibleSections } from '@/lib/navigation';

// The one menu definition, shared by the sidebar (wide and narrow) and the
// quick search, so a page can never be listed in one and missing in the other.
// Every item declares the `module` that gates it (see lib/navigation.js).
export function useNavigation() {
    const { t } = useI18n();
    const page = usePage();
    const { can, isSuperadmin } = useCan();

    const url = computed(() => page.url ?? '');

    const sections = computed(() => {
        const pendingApprovals = page.props.pendingApprovals ?? 0;

        const raw = [
            { key: 'dashboard', standalone: true, items: [
                { label: t('dashboard'), href: '/dashboard', icon: 'dashboard', prefix: '/dashboard', always: true },
            ] },
            { key: 'members', label: t('nav_members'), icon: 'players', items: [
                { label: t('players'), href: '/players', icon: 'players', prefix: '/players', module: 'players' },
                { label: t('subscriptions'), href: '/subscriptions', icon: 'subscriptions', prefix: '/subscriptions', module: 'subscriptions' },
                { label: t('attendance'), href: '/attendance', icon: 'calendar', prefix: '/attendance', module: 'attendance' },
            ] },
            { key: 'finance', label: t('nav_finance'), icon: 'money', items: [
                { label: t('transactions'), href: '/transactions', icon: 'transactions', prefix: '/transactions', module: 'transactions' },
                { label: t('finance'), href: '/finance', icon: 'money', prefix: '/finance', module: 'finance' },
            ] },
            { key: 'equipment', label: t('nav_equipment'), icon: 'equipment', items: [
                { label: t('equipments'), href: '/equipment/catalogs', icon: 'equipment', prefix: '/equipment/catalogs', module: 'equipment' },
                { label: t('equipment_out'), href: '/equipment/out', icon: 'box', prefix: '/equipment/out', module: 'equipment' },
                { label: t('inventory'), href: '/equipment/stocktake', icon: 'clipboard', prefix: '/equipment/stocktake', module: 'inventory' },
            ] },
            { key: 'board', label: t('nav.board_of_directors'), icon: 'board', items: [
                { label: t('nav.board_overview'), href: '/board', icon: 'board', prefix: '/board', exact: true, module: 'board' },
                { label: t('calendar'), href: '/board/calendar', icon: 'calendar', prefix: '/board/calendar', module: 'board' },
                { label: t('meetings'), href: '/board/meetings', icon: 'clipboard', prefix: '/board/meetings', module: 'board' },
                { label: t('tasks'), href: '/board/tasks', icon: 'task', prefix: '/board/tasks', module: 'board' },
            ] },
            { key: 'config', label: t('nav.config'), icon: 'wrench', items: [
                { label: t('categories'), href: '/categories', icon: 'categories', prefix: '/categories', module: 'categories' },
                { label: t('branches'), href: '/branches', icon: 'categories', prefix: '/branches', module: 'categories' },
                { label: t('positions'), href: '/positions', icon: 'positions', prefix: '/positions', module: 'categories' },
                { label: t('player_statuses'), href: '/player-statuses', icon: 'positions', prefix: '/player-statuses', module: 'categories' },
                { label: t('document_types'), href: '/document-types', icon: 'document', prefix: '/document-types', module: 'categories' },
                { label: t('jobs'), href: '/jobs', icon: 'jobs', prefix: '/jobs', module: 'categories' },
                { label: t('board_roles'), href: '/board-roles', icon: 'board', prefix: '/board-roles', module: 'board' },
                { label: t('equipment_categories'), href: '/equipment-categories', icon: 'equipment', prefix: '/equipment-categories', module: 'categories' },
                { label: t('storage_locations'), href: '/storage-locations', icon: 'location', prefix: '/storage-locations', module: 'categories' },
            ] },
            { key: 'system', label: t('nav.system'), icon: 'settings', items: [
                { label: t('users'), href: '/users', icon: 'members', prefix: '/users', match: /^\/users(?!\/(\d+\/)?activity)/, badge: pendingApprovals, module: 'users' },
                { label: t('activity.title'), href: '/users/activity', icon: 'task', prefix: '/users/activity', match: /^\/users\/(\d+\/)?activity/, module: 'users' },
                { label: t('roles'), href: '/roles', icon: 'flag', prefix: '/roles', superadminOnly: true },
                { label: t('settings'), href: '/settings', icon: 'settings', prefix: '/settings', module: 'settings' },
                { label: t('backup'), href: '/backups', icon: 'archive', prefix: '/backups', superadminOnly: true, desktopOnly: true },
            ] },
        ];

        return visibleSections(raw, {
            can,
            isSuperadmin: isSuperadmin.value,
            isDesktop: page.props.isDesktop ?? false,
        });
    });

    return { sections, url };
}
```

- [ ] **Step 2: Create** `resources/js/Composables/useSidebarState.js`:

```js
import { computed, ref } from 'vue';
import { readJson, writeJson } from '@/lib/safeStorage';

const SECTIONS_KEY = 'sidebar.sections';
const NARROW_KEY = 'sidebar.narrow';

// Module scope: the layout remounts on every Inertia visit, and the sidebar
// must not forget what the user opened.
const stored = readJson(SECTIONS_KEY, {});
const userState = ref(stored && typeof stored === 'object' ? stored : {});
const narrow = ref(readJson(NARROW_KEY, false) === true);

// The narrow rail is a desktop-width layout; the mobile drawer is always wide.
const lgQuery = typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia('(min-width: 1024px)')
    : null;
const isLg = ref(lgQuery?.matches ?? true);
lgQuery?.addEventListener('change', (e) => { isLg.value = e.matches; });

export function useSidebarState() {
    const compact = computed(() => narrow.value && isLg.value);

    function isOpen(key) {
        return userState.value[key] === true;
    }

    function toggleSection(key) {
        userState.value = { ...userState.value, [key]: !isOpen(key) };
        writeJson(SECTIONS_KEY, userState.value);
    }

    function toggleNarrow() {
        narrow.value = !narrow.value;
        writeJson(NARROW_KEY, narrow.value);
    }

    return { isOpen, toggleSection, compact, toggleNarrow };
}
```

- [ ] **Step 3: Replace** `resources/js/Components/SidebarLink.vue`:

```vue
<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

defineProps({
    href: { type: String, required: true },
    active: { type: Boolean, default: false },
    icon: { type: String, default: null },
    badge: { type: [Number, String], default: null },
    // Tighter rows, used inside sections and the narrow-mode flyout.
    compact: { type: Boolean, default: false },
    // Icon alone (narrow rail). Pass `title`/`aria-label` as attributes.
    iconOnly: { type: Boolean, default: false },
});
</script>

<template>
    <!-- Hover prefetch: the page is usually loaded before the click lands. -->
    <Link
        :href="href"
        prefetch
        cache-for="30s"
        class="group relative flex items-center rounded-xl text-sm font-medium transition-all duration-200"
        :class="[
            iconOnly ? 'justify-center p-1.5' : compact ? 'gap-2.5 px-2.5 py-1.5' : 'gap-3 px-3 py-2.5',
            active
                ? 'bg-primary-50 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300'
                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100',
        ]"
    >
        <!-- RTL-aware active accent bar -->
        <span v-if="active && !iconOnly" class="absolute inset-y-2 start-0 w-1 rounded-full bg-primary-500"></span>

        <span
            v-if="icon"
            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-[1.05rem] transition-colors"
            :class="active ? 'bg-primary-100 text-primary-600 ring-1 ring-inset ring-primary-200 dark:bg-primary-500/20 dark:text-primary-300 dark:ring-primary-500/30' : 'bg-slate-100 text-slate-500 group-hover:text-slate-700 dark:bg-slate-800 dark:text-slate-400 dark:group-hover:text-slate-200'"
        ><Icon :name="icon" /></span>

        <span :class="iconOnly ? 'sr-only' : 'truncate'"><slot /></span>

        <span
            v-if="badge"
            class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1.5 py-0.5 text-xs font-bold text-white shadow-sm"
            :class="iconOnly ? 'absolute -end-1 -top-1 !min-w-[1rem] !px-1 !py-0 !text-[0.6rem]' : 'ms-auto'"
        >{{ badge }}</span>
    </Link>
</template>
```

- [ ] **Step 4: Flush the prefetch cache before writes in** `resources/js/app.js`. The file starts with a UTF-8 BOM, so use the Edit tool and do not rewrite the whole file. Insert after the line `const rememberClubName = (pageProps) => {` … `};` block (before `createInertiaApp({`):

```js
// Sidebar and quick-search links prefetch pages on hover and keep them for
// 30s. Any write can change what those pages show, so drop the cache before
// a POST/PUT/PATCH/DELETE rather than ever serving a stale page after it.
router.on('before', (event) => {
    if (event.detail.visit.method !== 'get') router.flushAll();
});
```

- [ ] **Step 5: Build to check it compiles**

Run: `npm run build`
Expected: `✓ built in …`, with no errors. The layout does not use the new files yet; tree-shaking may drop them, which is fine.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Composables/useNavigation.js resources/js/Composables/useSidebarState.js resources/js/Components/SidebarLink.vue resources/js/app.js
git commit -m "feat(sidebar): menu definition, persisted sidebar state and hover prefetch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

Check that `app.js` shows only about 7 insertions.

---

### Task 5: Accordion sections in the wide sidebar

**Files:**
- Create: `resources/js/Components/Sidebar/SidebarSection.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`
- Modify: `resources/js/i18n/{ar,en,fr}.json` (remove 3 unused keys)

**Interfaces:**
- Consumes: `useNavigation`, `useSidebarState`, `SidebarLink` (Task 4); `isActive`, `sectionHasActive`, `sectionBadge` (Task 1).
- Produces: `SidebarSection` props `section: Section`, `url: string`, `userOpen: boolean`; emits `toggle(key: string)`.

- [ ] **Step 1: Create** `resources/js/Components/Sidebar/SidebarSection.vue`:

```vue
<script setup>
import { computed } from 'vue';
import Icon from '@/Components/Icon.vue';
import SidebarLink from '@/Components/SidebarLink.vue';
import { isActive, sectionBadge, sectionHasActive } from '@/lib/navigation';

const props = defineProps({
    section: { type: Object, required: true },
    url: { type: String, required: true },
    userOpen: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle']);

const hasActive = computed(() => sectionHasActive(props.section, props.url));
// The section of the current page is always open: closing it would hide where you are.
const open = computed(() => hasActive.value || props.userOpen);
const badge = computed(() => sectionBadge(props.section));
const panelId = computed(() => `nav-section-${props.section.key}`);

function onToggle() {
    if (hasActive.value) return;
    emit('toggle', props.section.key);
}
</script>

<template>
    <div>
        <button
            type="button"
            class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm font-semibold transition-colors hover:bg-slate-100 dark:hover:bg-slate-800"
            :class="hasActive ? 'text-slate-900 dark:text-slate-100' : 'text-slate-600 dark:text-slate-300'"
            :aria-expanded="open"
            :aria-controls="panelId"
            @click="onToggle"
        >
            <span
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-[1.05rem]"
                :class="hasActive ? 'text-primary-600 dark:text-primary-300' : 'text-slate-500 dark:text-slate-400'"
            ><Icon :name="section.icon" /></span>
            <span class="min-w-0 flex-1 truncate">{{ section.label }}</span>
            <span
                v-if="!open && badge"
                class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1.5 py-0.5 text-xs font-bold text-white shadow-sm"
            >{{ badge }}</span>
            <!-- Points down when open, toward the inline end when closed (mirrors in RTL). -->
            <Icon
                name="chevron"
                class="shrink-0 text-base text-slate-400 transition-transform duration-200 motion-reduce:transition-none"
                :class="open ? 'rotate-0' : '-rotate-90 rtl:rotate-90'"
            />
        </button>

        <!-- 0fr → 1fr animates to the content's natural height without measuring it. -->
        <div
            :id="panelId"
            class="grid transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none"
            :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
            :inert="open ? null : ''"
        >
            <div class="min-h-0 overflow-hidden">
                <div class="space-y-0.5 ps-3 pt-0.5">
                    <SidebarLink
                        v-for="item in section.items"
                        :key="item.href"
                        :href="item.href"
                        :active="isActive(item, url)"
                        :icon="item.icon"
                        :badge="item.badge"
                        compact
                    >{{ item.label }}</SidebarLink>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Wire it into the layout.** In `resources/js/Layouts/AuthenticatedLayout.vue`:

  a) Replace the import `import SidebarLink from '@/Components/SidebarLink.vue';` with:

```js
import SidebarLink from '@/Components/SidebarLink.vue';
import SidebarSection from '@/Components/Sidebar/SidebarSection.vue';
import { useNavigation } from '@/Composables/useNavigation.js';
import { useSidebarState } from '@/Composables/useSidebarState.js';
import { isActive } from '@/lib/navigation';
```

  b) Keep the `useCan` import, since the backup heartbeat still uses `isSuperadmin`. Change `const { can, isSuperadmin } = useCan();` to:

```js
const { isSuperadmin } = useCan();
const { sections, url } = useNavigation();
const { isOpen, toggleSection } = useSidebarState();
```

  c) Delete these, which now live in `useNavigation` and `lib/navigation`:
  - `const pendingApprovals = …`
  - `const currentUrl = …`
  - the whole `function isActive(item) { … }`
  - the comment block plus `const sections = computed(() => { … });`

  d) Replace the `<nav …>…</nav>` block with:

```vue
            <!-- Navigation -->
            <nav ref="navEl" @scroll.passive="rememberNavScroll" class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                <template v-for="section in sections" :key="section.key">
                    <template v-if="section.standalone">
                        <SidebarLink
                            v-for="item in section.items"
                            :key="item.href"
                            :href="item.href"
                            :active="isActive(item, url)"
                            :icon="item.icon"
                            :badge="item.badge"
                        >{{ item.label }}</SidebarLink>
                    </template>
                    <SidebarSection
                        v-else
                        :section="section"
                        :url="url"
                        :user-open="isOpen(section.key)"
                        @toggle="toggleSection"
                    />
                </template>
            </nav>
```

- [ ] **Step 3: Remove the three old keys only if nothing else uses them**

Run: `grep -rnE "nav_governance|nav_access|'administration'|\"administration\"" app resources/js routes --include=*.php --include=*.vue --include=*.js`
Expected: no output. If anything prints, skip the removal for that key.

Then run:

```bash
node -e "const fs=require('fs');for(const l of ['ar','en','fr']){const p='resources/js/i18n/'+l+'.json';const c=JSON.parse(fs.readFileSync(p,'utf8'));for(const k of ['nav_governance','nav_access','administration'])delete c[k];fs.writeFileSync(p,JSON.stringify(c,null,4)+'\n');}"
git diff --stat resources/js/i18n/
```

Expected: `3 deletions` per file and no insertions.

- [ ] **Step 4: Verify**

Run: `npm run test:js && npm run i18n:check && npm run build`
Expected: all three pass.

- [ ] **Step 5: Manual QA.** Run `php artisan serve --port=2027` in the worktree and log in as the superadmin.
  - On `/players`, Members is open and the others are closed.
  - Click the Members header: it does not close (the current page is inside it).
  - Open Finance, then go to `/board`. Finance stays open, Board of Directors opens, and the first board link reads "Overview".
  - Reload: Finance is still open.
  - Close Finance and Board: the sidebar fits without scrolling.
  - Switch to Arabic: chevrons point left when closed, and the open/close animation runs.
  - With a pending account approval, close System: the red badge shows on the System header.
  - Network tab: hovering a link fires a prefetch request, and a click right after it is instant.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Sidebar/SidebarSection.vue resources/js/Layouts/AuthenticatedLayout.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(sidebar): group the menu into collapsible sections

Board of Directors, App Configuration and System replace Governance,
Administration and Users & Access. The section of the current page
always stays open; other sections remember being opened.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 6: Quick search (Ctrl+K)

**Files:**
- Create: `resources/js/Components/CommandPalette.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

**Interfaces:**
- Consumes: `flattenForSearch` and `searchItems` (Task 1); `Modal.vue` (existing; props `show`, `maxWidth`; emits `close`; already closes on Esc); `router.prefetch(href, options, { cacheFor })` and `router.visit(href)` from `@inertiajs/vue3`.
- Produces: `CommandPalette` with props `open: Boolean` and `sections: Section[]`; emits `update:open` (use as `v-model:open`). It registers the global Ctrl/⌘+K listener itself.

- [ ] **Step 1: Create** `resources/js/Components/CommandPalette.vue`:

```vue
<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import Icon from '@/Components/Icon.vue';
import { flattenForSearch, searchItems } from '@/lib/navigation';

const props = defineProps({
    open: { type: Boolean, default: false },
    // Already permission-filtered: the palette never lists a page the user can't open.
    sections: { type: Array, required: true },
});

const emit = defineEmits(['update:open']);
const { t } = useI18n();

const query = ref('');
const highlighted = ref(0);
const input = ref(null);
let returnFocusTo = null;
let prefetchTimer = null;

const entries = computed(() => flattenForSearch(props.sections));
const results = computed(() => searchItems(entries.value, query.value));

watch(query, () => { highlighted.value = 0; });

watch(() => props.open, async (isOpen) => {
    if (isOpen) {
        returnFocusTo = document.activeElement;
        query.value = '';
        highlighted.value = 0;
        await nextTick();
        input.value?.focus();
    } else {
        returnFocusTo?.focus?.();
        returnFocusTo = null;
    }
});

// Same idea as hover prefetch: load the page the user is about to pick.
// Debounced so arrowing through the list doesn't fire a request per row.
watch(highlighted, () => {
    clearTimeout(prefetchTimer);
    if (!props.open) return;
    prefetchTimer = setTimeout(() => {
        const item = results.value[highlighted.value];
        if (item) router.prefetch(item.href, { method: 'get' }, { cacheFor: '30s' });
    }, 75);
});

function close() {
    emit('update:open', false);
}

function move(step) {
    const count = results.value.length;
    if (count === 0) return;
    highlighted.value = (highlighted.value + step + count) % count;
    nextTick(() => document.getElementById(`palette-option-${highlighted.value}`)?.scrollIntoView({ block: 'nearest' }));
}

function visit(item) {
    if (!item) return;
    close();
    router.visit(item.href);
}

// `code` keeps the shortcut working on an Arabic keyboard layout, where
// `key` for the K key is "ن".
function onGlobalKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.code === 'KeyK' || e.key?.toLowerCase() === 'k')) {
        e.preventDefault();
        emit('update:open', !props.open);
    }
}

onMounted(() => document.addEventListener('keydown', onGlobalKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', onGlobalKeydown);
    clearTimeout(prefetchTimer);
});
</script>

<template>
    <Modal :show="open" max-width="lg" @close="close">
        <div class="flex items-center gap-3 border-b border-slate-200 px-4 dark:border-slate-800">
            <Icon name="search" class="shrink-0 text-lg text-slate-400" />
            <input
                ref="input"
                v-model="query"
                type="text"
                role="combobox"
                aria-expanded="true"
                aria-controls="palette-results"
                :aria-activedescendant="results.length ? `palette-option-${highlighted}` : undefined"
                :placeholder="t('nav.search_placeholder')"
                class="h-12 w-full border-0 bg-transparent px-0 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0 dark:text-slate-100"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="visit(results[highlighted])"
            />
        </div>

        <ul id="palette-results" role="listbox" class="max-h-80 overflow-y-auto p-2">
            <li
                v-for="(item, i) in results"
                :id="`palette-option-${i}`"
                :key="item.href"
                role="option"
                :aria-selected="i === highlighted"
                class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm"
                :class="i === highlighted ? 'bg-primary-50 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300' : 'text-slate-700 dark:text-slate-300'"
                @mousemove="highlighted = i"
                @click="visit(item)"
            >
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-[1.05rem] text-slate-500 dark:bg-slate-800 dark:text-slate-400"><Icon :name="item.icon" /></span>
                <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                <span v-if="item.section" class="max-w-[45%] shrink-0 truncate text-xs text-slate-400">{{ item.section }}</span>
            </li>
            <li v-if="results.length === 0" class="px-3 py-6 text-center text-sm text-slate-400">{{ t('nav.no_results') }}</li>
        </ul>
    </Modal>
</template>
```

- [ ] **Step 2: Add the palette to the layout.** In `AuthenticatedLayout.vue`:

  a) Add the import `import CommandPalette from '@/Components/CommandPalette.vue';` after the `SidebarSection` import.

  b) After `const mobileMenuOpen = ref(false);` add:

```js
const paletteOpen = ref(false);
const shortcutHint = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/i.test(navigator.platform) ? '⌘K' : 'Ctrl K';
```

  c) As the first child inside `<nav …>`, before `<template v-for="section in sections" …>`, add:

```vue
                <button
                    type="button"
                    class="mb-2 flex w-full items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-400 transition-colors hover:border-slate-300 hover:text-slate-600 dark:border-slate-800 dark:bg-slate-800/50 dark:hover:border-slate-700 dark:hover:text-slate-300"
                    @click="paletteOpen = true"
                >
                    <Icon name="search" class="shrink-0 text-base" />
                    <span class="flex-1 text-start">{{ t('nav.search') }}</span>
                    <kbd class="rounded border border-slate-200 px-1.5 font-sans text-[0.65rem] font-semibold dark:border-slate-700" dir="ltr">{{ shortcutHint }}</kbd>
                </button>
```

  d) Right after `<FlashMessages />`, add:

```vue
        <CommandPalette v-model:open="paletteOpen" :sections="sections" />
```

- [ ] **Step 3: Verify**

Run: `npm run test:js && npm run build`
Expected: both pass.

- [ ] **Step 4: Manual QA** at the dev server:
  - Ctrl+K opens the palette with the input focused; Ctrl+K again closes it.
  - In Arabic, with the Arabic keyboard layout active, Ctrl+K still opens it.
  - Type `اعدادات`: Settings is listed. Type `parametrage` in FR: all configuration pages are listed. Type `zzz`: "No results".
  - ↑/↓ wraps around, Enter opens the page and closes the palette, Esc closes it and focus returns to the search button.
  - Log in as a user without the `categories` view permission: no configuration pages appear except Board roles if they have `board`.
  - Network: arrowing to an item fires one prefetch request.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/CommandPalette.vue resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat(sidebar): Ctrl+K quick search to jump to any page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 7: Icon-only narrow mode

**Files:**
- Create: `resources/js/Components/Sidebar/SidebarFlyout.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

**Interfaces:**
- Consumes: `compact` and `toggleNarrow` from `useSidebarState` (Task 4); `SidebarLink` with the `compact`/`iconOnly` props (Task 4); `isActive`, `sectionHasActive`, `sectionBadge` (Task 1).
- Produces: `SidebarFlyout` props `section: Section`, `url: string`.

- [ ] **Step 1: Create** `resources/js/Components/Sidebar/SidebarFlyout.vue`:

```vue
<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';
import SidebarLink from '@/Components/SidebarLink.vue';
import { isActive, sectionBadge, sectionHasActive } from '@/lib/navigation';

const props = defineProps({
    section: { type: Object, required: true },
    url: { type: String, required: true },
});

const open = ref(false);
const top = ref(0);
const wrapper = ref(null);
const trigger = ref(null);
const panel = ref(null);
let openTimer = null;
let closeTimer = null;
let skipFocusOpen = false;
let removeStartListener = null;

const hasActive = computed(() => sectionHasActive(props.section, props.url));
const badge = computed(() => sectionBadge(props.section));
const panelId = computed(() => `nav-flyout-${props.section.key}`);

async function show() {
    clearTimeout(openTimer);
    clearTimeout(closeTimer);
    top.value = trigger.value.getBoundingClientRect().top;
    open.value = true;
    await nextTick();
    // Keep the panel on screen when the icon sits low in the rail.
    const height = panel.value?.offsetHeight ?? 0;
    top.value = Math.max(8, Math.min(top.value, window.innerHeight - height - 8));
}

function hide() {
    clearTimeout(openTimer);
    clearTimeout(closeTimer);
    open.value = false;
}

function scheduleOpen() {
    clearTimeout(closeTimer);
    clearTimeout(openTimer);
    openTimer = setTimeout(show, 100);
}

// Grace period so the pointer can cross the gap between the icon and the panel.
function scheduleClose() {
    clearTimeout(openTimer);
    closeTimer = setTimeout(hide, 150);
}

function onFocus() {
    if (skipFocusOpen) {
        skipFocusOpen = false;
        return;
    }
    show();
}

function onFocusOut(e) {
    if (!wrapper.value?.contains(e.relatedTarget)) scheduleClose();
}

function onKeydown(e) {
    if (e.key === 'Escape' && open.value) {
        hide();
        skipFocusOpen = true;
        trigger.value?.focus();
    }
}

function onDocumentPointer(e) {
    if (open.value && !wrapper.value?.contains(e.target)) hide();
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointer);
    removeStartListener = router.on('start', hide);
});

onUnmounted(() => {
    document.removeEventListener('pointerdown', onDocumentPointer);
    removeStartListener?.();
    clearTimeout(openTimer);
    clearTimeout(closeTimer);
});
</script>

<template>
    <div
        ref="wrapper"
        @mouseenter="scheduleOpen"
        @mouseleave="scheduleClose"
        @focusout="onFocusOut"
        @keydown="onKeydown"
    >
        <button
            ref="trigger"
            type="button"
            class="relative flex w-full items-center justify-center rounded-xl p-1.5 transition-colors"
            :class="hasActive || open
                ? 'bg-primary-50 text-primary-600 dark:bg-primary-500/15 dark:text-primary-300'
                : 'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800'"
            :aria-label="section.label"
            :aria-expanded="open"
            :aria-controls="panelId"
            @click="show"
            @focus="onFocus"
        >
            <span class="flex h-7 w-7 items-center justify-center text-[1.05rem]"><Icon :name="section.icon" /></span>
            <span v-if="hasActive" class="absolute bottom-1 end-1 h-1.5 w-1.5 rounded-full bg-primary-500"></span>
            <span
                v-if="badge"
                class="absolute -end-0.5 -top-0.5 inline-flex min-w-[1rem] items-center justify-center rounded-full bg-rose-500 px-1 text-[0.6rem] font-bold text-white shadow-sm"
            >{{ badge }}</span>
        </button>

        <!-- Fixed, so the nav's overflow scroll can't clip it; `start-16` sits it
             against the rail on either side (mirrors in RTL). -->
        <div
            v-show="open"
            :id="panelId"
            ref="panel"
            class="fixed start-16 z-50 ms-1 w-60 rounded-xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-800 dark:bg-slate-900"
            :style="{ top: `${top}px` }"
        >
            <p class="eyebrow px-2 pb-1 pt-0.5 !text-[0.65rem] text-slate-400">{{ section.label }}</p>
            <div class="space-y-0.5">
                <SidebarLink
                    v-for="item in section.items"
                    :key="item.href"
                    :href="item.href"
                    :active="isActive(item, url)"
                    :icon="item.icon"
                    :badge="item.badge"
                    compact
                >{{ item.label }}</SidebarLink>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Support narrow mode in the layout.** In `AuthenticatedLayout.vue`:

  a) Add the import `import SidebarFlyout from '@/Components/Sidebar/SidebarFlyout.vue';`. Change the state destructuring to:

```js
const { isOpen, toggleSection, compact, toggleNarrow } = useSidebarState();
```

  b) `<aside>`: change its static `w-64` to a bound width and change the transition. Replace

  `class="fixed inset-y-0 start-0 z-50 flex w-64 flex-col border-e … transition-transform duration-300 ease-out …"`

  so that it keeps every other class but `w-64` is removed and `transition-transform duration-300` becomes `transition-[width,transform] duration-200`. Then extend `:class` to:

```vue
            :class="[
                compact ? 'w-16' : 'w-64',
                mobileMenuOpen ? 'translate-x-0' : 'max-lg:-translate-x-full max-lg:rtl:translate-x-full',
            ]"
```

  c) Crest `<div class="flex h-16 shrink-0 items-center gap-3 border-b … px-5 …">`: replace `gap-3` and `px-5` with a binding and hide the text in compact mode:

```vue
            <div
                class="flex h-16 shrink-0 items-center border-b border-slate-200 dark:border-slate-800"
                :class="compact ? 'justify-center px-0' : 'gap-3 px-5'"
            >
                <!-- logo div unchanged -->
                <div v-if="!compact" class="min-w-0">
                    <!-- the two <p> lines unchanged -->
                </div>
            </div>
```

  d) Nav: set `:class="compact ? 'px-2' : 'px-3'"` and remove `px-3` from the static class. Wrap the current wide content (search button plus sections loop) in `<template v-if="!compact">…</template>`, then add the narrow version after it:

```vue
                <template v-else>
                    <button
                        type="button"
                        class="mb-2 flex w-full items-center justify-center rounded-xl p-1.5 text-slate-500 transition-colors hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                        :title="t('nav.search')"
                        :aria-label="t('nav.search')"
                        @click="paletteOpen = true"
                    >
                        <span class="flex h-7 w-7 items-center justify-center text-[1.05rem]"><Icon name="search" /></span>
                    </button>
                    <template v-for="section in sections" :key="section.key">
                        <template v-if="section.standalone">
                            <SidebarLink
                                v-for="item in section.items"
                                :key="item.href"
                                :href="item.href"
                                :active="isActive(item, url)"
                                :icon="item.icon"
                                :badge="item.badge"
                                :title="item.label"
                                icon-only
                            >{{ item.label }}</SidebarLink>
                        </template>
                        <SidebarFlyout v-else :section="section" :url="url" />
                    </template>
                </template>
```

  e) Footer: replace the profile `<Link>` and the language `<div>` with:

```vue
            <div class="shrink-0 space-y-3 border-t border-slate-200 p-3 dark:border-slate-800" :class="compact ? 'px-2' : ''">
                <Link
                    :href="route('profile.edit')"
                    class="flex items-center rounded-xl p-2 transition-colors hover:bg-slate-100 dark:hover:bg-slate-800"
                    :class="compact ? 'justify-center' : 'gap-3'"
                    :title="compact ? (user?.firstname || user?.name) : null"
                >
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary-500 to-primary-600 text-sm font-bold text-white ring-1 ring-inset ring-primary-400/40">{{ userInitial }}</span>
                    <span v-if="!compact" class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ user?.firstname || user?.name }}</span>
                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ userRole }}</span>
                    </span>
                </Link>
                <div class="flex items-center gap-2" :class="compact ? 'flex-col' : ''">
                    <div class="flex flex-1 items-center gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800" :class="compact ? 'w-full flex-col' : ''">
                        <button
                            v-for="loc in locales"
                            :key="loc.code"
                            @click="switchLocale(loc.code)"
                            class="flex-1 rounded-lg py-1.5 text-xs font-bold transition-colors"
                            :class="[compact ? 'w-full' : '', currentLocale === loc.code ? 'bg-primary-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200']"
                        >{{ loc.label }}</button>
                    </div>
                    <!-- Desktop-width only: the mobile drawer is always wide. -->
                    <button
                        type="button"
                        class="hidden shrink-0 items-center justify-center rounded-xl p-2 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200 lg:flex"
                        :title="compact ? t('nav.expand_sidebar') : t('nav.collapse_sidebar')"
                        :aria-label="compact ? t('nav.expand_sidebar') : t('nav.collapse_sidebar')"
                        @click="toggleNarrow"
                    >
                        <!-- Chevron points toward the inline start to collapse, the end to expand. -->
                        <Icon
                            name="chevron"
                            class="text-base transition-transform duration-200 motion-reduce:transition-none"
                            :class="compact ? '-rotate-90 rtl:rotate-90' : 'rotate-90 rtl:-rotate-90'"
                        />
                    </button>
                </div>
            </div>
```

  f) Main column: replace `<div class="lg:ms-64 print:ms-0">` with:

```vue
        <div class="transition-[margin] duration-200 ease-out motion-reduce:transition-none print:ms-0" :class="compact ? 'lg:ms-16' : 'lg:ms-64'">
```

- [ ] **Step 3: Verify**

Run: `npm run test:js && npm run i18n:check && npm run build`
Expected: all pass.

- [ ] **Step 4: Manual QA** at the dev server (desktop width ≥ 1024 px):
  - Click the footer chevron: the sidebar shrinks to icons and the page content moves over smoothly. Reload: it stays narrow.
  - Hovering a section icon opens its panel next to the rail after a short delay. Moving the pointer into the panel keeps it open; leaving closes it.
  - Click a link in the panel: the panel closes and the page opens. The current section's icon shows a dot.
  - Keyboard: Tab onto a section icon opens its panel, Tab moves into its links, Esc closes and returns focus to the icon.
  - Icons near the bottom: the panel stays inside the window.
  - Arabic: the rail is on the right, the panel opens to its left, and the chevron directions are mirrored.
  - Resize below 1024 px: the burger menu opens the full-width drawer with sections, even with narrow mode stored.
  - The search icon and Ctrl+K both work in narrow mode.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Sidebar/SidebarFlyout.vue resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat(sidebar): icon-only narrow mode with section flyouts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 8: Final verification

- [ ] **Step 1: Run the whole suite**

Run: `npm run test:js && npm run i18n:check && npm run build && php artisan test`
Expected:
- `npm run test:js`: 13 passing node tests.
- `npm run i18n:check`: ✓.
- `npm run build`: clean.
- `php artisan test`: the same pass count as the Task 0 baseline on `main`, with no new failures. The backend is unchanged.

- [ ] **Step 2: Check the branch contents**

Run: `git log --oneline main..HEAD && git diff --stat main..HEAD`
Expected: the spec, the plan, and 7 feature commits. Only the files in the File Structure table plus the spec and plan appear.

- [ ] **Step 3: Hand off.** Report to the owner: the QA checklist results, and the expected small conflict in `AuthenticatedLayout.vue` with `feat/users-batch-oct` (impersonation banner in the main column) when merging. Then use superpowers:finishing-a-development-branch.
