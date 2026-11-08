# Writing Help Center articles

Articles live in `resources/help/<locale>/<topic>/<slug>.md` (Arabic first: `ar`).
Topics, their order, icons, permission module and the "Start here" list are in
`topics.php`; topic titles are UI strings `help.topic.<slug with _>` in
`resources/js/i18n/*.json`.

## An article

    ---
    title: كيف أضيف لاعباً جديداً        ← always "How do I…", a task
    summary: one line, what it's for
    module: players                     ← optional; defaults to the topic's module
                                          (superadmin = visible to superadmins only)
    pages: [players.index]              ← route names whose ? panel lists it
    keywords: [تسجيل, لاعب جديد]        ← words people type in search
    related: [players/find-player]
    ---

    Short intro paragraph.

    :::example
    Follow one example through the article (demo club: أحمد بن علي).
    :::

    :::step Press «لاعب جديد»
    One short sentence.
    ::figure players-index ring=add-player zoom=add-player
    :::

    :::tip
    ...
    :::

    :::warning
    ...
    :::

The `←` notes explain each line; they are not part of the file.

## Rules

- Simple Arabic, singular imperative, ≤ 15 words a sentence, one action per step.
- Button names exactly as the app shows them, in «…» (check `ar.json`).
- 3–7 steps; at most one tip and one warning.
- `ring=` what the user must click; `zoom=` the small thing they'd miss.

## Screenshots

One-time setup on a new machine (the script drives the full Chromium build):

    npx playwright install chromium

1. Put `data-help="<name>"` on the element in the Vue page (attribute only).
2. Add or extend the shot in `resources/help/shots.js`.
3. `npm run build && npm run help:shots` (or `npm run help:shots -- <shot>`).
4. Commit `public/help-shots/ar/*`.

Shots live in `public/help-shots`, not `public/help`: a real `public/help`
folder would be served instead of the `/help` page.

After any UI change, rerun `npm run help:shots`; it fails naming the shot and
target if a marked element disappeared.

## Check before committing

    php artisan help:check
    php artisan test --filter=Help
