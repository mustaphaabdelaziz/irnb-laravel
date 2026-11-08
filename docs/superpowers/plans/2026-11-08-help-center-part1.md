# Help Center Part 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An in-app, offline, Arabic Help Center: task articles with numbered step cards, real screenshots with amber rings and a magnifier, a `?` side panel on every page, Ctrl+K search — pilot content Getting started + Players.

**Architecture:** Markdown article files in `resources/help/ar/<topic>/<slug>.md` are parsed by a PHP `HelpArticleParser` into typed blocks; `HelpLibrary` loads, caches, enriches figures with screenshot URLs + target boxes, and filters by the user's module view rights. `HelpController` serves Inertia pages and two JSON endpoints. Vue components render blocks inside the normal AppShell using the app's own tokens. A Playwright script seeds a demo club on a throwaway SQLite file and regenerates light/dark WebP screenshots + box JSON.

**Tech Stack:** Laravel 13, Inertia 2 + Vue 3 `<script setup>`, Tailwind 3 (`primary-*` tokens driven by the club colour), vue-i18n (flat dotted keys), league/commonmark via `Str::markdown`, PHPUnit-style tests (`#[Test]`), `node --test`, Playwright + sharp (devDependencies only).

Spec: `docs/superpowers/specs/2026-11-08-help-center-design.md`.

## Global Constraints

- Offline only: no CDN, no remote fonts/scripts/images. Everything ships in the repo.
- Theme: Help UI uses existing components and Tailwind tokens (`primary-*`, `slate-*`, `dark:`); it follows the club colour and light/dark automatically. Dark mode uses `primary-300` (not 400) for text on dark.
- Screenshots: default emerald theme, 1280×800, Arabic, light + dark WebP. Markers/rings/magnifier border: `amber-500` in both themes.
- Article bodies Arabic only in Part 1; UI strings in AR/FR/EN.
- i18n: insert-only flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`; no key segment named after a String method (`search`, `link`, `replace`, `split`, `match`…). Use `t()` directly, never `te()`.
- Visibility: article module = front-matter `module`, else the topic's module from `topics.php`; null module = every signed-in user. Gate is `User::hasPermission($module, 'view')` (god admins pass).
- No new Composer dependency. New npm devDependencies: `playwright`, `sharp` only.
- Root-relative URLs for images (`/help/shots/...`) so web and desktop both load them.
- Phone: no horizontal scroll at 320 px; bare grids need `grid-cols-1`.
- Git: stage explicit files only (the working tree holds unrelated untracked files: `.claude/`, `ASIRAM.png`, `HANDOFF.md`, `brag-output/`). Never `git add -A`.
- Run PHP tests with `php artisan test --filter=<Name>`; JS lib tests with `node --test tests/js/<file>.test.mjs`.

## File map

| File | Responsibility |
|---|---|
| `app/Services/Help/HelpParseException.php` | Parse error with article key + line |
| `app/Services/Help/HelpArticle.php` | Immutable article DTO, summaries |
| `app/Services/Help/HelpArticleParser.php` | Front matter + block parsing, Markdown → safe HTML |
| `app/Services/Help/HelpLibrary.php` | Load/cache articles, topics, figure enrichment, visibility, problems list |
| `app/Http/Controllers/HelpController.php` | `/help`, article, panel JSON, search index JSON, guest login article |
| `app/Console/Commands/HelpCheck.php` | `php artisan help:check` content validation |
| `database/seeders/HelpDemoSeeder.php` | Demo club for screenshots |
| `resources/help/topics.php` | Topic order, icons, module gates, article order, start-here list |
| `resources/help/shots.js` | Screenshot shot list |
| `resources/help/README.md` | Authoring guide |
| `resources/help/ar/**.md` | Articles |
| `scripts/help-shots.mjs` | Screenshot generator |
| `resources/js/lib/helpZoom.js` | Pure magnifier maths |
| `resources/js/Composables/useHelpIndex.js` | Lazy search index for Ctrl+K |
| `resources/js/Components/Help/*.vue` | `HelpCallout`, `HelpFigure`, `HelpStep`, `HelpArticleBody`, `HelpDrawer` |
| `resources/js/Pages/Help/Index.vue`, `Show.vue`, `resources/js/Pages/Auth/LoginHelp.vue` | Pages |
| Modified: `routes/web.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Providers/AppServiceProvider.php`, `resources/js/Layouts/AppShell.vue`, `resources/js/Components/CommandPalette.vue`, `resources/js/Components/Icon.vue`, `resources/js/Pages/Auth/Login.vue`, Players pages (data-help attributes), `package.json`, `.gitignore`, i18n JSON files |

---

### Task 1: Article parser

**Files:**
- Create: `app/Services/Help/HelpParseException.php`
- Create: `app/Services/Help/HelpArticle.php`
- Create: `app/Services/Help/HelpArticleParser.php`
- Test: `tests/Unit/Help/HelpArticleParserTest.php`

**Interfaces:**
- Produces:
  - `HelpArticleParser::parse(string $raw, string $topic, string $slug): HelpArticle` — throws `HelpParseException`.
  - `HelpArticle` public readonly props: `topic, slug, title, summary, ?module, array pages, array keywords, array related, array blocks`; methods `key(): string` (`"topic/slug"`), `href(): string` (`"/help/topic/slug"`), `stepCount(): int`, `minutes(): int`, `headings(): list<string>`, `withBlocks(array $blocks): self`, `summary(): array` (`key, topic, slug, title, summary, minutes, href`).
  - Block shapes: `['type'=>'text','html'=>string]`, `['type'=>'step','number'=>int,'heading'=>string,'html'=>string,'figures'=>list<figure>]`, `['type'=>'tip'|'warning'|'example','html'=>string]`, figure `['type'=>'figure','shot'=>string,'ring'=>list<string>,'zoom'=>?string]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Help;

use App\Services\Help\HelpArticleParser;
use App\Services\Help\HelpParseException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpArticleParserTest extends TestCase
{
    private function parse(string $raw)
    {
        return (new HelpArticleParser)->parse($raw, 'players', 'add-player');
    }

    private const SAMPLE = <<<'MD'
---
title: كيف أضيف لاعباً جديداً
summary: سجّل لاعباً جديداً.
module: players
pages: [players.index, players.create]
keywords: [تسجيل, لاعب جديد]
related: [players/find-player]
---

مقدمة **قصيرة**.

:::example
اللاعب **أحمد بن علي**.
:::

:::step افتح صفحة اللاعبين
في القائمة اضغط «اللاعبون».
::figure players-index ring=nav-players
:::

:::step اضغط «إضافة لاعب»
ستجده أعلى الصفحة.
::figure players-index ring=add-player,players-search zoom=add-player
:::

:::tip
Ctrl + K
:::

:::warning
تأكد من تاريخ الميلاد.
:::

::figure players-create
MD;

    #[Test]
    public function it_reads_the_front_matter(): void
    {
        $a = $this->parse(self::SAMPLE);

        $this->assertSame('players/add-player', $a->key());
        $this->assertSame('/help/players/add-player', $a->href());
        $this->assertSame('كيف أضيف لاعباً جديداً', $a->title);
        $this->assertSame('سجّل لاعباً جديداً.', $a->summary);
        $this->assertSame('players', $a->module);
        $this->assertSame(['players.index', 'players.create'], $a->pages);
        $this->assertSame(['تسجيل', 'لاعب جديد'], $a->keywords);
        $this->assertSame(['players/find-player'], $a->related);
    }

    #[Test]
    public function it_builds_typed_blocks_in_order_and_numbers_steps(): void
    {
        $blocks = $this->parse(self::SAMPLE)->blocks;

        $this->assertSame(['text', 'example', 'step', 'step', 'tip', 'warning', 'figure'], array_column($blocks, 'type'));
        $this->assertStringContainsString('<strong>قصيرة</strong>', $blocks[0]['html']);
        $this->assertSame(1, $blocks[2]['number']);
        $this->assertSame('افتح صفحة اللاعبين', $blocks[2]['heading']);
        $this->assertSame(2, $blocks[3]['number']);
        $this->assertSame(
            ['type' => 'figure', 'shot' => 'players-index', 'ring' => ['add-player', 'players-search'], 'zoom' => 'add-player'],
            $blocks[3]['figures'][0],
        );
        $this->assertSame(['type' => 'figure', 'shot' => 'players-create', 'ring' => [], 'zoom' => null], $blocks[6]);
    }

    #[Test]
    public function it_derives_reading_time_and_headings(): void
    {
        $a = $this->parse(self::SAMPLE);

        $this->assertSame(2, $a->stepCount());
        $this->assertSame(1, $a->minutes());
        $this->assertSame(['افتح صفحة اللاعبين', 'اضغط «إضافة لاعب»'], $a->headings());
        $this->assertSame(
            ['key' => 'players/add-player', 'topic' => 'players', 'slug' => 'add-player', 'title' => 'كيف أضيف لاعباً جديداً', 'summary' => 'سجّل لاعباً جديداً.', 'minutes' => 1, 'href' => '/help/players/add-player'],
            $a->summary(),
        );
    }

    #[Test]
    public function raw_html_in_articles_is_stripped(): void
    {
        $a = $this->parse("---\ntitle: T\n---\n\n<script>alert(1)</script>نص");

        $this->assertStringNotContainsString('<script', $a->blocks[0]['html']);
        $this->assertStringContainsString('نص', $a->blocks[0]['html']);
    }

    #[Test]
    public function module_is_null_when_absent_and_windows_line_endings_work(): void
    {
        $a = $this->parse("---\r\ntitle: T\r\n---\r\n\r\n:::step S\r\nx\r\n:::\r\n");

        $this->assertNull($a->module);
        $this->assertSame([], $a->pages);
        $this->assertSame('S', $a->blocks[0]['heading']);
    }

    public static function brokenArticles(): array
    {
        return [
            'no front matter' => ["title: T\n"],
            'no title' => ["---\nsummary: x\n---\n"],
            'bad front matter line' => ["---\ntitle: T\nnot a pair\n---\n"],
            'unclosed block' => ["---\ntitle: T\n---\n:::tip\nx\n"],
            'stray closer' => ["---\ntitle: T\n---\n:::\n"],
            'step without heading' => ["---\ntitle: T\n---\n:::step\nx\n:::\n"],
            'figure in a tip' => ["---\ntitle: T\n---\n:::tip\n::figure a\n:::\n"],
            'figure without shot' => ["---\ntitle: T\n---\n::figure\n"],
            'unknown figure option' => ["---\ntitle: T\n---\n::figure a size=big\n"],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenArticles')]
    public function broken_articles_throw_with_the_article_key(string $raw): void
    {
        $this->expectException(HelpParseException::class);
        $this->expectExceptionMessage('players/add-player');

        $this->parse($raw);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=HelpArticleParserTest`
Expected: FAIL — `Class "App\Services\Help\HelpArticleParser" not found`.

- [ ] **Step 3: Write the implementation**

`app/Services/Help/HelpParseException.php`:

```php
<?php

namespace App\Services\Help;

use RuntimeException;

/** A help article that can't be read; the message starts with the article key. */
class HelpParseException extends RuntimeException {}
```

`app/Services/Help/HelpArticle.php`:

```php
<?php

namespace App\Services\Help;

/** One parsed help article. Blocks are plain arrays so they serialize straight to Inertia. */
final class HelpArticle
{
    /**
     * @param  list<string>  $pages  route names whose `?` panel lists this article
     * @param  list<string>  $keywords
     * @param  list<string>  $related  article keys ("topic/slug")
     * @param  list<array<string, mixed>>  $blocks
     */
    public function __construct(
        public readonly string $topic,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly ?string $module,
        public readonly array $pages,
        public readonly array $keywords,
        public readonly array $related,
        public readonly array $blocks,
    ) {}

    public function key(): string
    {
        return $this->topic.'/'.$this->slug;
    }

    public function href(): string
    {
        return '/help/'.$this->key();
    }

    public function stepCount(): int
    {
        return count(array_filter($this->blocks, fn (array $b): bool => $b['type'] === 'step'));
    }

    /** Reading estimate: about two steps a minute, never less than one. */
    public function minutes(): int
    {
        return max(1, (int) ceil($this->stepCount() / 2));
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_values(array_map(
            fn (array $b): string => $b['heading'],
            array_filter($this->blocks, fn (array $b): bool => $b['type'] === 'step'),
        ));
    }

    public function withBlocks(array $blocks): self
    {
        return new self($this->topic, $this->slug, $this->title, $this->summary, $this->module, $this->pages, $this->keywords, $this->related, $blocks);
    }

    /** @return array{key:string, topic:string, slug:string, title:string, summary:string, minutes:int, href:string} */
    public function summary(): array
    {
        return [
            'key' => $this->key(),
            'topic' => $this->topic,
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'minutes' => $this->minutes(),
            'href' => $this->href(),
        ];
    }
}
```

`app/Services/Help/HelpArticleParser.php`:

```php
<?php

namespace App\Services\Help;

use Illuminate\Support\Str;

/**
 * Reads one help article: a flat `key: value` front matter (lists as `[a, b]`)
 * then Markdown with blocks:
 *   :::step <heading> … :::   numbered automatically
 *   :::tip / :::warning / :::example … :::
 *   ::figure <shot> [ring=a,b] [zoom=a]   inside a step, or on its own
 * Raw HTML is stripped: articles are content, never markup.
 */
final class HelpArticleParser
{
    private const CALLOUTS = ['tip', 'warning', 'example'];

    public function parse(string $raw, string $topic, string $slug): HelpArticle
    {
        $where = "{$topic}/{$slug}";
        $raw = str_replace("\r\n", "\n", $raw);

        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $raw, $m)) {
            throw new HelpParseException("{$where}: missing front matter (--- … ---)");
        }

        $meta = $this->frontMatter($m[1], $where);
        $title = is_string($meta['title'] ?? null) ? $meta['title'] : '';
        if ($title === '') {
            throw new HelpParseException("{$where}: missing title");
        }

        $module = $meta['module'] ?? null;

        return new HelpArticle(
            topic: $topic,
            slug: $slug,
            title: $title,
            summary: is_string($meta['summary'] ?? null) ? $meta['summary'] : '',
            module: is_string($module) && $module !== '' ? $module : null,
            pages: $this->list($meta['pages'] ?? []),
            keywords: $this->list($meta['keywords'] ?? []),
            related: $this->list($meta['related'] ?? []),
            blocks: $this->blocks($m[2], $where),
        );
    }

    /** @return array<string, string|list<string>> */
    private function frontMatter(string $text, string $where): array
    {
        $meta = [];
        foreach (explode("\n", $text) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^([a-z_]+):\s*(.*)$/', $line, $m)) {
                throw new HelpParseException("{$where}: front matter line ".($i + 2)." is not `key: value`");
            }
            $value = trim($m[2]);
            if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $value = array_values(array_filter(
                    array_map('trim', explode(',', substr($value, 1, -1))),
                    fn (string $v): bool => $v !== '',
                ));
            }
            $meta[$m[1]] = $value;
        }

        return $meta;
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return $value === '' || $value === null ? [] : [(string) $value];
    }

    /** @return list<array<string, mixed>> */
    private function blocks(string $body, string $where): array
    {
        $blocks = [];
        $buffer = [];
        $open = null;
        $step = 0;

        $flush = function () use (&$buffer, &$blocks): void {
            $text = trim(implode("\n", $buffer));
            $buffer = [];
            if ($text !== '') {
                $blocks[] = ['type' => 'text', 'html' => $this->markdown($text)];
            }
        };

        foreach (explode("\n", $body) as $i => $line) {
            $trim = rtrim($line);
            $lineNo = $i + 1;

            if ($open === null && preg_match('/^:::(step|tip|warning|example)(?:\s+(.*))?$/u', $trim, $m)) {
                $flush();
                $heading = trim($m[2] ?? '');
                if ($m[1] === 'step' && $heading === '') {
                    throw new HelpParseException("{$where}: step without a heading (body line {$lineNo})");
                }
                $open = ['type' => $m[1], 'heading' => $heading, 'lines' => [], 'figures' => []];

                continue;
            }

            if ($trim === ':::') {
                if ($open === null) {
                    throw new HelpParseException("{$where}: `:::` closes nothing (body line {$lineNo})");
                }
                $html = $this->markdown(trim(implode("\n", $open['lines'])));
                $blocks[] = $open['type'] === 'step'
                    ? ['type' => 'step', 'number' => ++$step, 'heading' => $open['heading'], 'html' => $html, 'figures' => $open['figures']]
                    : ['type' => $open['type'], 'html' => $html];
                $open = null;

                continue;
            }

            if (str_starts_with($trim, '::figure')) {
                $figure = $this->figure($trim, $where, $lineNo);
                if ($open === null) {
                    $flush();
                    $blocks[] = $figure;
                } elseif ($open['type'] === 'step') {
                    $open['figures'][] = $figure;
                } else {
                    throw new HelpParseException("{$where}: figures go inside a step or on their own, not in :::{$open['type']} (body line {$lineNo})");
                }

                continue;
            }

            if ($open !== null) {
                $open['lines'][] = $line;
            } else {
                $buffer[] = $line;
            }
        }

        if ($open !== null) {
            throw new HelpParseException("{$where}: :::{$open['type']} is never closed");
        }
        $flush();

        return $blocks;
    }

    /** @return array{type:string, shot:string, ring:list<string>, zoom:?string} */
    private function figure(string $line, string $where, int $lineNo): array
    {
        $parts = preg_split('/\s+/', trim(substr($line, strlen('::figure'))), -1, PREG_SPLIT_NO_EMPTY);
        $shot = array_shift($parts) ?? '';
        if (! preg_match('/^[a-z0-9-]+$/', $shot)) {
            throw new HelpParseException("{$where}: figure needs a shot name like players-index (body line {$lineNo})");
        }

        $ring = [];
        $zoom = null;
        foreach ($parts as $part) {
            if (preg_match('/^ring=([a-z0-9,-]+)$/', $part, $m)) {
                $ring = array_values(array_filter(explode(',', $m[1])));
            } elseif (preg_match('/^zoom=([a-z0-9-]+)$/', $part, $m)) {
                $zoom = $m[1];
            } else {
                throw new HelpParseException("{$where}: unknown figure option `{$part}` (body line {$lineNo})");
            }
        }

        return ['type' => 'figure', 'shot' => $shot, 'ring' => $ring, 'zoom' => $zoom];
    }

    private function markdown(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return trim((string) Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=HelpArticleParserTest`
Expected: PASS (5 tests + 9 data sets).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Services/Help tests/Unit/Help
git add app/Services/Help/HelpParseException.php app/Services/Help/HelpArticle.php app/Services/Help/HelpArticleParser.php tests/Unit/Help/HelpArticleParserTest.php
git commit -m "feat(help): article parser with step, callout and figure blocks"
```

---

### Task 2: Help library (load, topics, screenshots, visibility)

**Files:**
- Create: `app/Services/Help/HelpLibrary.php`
- Modify: `app/Providers/AppServiceProvider.php` (register singleton in `register()`)
- Create fixtures: `tests/Fixtures/help/topics.php`, `tests/Fixtures/help/ar/getting-started/login.md`, `tests/Fixtures/help/ar/players/add-player.md`, `tests/Fixtures/help/ar/players/find-player.md`, `tests/Fixtures/help-shots/ar/players-index.json`, `tests/Fixtures/help-shots/ar/light/players-index.webp`
- Test: `tests/Feature/Help/HelpLibraryTest.php`

**Interfaces:**
- Consumes: `HelpArticleParser::parse`, `HelpArticle` (Task 1).
- Produces (`App\Services\Help\HelpLibrary`):
  - `__construct(HelpArticleParser $parser, string $contentPath, string $shotsPath, string $shotsUrl = '/help/shots')`
  - `contentLocale(string $locale): string` — `$locale` if it has articles, else `'ar'`.
  - `isFallback(string $locale): bool`
  - `topics(): array` — `topics.php` `topics` map: `slug => ['icon'=>string,'module'=>?string,'articles'=>list<string>]`.
  - `startHere(): list<string>` — article keys.
  - `all(string $locale): array<string, HelpArticle>` — keyed by `key()`, ordered by topic order then the topic's `articles` order then slug; figures enriched (below). Broken files are logged and skipped.
  - `visibleFor(User $user, string $locale): array<string, HelpArticle>`
  - `find(string $key, User $user, string $locale): ?HelpArticle` — visible only.
  - `article(string $key, string $locale): ?HelpArticle` — unfiltered (guest login article).
  - `forRoute(string $routeName, User $user, string $locale): list<HelpArticle>`
  - `problems(string $locale): list<string>` — parse errors, missing images/JSON, unknown ring/zoom names, unknown related keys, topics without config.
  - Enriched figure: `['type'=>'figure','shot','ring','zoom','light'=>?string,'dark'=>?string,'aspect'=>float,'rings'=>list<['name','x','y','w','h']>,'zoomBox'=>?['x','y','w','h']]` (percent values; URL `"/help/shots/{locale}/{theme}/{shot}.webp?v={mtime}"`).
  - Shot JSON format (written by Task 8): `{"width":1280,"height":800,"targets":{"add-player":{"x":71.2,"y":9.5,"w":8.4,"h":4.6}}}`.

- [ ] **Step 1: Create fixtures**

`tests/Fixtures/help/topics.php`:

```php
<?php

return [
    'topics' => [
        'getting-started' => ['icon' => 'home', 'module' => null, 'articles' => ['login']],
        'players' => ['icon' => 'players', 'module' => 'players', 'articles' => ['find-player', 'add-player']],
    ],
    'start_here' => ['getting-started/login', 'players/add-player'],
];
```

`tests/Fixtures/help/ar/getting-started/login.md`:

```md
---
title: كيف أدخل إلى التطبيق
summary: الدخول باسم المستخدم وكلمة المرور.
---

:::step اكتب اسم المستخدم
::figure login ring=login-username
:::
```

`tests/Fixtures/help/ar/players/add-player.md`:

```md
---
title: كيف أضيف لاعباً جديداً
summary: سجّل لاعباً جديداً.
pages: [players.index, players.create]
keywords: [تسجيل]
related: [players/find-player, players/missing]
---

:::step اضغط «إضافة لاعب»
::figure players-index ring=add-player,ghost zoom=add-player
:::
```

`tests/Fixtures/help/ar/players/find-player.md`:

```md
---
title: كيف أبحث عن لاعب
summary: ابحث بالاسم.
pages: [players.index]
---

نص.
```

`tests/Fixtures/help-shots/ar/players-index.json`:

```json
{"width":1280,"height":800,"targets":{"add-player":{"x":71.2,"y":9.5,"w":8.4,"h":4.6}}}
```

`tests/Fixtures/help-shots/ar/light/players-index.webp`: a file containing the 4 bytes `RIFF` (content is never decoded; only existence and mtime matter):

```bash
printf 'RIFF' > tests/Fixtures/help-shots/ar/light/players-index.webp
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Help;

use App\Models\Role;
use App\Models\User;
use App\Services\Help\HelpArticleParser;
use App\Services\Help\HelpLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function library(?string $content = null): HelpLibrary
    {
        return new HelpLibrary(
            new HelpArticleParser,
            $content ?? base_path('tests/Fixtures/help'),
            base_path('tests/Fixtures/help-shots'),
        );
    }

    private function userWith(array $permissions): User
    {
        $role = Role::factory()->create(['permissions' => $permissions]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    #[Test]
    public function articles_follow_topic_order_then_the_topic_article_order(): void
    {
        $this->assertSame(
            ['getting-started/login', 'players/find-player', 'players/add-player'],
            array_keys($this->library()->all('ar')),
        );
    }

    #[Test]
    public function figures_get_image_urls_aspect_and_boxes_from_the_shot_json(): void
    {
        $figure = $this->library()->all('ar')['players/add-player']->blocks[0]['figures'][0];

        $this->assertMatchesRegularExpression('#^/help/shots/ar/light/players-index\.webp\?v=\d+$#', $figure['light']);
        $this->assertNull($figure['dark']);
        $this->assertSame(1.6, $figure['aspect']);
        // Unknown ring names are dropped at runtime (help:check reports them).
        $this->assertSame([['name' => 'add-player', 'x' => 71.2, 'y' => 9.5, 'w' => 8.4, 'h' => 4.6]], $figure['rings']);
        $this->assertSame(['x' => 71.2, 'y' => 9.5, 'w' => 8.4, 'h' => 4.6], $figure['zoomBox']);
    }

    #[Test]
    public function a_figure_without_any_screenshot_has_null_images(): void
    {
        $figure = $this->library()->all('ar')['getting-started/login']->blocks[0]['figures'][0];

        $this->assertNull($figure['light']);
        $this->assertNull($figure['dark']);
        $this->assertSame([], $figure['rings']);
        $this->assertNull($figure['zoomBox']);
    }

    #[Test]
    public function articles_of_a_module_the_user_cannot_view_are_hidden(): void
    {
        $lib = $this->library();

        $this->assertSame(['getting-started/login'], array_keys($lib->visibleFor($this->userWith(['subscriptions' => ['view']]), 'ar')));
        $this->assertCount(3, $lib->visibleFor($this->userWith(['players' => ['view']]), 'ar'));
        $this->assertCount(3, $lib->visibleFor(User::factory()->admin()->create(), 'ar'));
        $this->assertNull($lib->find('players/add-player', $this->userWith([]), 'ar'));
        $this->assertNotNull($lib->article('players/add-player', 'ar'));
    }

    #[Test]
    public function for_route_lists_visible_articles_declaring_that_page(): void
    {
        $lib = $this->library();
        $viewer = $this->userWith(['players' => ['view']]);

        $this->assertSame(
            ['players/find-player', 'players/add-player'],
            array_map(fn ($a) => $a->key(), $lib->forRoute('players.index', $viewer, 'ar')),
        );
        $this->assertSame([], $lib->forRoute('players.index', $this->userWith([]), 'ar'));
        $this->assertSame([], $lib->forRoute('dashboard', $viewer, 'ar'));
    }

    #[Test]
    public function a_locale_without_articles_falls_back_to_arabic(): void
    {
        $lib = $this->library();

        $this->assertSame('ar', $lib->contentLocale('fr'));
        $this->assertTrue($lib->isFallback('fr'));
        $this->assertFalse($lib->isFallback('ar'));
        $this->assertCount(3, $lib->all('fr'));
    }

    #[Test]
    public function a_broken_article_is_skipped_at_runtime_and_reported_by_problems(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        File::copyDirectory(base_path('tests/Fixtures/help'), $dir);
        File::put("{$dir}/ar/players/broken.md", "no front matter\n");

        try {
            $lib = $this->library($dir);

            $this->assertArrayNotHasKey('players/broken', $lib->all('ar'));
            $problems = implode("\n", $lib->problems('ar'));
            $this->assertStringContainsString('players/broken: missing front matter', $problems);
            $this->assertStringContainsString('getting-started/login: figure "login" has no light screenshot', $problems);
            $this->assertStringContainsString('players/add-player: figure "players-index" has no dark screenshot', $problems);
            $this->assertStringContainsString('players/add-player: figure "players-index" rings unknown target "ghost"', $problems);
            $this->assertStringContainsString('players/add-player: related article "players/missing" does not exist', $problems);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    #[Test]
    public function the_container_builds_the_library_on_the_real_content_folder(): void
    {
        $this->assertInstanceOf(HelpLibrary::class, app(HelpLibrary::class));
        $this->assertSame(app(HelpLibrary::class), app(HelpLibrary::class));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=HelpLibraryTest`
Expected: FAIL — `Class "App\Services\Help\HelpLibrary" not found`.

- [ ] **Step 4: Write the implementation**

`app/Services/Help/HelpLibrary.php`:

```php
<?php

namespace App\Services\Help;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The Help Center's articles: read from resources/help/{locale}/{topic}/{slug}.md,
 * cached (the key changes whenever an article or screenshot file changes), with
 * figures enriched from the screenshot script's output in public/help/shots.
 */
final class HelpLibrary
{
    public const FALLBACK_LOCALE = 'ar';

    private ?array $config = null;

    public function __construct(
        private readonly HelpArticleParser $parser,
        private readonly string $contentPath,
        private readonly string $shotsPath,
        private readonly string $shotsUrl = '/help/shots',
    ) {}

    public function contentLocale(string $locale): string
    {
        return $this->files($locale) !== [] ? $locale : self::FALLBACK_LOCALE;
    }

    public function isFallback(string $locale): bool
    {
        return $this->contentLocale($locale) !== $locale;
    }

    /** @return array<string, array{icon:string, module:?string, articles:list<string>}> */
    public function topics(): array
    {
        return $this->config()['topics'] ?? [];
    }

    /** @return list<string> */
    public function startHere(): array
    {
        return $this->config()['start_here'] ?? [];
    }

    /** @return array<string, HelpArticle> */
    public function all(string $locale): array
    {
        $locale = $this->contentLocale($locale);

        return Cache::remember(
            "help:{$locale}:".$this->fingerprint($locale),
            now()->addDay(),
            fn (): array => $this->load($locale),
        );
    }

    /** @return array<string, HelpArticle> */
    public function visibleFor(User $user, string $locale): array
    {
        return array_filter($this->all($locale), fn (HelpArticle $a): bool => $this->canSee($user, $a));
    }

    public function find(string $key, User $user, string $locale): ?HelpArticle
    {
        return $this->visibleFor($user, $locale)[$key] ?? null;
    }

    public function article(string $key, string $locale): ?HelpArticle
    {
        return $this->all($locale)[$key] ?? null;
    }

    /** @return list<HelpArticle> */
    public function forRoute(string $routeName, User $user, string $locale): array
    {
        return array_values(array_filter(
            $this->visibleFor($user, $locale),
            fn (HelpArticle $a): bool => in_array($routeName, $a->pages, true),
        ));
    }

    /** Everything help:check reports. Uncached, so it always reflects the files. @return list<string> */
    public function problems(string $locale): array
    {
        $locale = $this->contentLocale($locale);
        $problems = [];
        $articles = $this->load($locale, $problems);
        $topics = $this->topics();

        foreach ($articles as $article) {
            if (! isset($topics[$article->topic])) {
                $problems[] = "{$article->key()}: topic \"{$article->topic}\" is missing from topics.php";
            }
            foreach ($article->related as $key) {
                if (! isset($articles[$key])) {
                    $problems[] = "{$article->key()}: related article \"{$key}\" does not exist";
                }
            }
            foreach ($this->figuresOf($article->blocks) as $figure) {
                $where = "{$article->key()}: figure \"{$figure['shot']}\"";
                foreach (['light', 'dark'] as $theme) {
                    if ($figure[$theme] === null) {
                        $problems[] = "{$where} has no {$theme} screenshot";
                    }
                }
                $targets = $this->shot($locale, $figure['shot'])['targets'] ?? [];
                foreach (array_filter([...$figure['ring'], $figure['zoom']]) as $name) {
                    if (! isset($targets[$name])) {
                        $problems[] = "{$where} rings unknown target \"{$name}\"";
                    }
                }
            }
        }
        foreach ($this->startHere() as $key) {
            if (! isset($articles[$key])) {
                $problems[] = "topics.php: start_here article \"{$key}\" does not exist";
            }
        }

        return array_values(array_unique($problems));
    }

    private function canSee(User $user, HelpArticle $article): bool
    {
        $module = $article->module ?? ($this->topics()[$article->topic]['module'] ?? null);

        return $module === null || $user->hasPermission($module, 'view');
    }

    /** Parse every article; parse errors are appended to $problems. @return array<string, HelpArticle> */
    private function load(string $locale, array &$problems = []): array
    {
        $articles = [];
        foreach ($this->files($locale) as $file) {
            $topic = basename(dirname($file));
            $slug = basename($file, '.md');
            try {
                $article = $this->parser->parse((string) file_get_contents($file), $topic, $slug);
                $articles[$article->key()] = $article->withBlocks($this->enrich($article->blocks, $locale));
            } catch (HelpParseException $e) {
                $problems[] = $e->getMessage();
                Log::warning('Help article skipped: '.$e->getMessage());
            }
        }

        return $this->ordered($articles);
    }

    /** @param array<string, HelpArticle> $articles @return array<string, HelpArticle> */
    private function ordered(array $articles): array
    {
        $topicOrder = array_flip(array_keys($this->topics()));
        uasort($articles, function (HelpArticle $a, HelpArticle $b) use ($topicOrder): int {
            // Topics missing from topics.php sort last, A→Z among themselves.
            $byTopic = ($topicOrder[$a->topic] ?? PHP_INT_MAX) <=> ($topicOrder[$b->topic] ?? PHP_INT_MAX);
            if ($byTopic !== 0) {
                return $byTopic;
            }
            if ($a->topic !== $b->topic) {
                return strcmp($a->topic, $b->topic);
            }
            $order = array_flip($this->topics()[$a->topic]['articles'] ?? []);

            return [($order[$a->slug] ?? PHP_INT_MAX), $a->slug] <=> [($order[$b->slug] ?? PHP_INT_MAX), $b->slug];
        });

        return $articles;
    }

    private function enrich(array $blocks, string $locale): array
    {
        return array_map(function (array $block) use ($locale): array {
            if ($block['type'] === 'figure') {
                return $this->enrichFigure($block, $locale);
            }
            if ($block['type'] === 'step') {
                $block['figures'] = array_map(fn (array $f): array => $this->enrichFigure($f, $locale), $block['figures']);
            }

            return $block;
        }, $blocks);
    }

    private function enrichFigure(array $figure, string $locale): array
    {
        $shot = $this->shot($locale, $figure['shot']);
        $targets = $shot['targets'] ?? [];
        $box = fn (array $t): array => ['x' => (float) $t['x'], 'y' => (float) $t['y'], 'w' => (float) $t['w'], 'h' => (float) $t['h']];

        return $figure + [
            'light' => $this->imageUrl($locale, 'light', $figure['shot']),
            'dark' => $this->imageUrl($locale, 'dark', $figure['shot']),
            'aspect' => isset($shot['width'], $shot['height']) && $shot['height'] > 0
                ? round($shot['width'] / $shot['height'], 4)
                : 1.6,
            'rings' => array_values(array_map(
                fn (string $name): array => ['name' => $name] + $box($targets[$name]),
                array_filter($figure['ring'], fn (string $name): bool => isset($targets[$name])),
            )),
            'zoomBox' => $figure['zoom'] !== null && isset($targets[$figure['zoom']]) ? $box($targets[$figure['zoom']]) : null,
        ];
    }

    private function imageUrl(string $locale, string $theme, string $shot): ?string
    {
        $path = "{$this->shotsPath}/{$locale}/{$theme}/{$shot}.webp";

        return is_file($path) ? "{$this->shotsUrl}/{$locale}/{$theme}/{$shot}.webp?v=".filemtime($path) : null;
    }

    private function shot(string $locale, string $shot): array
    {
        $path = "{$this->shotsPath}/{$locale}/{$shot}.json";

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    /** @return list<array<string, mixed>> */
    private function figuresOf(array $blocks): array
    {
        $figures = [];
        foreach ($blocks as $block) {
            if ($block['type'] === 'figure') {
                $figures[] = $block;
            } elseif ($block['type'] === 'step') {
                array_push($figures, ...$block['figures']);
            }
        }

        return $figures;
    }

    /** @return list<string> */
    private function files(string $locale): array
    {
        $files = glob("{$this->contentPath}/{$locale}/*/*.md") ?: [];
        sort($files);

        return $files;
    }

    private function fingerprint(string $locale): string
    {
        $paths = [
            ...$this->files($locale),
            ...(glob("{$this->shotsPath}/{$locale}/*.json") ?: []),
            ...(glob("{$this->shotsPath}/{$locale}/*/*.webp") ?: []),
            "{$this->contentPath}/topics.php",
        ];

        return md5(implode('|', array_map(fn (string $p): string => $p.':'.(@filemtime($p) ?: 0), $paths)));
    }

    private function config(): array
    {
        return $this->config ??= is_file("{$this->contentPath}/topics.php")
            ? (array) require "{$this->contentPath}/topics.php"
            : [];
    }
}
```

`app/Providers/AppServiceProvider.php` — inside `register()`, after the existing singletons, add (plus `use App\Services\Help\HelpArticleParser; use App\Services\Help\HelpLibrary;` at the top):

```php
        $this->app->singleton(HelpLibrary::class, fn ($app) => new HelpLibrary(
            $app->make(HelpArticleParser::class),
            resource_path('help'),
            public_path('help/shots'),
        ));
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=HelpLibraryTest`
Expected: PASS (8 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Services/Help app/Providers/AppServiceProvider.php tests/Feature/Help
git add app/Services/Help/HelpLibrary.php app/Providers/AppServiceProvider.php tests/Feature/Help/HelpLibraryTest.php tests/Fixtures/help tests/Fixtures/help-shots
git commit -m "feat(help): library with topics, screenshot boxes and module visibility"
```

---

### Task 3: Routes, controller, shared route name, UI strings, `help:check`

**Files:**
- Create: `app/Http/Controllers/HelpController.php`
- Create: `app/Console/Commands/HelpCheck.php`
- Create: `resources/help/topics.php`
- Create (stubs, filled in Task 5): `resources/js/Pages/Help/Index.vue`, `resources/js/Pages/Help/Show.vue`, `resources/js/Pages/Auth/LoginHelp.vue`
- Modify: `routes/web.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/i18n/ar.json`, `fr.json`, `en.json`, `package.json`
- Test: `tests/Feature/Help/HelpPagesTest.php`, `tests/Feature/Help/HelpCheckCommandTest.php`

**Interfaces:**
- Consumes: `HelpLibrary` (Task 2).
- Produces:
  - Routes: `help.index` `GET /help`; `help.panel` `GET /help/panel?route=`; `help.search-index` `GET /help/search-index`; `help.show` `GET /help/{topic}/{slug}`; `help.login` `GET /help/login` (guest-accessible).
  - Inertia `Help/Index` props: `topics: list<{slug, icon, articles: list<summary>}>`, `startHere: list<summary>`, `fallback: bool`.
  - Inertia `Help/Show` props: `article: summary + {blocks, keywords}`, `related: list<summary>`, `topic: {slug, icon}`, `fallback: bool`.
  - Inertia `Auth/LoginHelp` props: `article: summary + {blocks}`.
  - JSON `help.panel`: `{articles: list<summary>}`; JSON `help.search-index`: `{articles: list<summary + {keywords, headings}>}`.
  - Shared Inertia prop `routeName: ?string`.
  - i18n keys under `help.*` (list in Step 5).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Help/HelpPagesTest.php`:

```php
<?php

namespace Tests\Feature\Help;

use App\Models\Role;
use App\Models\User;
use App\Services\Help\HelpArticleParser;
use App\Services\Help\HelpLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(HelpLibrary::class, new HelpLibrary(
            new HelpArticleParser,
            base_path('tests/Fixtures/help'),
            base_path('tests/Fixtures/help-shots'),
        ));
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    #[Test]
    public function the_help_center_lists_only_visible_topics_and_start_here_articles(): void
    {
        $this->actingAs($this->userWith([]))
            ->get('/help')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Help/Index')
                ->has('topics', 1)
                ->where('topics.0.slug', 'getting-started')
                ->where('topics.0.icon', 'home')
                ->where('topics.0.articles.0.key', 'getting-started/login')
                ->has('startHere', 1)
                ->where('fallback', false));
    }

    #[Test]
    public function a_players_viewer_sees_the_players_topic(): void
    {
        $this->actingAs($this->userWith(['players' => ['view']]))
            ->get('/help')
            ->assertInertia(fn (Assert $page) => $page
                ->has('topics', 2)
                ->where('topics.1.articles.0.key', 'players/find-player')
                ->has('startHere', 2));
    }

    #[Test]
    public function an_article_page_has_blocks_related_and_topic(): void
    {
        $this->actingAs($this->userWith(['players' => ['view']]))
            ->get('/help/players/add-player')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Help/Show')
                ->where('article.title', 'كيف أضيف لاعباً جديداً')
                ->where('article.blocks.0.type', 'step')
                ->where('topic.slug', 'players')
                // players/missing does not exist and is dropped.
                ->has('related', 1)
                ->where('related.0.key', 'players/find-player'));
    }

    #[Test]
    public function hidden_or_unknown_articles_are_404(): void
    {
        $user = $this->userWith([]);
        $this->actingAs($user)->get('/help/players/add-player')->assertNotFound();
        $this->actingAs($user)->get('/help/players/nope')->assertNotFound();
    }

    #[Test]
    public function the_panel_lists_articles_for_a_route(): void
    {
        $this->actingAs($this->userWith(['players' => ['view']]))
            ->getJson('/help/panel?route=players.index')
            ->assertOk()
            ->assertJsonPath('articles.0.key', 'players/find-player')
            ->assertJsonPath('articles.1.key', 'players/add-player')
            ->assertJsonCount(2, 'articles');

        $this->actingAs($this->userWith([]))
            ->getJson('/help/panel?route=players.index')
            ->assertJsonCount(0, 'articles');
    }

    #[Test]
    public function the_search_index_carries_keywords_and_step_headings(): void
    {
        $this->actingAs($this->userWith(['players' => ['view']]))
            ->getJson('/help/search-index')
            ->assertOk()
            ->assertJsonCount(3, 'articles')
            ->assertJsonPath('articles.2.keywords', ['تسجيل'])
            ->assertJsonPath('articles.2.headings', ['اضغط «إضافة لاعب»']);
    }

    #[Test]
    public function help_needs_a_login_except_the_login_article(): void
    {
        $this->get('/help')->assertRedirect('/login');
        $this->getJson('/help/panel?route=x')->assertUnauthorized();

        $this->get('/help/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/LoginHelp')
                ->where('article.key', 'getting-started/login'));
    }

    #[Test]
    public function pages_share_their_route_name(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/help')
            ->assertInertia(fn (Assert $page) => $page->where('routeName', 'help.index'));
    }
}
```

`tests/Feature/Help/HelpCheckCommandTest.php`:

```php
<?php

namespace Tests\Feature\Help;

use App\Services\Help\HelpArticleParser;
use App\Services\Help\HelpLibrary;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpCheckCommandTest extends TestCase
{
    #[Test]
    public function it_lists_problems_and_fails(): void
    {
        $this->app->instance(HelpLibrary::class, new HelpLibrary(
            new HelpArticleParser,
            base_path('tests/Fixtures/help'),
            base_path('tests/Fixtures/help-shots'),
        ));

        $this->artisan('help:check')
            ->expectsOutputToContain('players/add-player: related article "players/missing" does not exist')
            ->expectsOutputToContain('getting-started/login: figure "login" has no light screenshot')
            ->assertFailed();
    }

    #[Test]
    public function it_reports_pages_that_are_not_route_names(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        mkdir("{$dir}/ar/players", 0777, true);
        file_put_contents("{$dir}/topics.php", "<?php return ['topics' => ['players' => ['icon' => 'players', 'module' => 'players', 'articles' => []]], 'start_here' => []];");
        file_put_contents("{$dir}/ar/players/a.md", "---\ntitle: A\npages: [players.index, players.nope]\n---\nنص\n");

        try {
            $this->app->instance(HelpLibrary::class, new HelpLibrary(new HelpArticleParser, $dir, $dir.'/shots'));

            $this->artisan('help:check')
                ->expectsOutputToContain('players/a: page "players.nope" is not a route name')
                ->assertFailed();
        } finally {
            @unlink("{$dir}/ar/players/a.md");
            @unlink("{$dir}/topics.php");
            @rmdir("{$dir}/ar/players");
            @rmdir("{$dir}/ar");
            @rmdir($dir);
        }
    }

    #[Test]
    public function clean_content_passes(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        mkdir("{$dir}/ar/players", 0777, true);
        file_put_contents("{$dir}/topics.php", "<?php return ['topics' => ['players' => ['icon' => 'players', 'module' => 'players', 'articles' => []]], 'start_here' => ['players/a']];");
        file_put_contents("{$dir}/ar/players/a.md", "---\ntitle: A\npages: [players.index]\n---\nنص\n");

        try {
            $this->app->instance(HelpLibrary::class, new HelpLibrary(new HelpArticleParser, $dir, $dir.'/shots'));

            $this->artisan('help:check')->expectsOutputToContain('1 article')->assertSuccessful();
        } finally {
            @unlink("{$dir}/ar/players/a.md");
            @unlink("{$dir}/topics.php");
            @rmdir("{$dir}/ar/players");
            @rmdir("{$dir}/ar");
            @rmdir($dir);
        }
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="HelpPagesTest|HelpCheckCommandTest"`
Expected: FAIL — 404 on `/help`, command `help:check` not defined.

- [ ] **Step 3: Controller, command, routes, shared prop, real topics file, page stubs**

`app/Http/Controllers/HelpController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Services\Help\HelpArticle;
use App\Services\Help\HelpLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The in-app manual. Every signed-in user may open it; articles are filtered per module. */
class HelpController extends Controller
{
    public function __construct(private readonly HelpLibrary $help) {}

    public function index(Request $request): Response
    {
        $locale = app()->getLocale();
        $visible = $this->help->visibleFor($request->user(), $locale);

        $topics = [];
        foreach ($this->help->topics() as $slug => $topic) {
            $articles = array_values(array_map(
                fn (HelpArticle $a): array => $a->summary(),
                array_filter($visible, fn (HelpArticle $a): bool => $a->topic === $slug),
            ));
            if ($articles !== []) {
                $topics[] = ['slug' => $slug, 'icon' => $topic['icon'], 'articles' => $articles];
            }
        }

        return Inertia::render('Help/Index', [
            'topics' => $topics,
            'startHere' => array_values(array_filter(array_map(
                fn (string $key): ?array => isset($visible[$key]) ? $visible[$key]->summary() : null,
                $this->help->startHere(),
            ))),
            'fallback' => $this->help->isFallback($locale),
        ]);
    }

    public function show(Request $request, string $topic, string $slug): Response
    {
        $locale = app()->getLocale();
        $article = $this->help->find("{$topic}/{$slug}", $request->user(), $locale);
        abort_if($article === null, 404);

        $visible = $this->help->visibleFor($request->user(), $locale);

        return Inertia::render('Help/Show', [
            'article' => $article->summary() + ['blocks' => $article->blocks, 'keywords' => $article->keywords],
            'related' => array_values(array_filter(array_map(
                fn (string $key): ?array => isset($visible[$key]) ? $visible[$key]->summary() : null,
                $article->related,
            ))),
            'topic' => ['slug' => $topic, 'icon' => $this->help->topics()[$topic]['icon'] ?? 'document'],
            'fallback' => $this->help->isFallback($locale),
        ]);
    }

    public function panel(Request $request): JsonResponse
    {
        $articles = $this->help->forRoute((string) $request->query('route', ''), $request->user(), app()->getLocale());

        return response()->json(['articles' => array_map(fn (HelpArticle $a): array => $a->summary(), $articles)]);
    }

    public function searchIndex(Request $request): JsonResponse
    {
        $articles = $this->help->visibleFor($request->user(), app()->getLocale());

        return response()->json(['articles' => array_values(array_map(
            fn (HelpArticle $a): array => $a->summary() + ['keywords' => $a->keywords, 'headings' => $a->headings()],
            $articles,
        ))]);
    }

    /** Before signing in nobody can open /help, so the login article has its own page. */
    public function login(): Response
    {
        $article = $this->help->article('getting-started/login', app()->getLocale());
        abort_if($article === null, 404);

        return Inertia::render('Auth/LoginHelp', [
            'article' => $article->summary() + ['blocks' => $article->blocks],
        ]);
    }
}
```

`app/Console/Commands/HelpCheck.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Help\HelpLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class HelpCheck extends Command
{
    protected $signature = 'help:check {--locale=ar}';

    protected $description = 'Validate Help Center articles: syntax, screenshots, ring targets, related links, page route names';

    public function handle(HelpLibrary $help): int
    {
        $locale = (string) $this->option('locale');
        $problems = $help->problems($locale);

        foreach ($help->all($locale) as $article) {
            foreach ($article->pages as $page) {
                if (! Route::has($page)) {
                    $problems[] = "{$article->key()}: page \"{$page}\" is not a route name";
                }
            }
        }

        $count = count($help->all($locale));
        if ($problems === []) {
            $this->info("Help content OK — {$count} article(s).");

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        $this->line(count($problems)." problem(s) in {$count} article(s).");

        return self::FAILURE;
    }
}
```

`routes/web.php` — add **above** the `Route::middleware(['auth', 'approved', 'permission'])` group (line ~165), plus `use App\Http\Controllers\HelpController;` with the other imports:

```php
// The manual: open to every signed-in user (no `permission` middleware);
// HelpLibrary filters articles by module. /help/login is for people who
// can't sign in yet.
Route::get('/help/login', [HelpController::class, 'login'])->name('help.login');
Route::middleware(['auth', 'approved'])->group(function () {
    Route::get('/help', [HelpController::class, 'index'])->name('help.index');
    Route::get('/help/panel', [HelpController::class, 'panel'])->name('help.panel');
    Route::get('/help/search-index', [HelpController::class, 'searchIndex'])->name('help.search-index');
    Route::get('/help/{topic}/{slug}', [HelpController::class, 'show'])
        ->where(['topic' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+'])
        ->name('help.show');
});
```

`app/Http/Middleware/HandleInertiaRequests.php` — in the returned array, after `'locale' => app()->getLocale(),` add:

```php
            // Which page this is, so the `?` help panel can ask for its articles.
            'routeName' => fn () => $request->route()?->getName(),
```

`resources/help/topics.php` (real content, used by Tasks 9–10):

```php
<?php

// Help Center topics in display order. `module` gates the topic's articles
// (null = every signed-in user); an article's own `module:` overrides it.
// `articles` orders slugs inside the topic; unlisted ones follow A→Z.
// Topic titles are UI strings: help.topic.<slug with _ for ->.
return [
    'topics' => [
        'getting-started' => ['icon' => 'home', 'module' => null, 'articles' => ['login', 'find-your-way', 'profile-password']],
        'players' => ['icon' => 'players', 'module' => 'players', 'articles' => ['add-player', 'find-player', 'player-page', 'edit-player', 'documents', 'branch-registrations']],
    ],
    'start_here' => [
        'getting-started/login',
        'getting-started/find-your-way',
        'players/add-player',
        'players/find-player',
    ],
];
```

Page stubs (replaced in Task 5) — `resources/js/Pages/Help/Index.vue`, `resources/js/Pages/Help/Show.vue`, `resources/js/Pages/Auth/LoginHelp.vue`, each:

```vue
<script setup>
defineProps({ article: Object, topics: Array, startHere: Array, related: Array, topic: Object, fallback: Boolean });
</script>

<template>
    <div />
</template>
```

`package.json` `scripts` — add:

```json
        "help:check": "php artisan help:check",
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="HelpPagesTest|HelpCheckCommandTest"`
Expected: PASS (11 tests).

If `help_needs_a_login_except_the_login_article` fails on `assertRedirect('/login')`, check `bootstrap/app.php` for the guest redirect path and use the path the app actually redirects to.

- [ ] **Step 5: Add UI strings**

Insert these flat keys into each locale file (keep the file's existing alphabetical order; insert-only, don't reformat other lines).

| key | ar | fr | en |
|---|---|---|---|
| `help.title` | المساعدة | Aide | Help |
| `help.center` | مركز المساعدة | Centre d'aide | Help Center |
| `help.welcome` | كيف يمكننا مساعدتك؟ | Comment pouvons-nous vous aider ? | How can we help? |
| `help.search_placeholder` | اكتب ما تبحث عنه… مثلاً «إضافة لاعب» | Que cherchez-vous ? ex. « ajouter un joueur » | What are you looking for? e.g. "add a player" |
| `help.start_here` | ابدأ من هنا | Commencez ici | Start here |
| `help.start_here_hint` | دروس قصيرة لتتعلم الأساسيات خطوة بخطوة | De courtes leçons pour apprendre l'essentiel pas à pas | Short lessons to learn the basics step by step |
| `help.recent` | قرأته مؤخراً | Lu récemment | Recently read |
| `help.minutes` | مدة القراءة: {n} د | {n} min de lecture | {n} min read |
| `help.step` | الخطوة {n} | Étape {n} | Step {n} |
| `help.related` | مقالات ذات صلة | Articles liés | Related articles |
| `help.helpful` | هل كان هذا مفيداً؟ | Cet article vous a-t-il aidé ? | Was this helpful? |
| `help.thanks` | شكراً لك! | Merci ! | Thank you! |
| `help.print_page` | طباعة | Imprimer | Print |
| `help.example` | مثال | Exemple | Example |
| `help.tip` | نصيحة | Astuce | Tip |
| `help.warning` | انتبه | Attention | Careful |
| `help.no_articles` | لا توجد مقالات لهذه الصفحة بعد. | Pas encore d'article pour cette page. | No articles for this page yet. |
| `help.open_center` | افتح مركز المساعدة | Ouvrir le centre d'aide | Open the Help Center |
| `help.this_page` | مساعدة لهذه الصفحة | Aide pour cette page | Help for this page |
| `help.arabic_only` | هذا الدليل متوفر بالعربية فقط حالياً. | Ce guide n'existe qu'en arabe pour l'instant. | This guide is only in Arabic for now. |
| `help.login_help` | تحتاج مساعدة في الدخول؟ | Besoin d'aide pour vous connecter ? | Need help signing in? |
| `help.back_to_login` | العودة إلى صفحة الدخول | Retour à la connexion | Back to sign in |
| `help.no_results` | لا توجد نتائج | Aucun résultat | No results |
| `help.picture_missing` | الصورة غير متوفرة بعد | Image pas encore disponible | Picture not available yet |
| `help.zoom_hint` | اضغط على الصورة لتكبيرها | Touchez l'image pour l'agrandir | Tap the picture to enlarge it |
| `help.close` | إغلاق | Fermer | Close |
| `help.all_articles` | كل المقالات | Tous les articles | All articles |
| `help.topic.getting_started` | البداية | Premiers pas | Getting started |
| `help.topic.players` | اللاعبون | Joueurs | Players |

Run: `npm run i18n:check`
Expected: exit 0.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Http/Controllers/HelpController.php app/Console/Commands/HelpCheck.php app/Http/Middleware/HandleInertiaRequests.php routes/web.php tests/Feature/Help
git add app/Http/Controllers/HelpController.php app/Console/Commands/HelpCheck.php app/Http/Middleware/HandleInertiaRequests.php routes/web.php resources/help/topics.php resources/js/Pages/Help/Index.vue resources/js/Pages/Help/Show.vue resources/js/Pages/Auth/LoginHelp.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json package.json tests/Feature/Help/HelpPagesTest.php tests/Feature/Help/HelpCheckCommandTest.php
git commit -m "feat(help): help routes, panel and search JSON, help:check, UI strings"
```

---

### Task 4: Magnifier maths (pure JS)

**Files:**
- Create: `resources/js/lib/helpZoom.js`
- Test: `tests/js/helpZoom.test.mjs`

**Interfaces:**
- Produces:
  - `zoomStyle(box: {x,y,w,h}, aspect = 1.6, margin = 0.6): { backgroundSize: string, backgroundPosition: string }` — for a square bubble whose background is the same screenshot.
  - `bubbleSide(box): 'left' | 'right'` — side away from the target.

- [ ] **Step 1: Write the failing test**

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { zoomStyle, bubbleSide } from '../../resources/js/lib/helpZoom.js';

// box values are percentages of the screenshot (x, w of width; y, h of height).

test('a centred target is framed with the margin on each side', () => {
    // 10% wide target → region 10 * (1 + 2 * 0.6) = 22% of the width → image 454.55% of the bubble.
    const s = zoomStyle({ x: 45, y: 45, w: 10, h: 10 }, 1.6);
    assert.equal(s.backgroundSize, '454.55% auto');
    // region x = 50 - 11 = 39 → 39 / (100 - 22) = 50%; region h = 22 * 1.6 = 35.2 → y = 50 - 17.6 = 32.4 → 32.4 / 64.8 = 50%.
    assert.equal(s.backgroundPosition, '50% 50%');
});

test('a tall target sizes the region by its height', () => {
    // h 16% of height = 10% of width at aspect 1.6 → region 22%.
    assert.equal(zoomStyle({ x: 48, y: 42, w: 4, h: 16 }, 1.6).backgroundSize, '454.55% auto');
});

test('regions are clamped inside the picture at the edges', () => {
    assert.equal(zoomStyle({ x: 0, y: 0, w: 10, h: 10 }, 1.6).backgroundPosition, '0% 0%');
    assert.equal(zoomStyle({ x: 90, y: 90, w: 10, h: 10 }, 1.6).backgroundPosition, '100% 100%');
});

test('tiny targets are not blown up past 8% of the width', () => {
    assert.equal(zoomStyle({ x: 50, y: 50, w: 1, h: 1 }, 1.6).backgroundSize, '1250% auto');
});

test('a huge target never asks for more than the whole picture', () => {
    const s = zoomStyle({ x: 0, y: 0, w: 100, h: 100 }, 1.6);
    assert.equal(s.backgroundSize, '100% auto');
    assert.equal(s.backgroundPosition, '0% 0%');
});

test('the bubble sits on the side away from the target', () => {
    assert.equal(bubbleSide({ x: 70, y: 10, w: 10, h: 5 }), 'left');
    assert.equal(bubbleSide({ x: 10, y: 10, w: 10, h: 5 }), 'right');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test tests/js/helpZoom.test.mjs`
Expected: FAIL — cannot find module `helpZoom.js`.

- [ ] **Step 3: Write the implementation**

```js
// Magnifier bubble for help screenshots: a square showing the target box (plus
// a margin) cut from the same image with background-size/position. Pure, so
// `node --test` loads it directly.
//
// box: percentages of the screenshot — x, w of its width; y, h of its height.
// aspect: screenshot width / height.

const round = (n) => Math.round(n * 100) / 100;
const clamp = (n, lo, hi) => Math.min(hi, Math.max(lo, n));

export function zoomStyle(box, aspect = 1.6, margin = 0.6) {
    // Compare width and height in the same unit: % of the image width.
    const side = Math.max(box.w, box.h / aspect) * (1 + 2 * margin);
    const regionW = clamp(side, 8, 100);
    const regionH = Math.min(100, regionW * aspect); // % of the height, square on screen

    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;
    const regionX = clamp(cx - regionW / 2, 0, 100 - regionW);
    const regionY = clamp(cy - regionH / 2, 0, 100 - regionH);

    // CSS: position p% puts the image's p% point on the bubble's p% point.
    const posX = regionW >= 100 ? 0 : (regionX / (100 - regionW)) * 100;
    const posY = regionH >= 100 ? 0 : (regionY / (100 - regionH)) * 100;

    return {
        backgroundSize: `${round(10000 / regionW)}% auto`,
        backgroundPosition: `${round(posX)}% ${round(posY)}%`,
    };
}

export function bubbleSide(box) {
    return box.x + box.w / 2 >= 50 ? 'left' : 'right';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test tests/js/helpZoom.test.mjs`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/lib/helpZoom.js tests/js/helpZoom.test.mjs
git commit -m "feat(help): magnifier maths for screenshot bubbles"
```

---

### Task 5: Help Center pages and article components

**Files:**
- Create: `resources/js/Components/Help/HelpCallout.vue`, `HelpFigure.vue`, `HelpStep.vue`, `HelpArticleBody.vue`
- Replace stubs: `resources/js/Pages/Help/Index.vue`, `resources/js/Pages/Help/Show.vue`, `resources/js/Pages/Auth/LoginHelp.vue`
- Modify: `resources/js/Components/Icon.vue` (add `help` icon), `resources/js/Pages/Auth/Login.vue` (help link)

**Interfaces:**
- Consumes: Inertia props from Task 3; `zoomStyle`, `bubbleSide` (Task 4); `useTheme()` (`isDark` ref); `readJson`/`writeJson` from `@/lib/safeStorage`; `normalize` from `@/lib/navigation`.
- Produces: `<HelpArticleBody :blocks>`; `<HelpFigure :figure :number>`; localStorage keys `help.recent` (list of `{key,title,href}`, max 5) and `help.feedback` (`{[key]: 'up'|'down'}`).

- [ ] **Step 1: Add the `help` icon**

In `resources/js/Components/Icon.vue`, in the `icons` object after `search:` add:

```js
    help: '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 0 1 4.8.9c0 1.7-2.4 2.2-2.4 3.8"/><circle cx="12" cy="17" r="0.6" fill="currentColor"/>',
```

- [ ] **Step 2: `HelpCallout.vue`**

```vue
<script setup>
import { useI18n } from 'vue-i18n';

// Tip / warning / example boxes inside an article. `html` is server-rendered
// Markdown with raw HTML stripped (HelpArticleParser), so v-html is safe.
defineProps({
    type: { type: String, required: true }, // tip | warning | example
    html: { type: String, required: true },
});

const { t } = useI18n();

const styles = {
    tip: { icon: '💡', box: 'border-primary-500 bg-primary-50 text-primary-900 dark:border-primary-400 dark:bg-primary-500/10 dark:text-primary-100', label: 'help.tip' },
    warning: { icon: '⚠️', box: 'border-amber-500 bg-amber-50 text-amber-900 dark:border-amber-400 dark:bg-amber-500/10 dark:text-amber-100', label: 'help.warning' },
    example: { icon: '📋', box: 'border-sky-500 bg-sky-50 text-sky-900 dark:border-sky-400 dark:bg-sky-500/10 dark:text-sky-100', label: 'help.example' },
};
</script>

<template>
    <aside class="my-4 rounded-xl border-s-4 px-4 py-3 text-base leading-relaxed" :class="styles[type].box">
        <p class="mb-1 font-bold"><span aria-hidden="true">{{ styles[type].icon }}</span> {{ t(styles[type].label) }}</p>
        <div class="help-prose" v-html="html" />
    </aside>
</template>
```

- [ ] **Step 3: `HelpFigure.vue`**

```vue
<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useTheme } from '@/Composables/useTheme';
import { bubbleSide, zoomStyle } from '@/lib/helpZoom';

// A screenshot from the help script with amber rings on the targets and a
// magnifier bubble on the zoom target. Shows the shot that matches the user's
// light/dark mode; falls back to the other one, then to a placeholder.
const props = defineProps({
    figure: { type: Object, required: true },
    number: { type: Number, default: null },
    alt: { type: String, default: '' },
});

const { t } = useI18n();
const { isDark } = useTheme();
const enlarged = ref(false);

const src = computed(() => (isDark.value ? props.figure.dark ?? props.figure.light : props.figure.light ?? props.figure.dark));
const zoom = computed(() => (src.value && props.figure.zoomBox ? zoomStyle(props.figure.zoomBox, props.figure.aspect ?? 1.6) : null));
const side = computed(() => (props.figure.zoomBox ? bubbleSide(props.figure.zoomBox) : 'left'));
// Screenshots are physical pictures: position with left/top even in RTL.
const place = (r) => ({ left: `${r.x}%`, top: `${r.y}%`, width: `${r.w}%`, height: `${r.h}%` });
</script>

<template>
    <figure class="my-4">
        <div v-if="src" class="relative" :class="zoom ? 'pb-16 sm:pb-20' : ''">
            <button
                type="button"
                class="relative block w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-slate-700 dark:bg-slate-900"
                :aria-label="t('help.zoom_hint')"
                :title="t('help.zoom_hint')"
                @click="enlarged = true"
            >
                <img :src="src" :alt="alt" class="block h-auto w-full" loading="lazy" />
                <span
                    v-for="ring in figure.rings"
                    :key="ring.name"
                    class="pointer-events-none absolute rounded-md outline outline-[3px] outline-offset-2 outline-amber-500 shadow-[0_0_0_7px_rgba(245,158,11,0.28)]"
                    :style="place(ring)"
                >
                    <span
                        v-if="number"
                        class="absolute -right-3 -top-3 flex h-6 w-6 items-center justify-center rounded-full bg-amber-500 text-xs font-bold text-white shadow ring-2 ring-white dark:ring-slate-900"
                    >{{ number }}</span>
                </span>
            </button>
            <div
                v-if="zoom"
                class="pointer-events-none absolute bottom-0 h-28 w-28 rounded-full border-4 border-amber-500 bg-white bg-no-repeat shadow-lg dark:bg-slate-900 sm:h-32 sm:w-32"
                :class="side === 'left' ? 'left-3' : 'right-3'"
                :style="{ backgroundImage: `url('${src}')`, ...zoom }"
                aria-hidden="true"
            />
        </div>
        <div
            v-else
            class="flex aspect-[16/10] items-center justify-center rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-400"
        >
            {{ t('help.picture_missing') }}
        </div>

        <Teleport to="body">
            <div
                v-if="enlarged"
                class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/85 p-3 print:hidden"
                role="dialog"
                aria-modal="true"
                @click="enlarged = false"
                @keydown.esc="enlarged = false"
            >
                <img :src="src" :alt="alt" class="max-h-full max-w-full rounded-lg shadow-2xl" />
                <button type="button" class="absolute end-4 top-4 rounded-full bg-white/90 px-3 py-1 text-sm font-semibold text-slate-800" @click.stop="enlarged = false">
                    {{ t('help.close') }}
                </button>
            </div>
        </Teleport>
    </figure>
</template>
```

- [ ] **Step 4: `HelpStep.vue` and `HelpArticleBody.vue`**

`HelpStep.vue`:

```vue
<script setup>
import HelpFigure from '@/Components/Help/HelpFigure.vue';

// One numbered step card: big number, one short sentence, its screenshot(s).
defineProps({ step: { type: Object, required: true } });
</script>

<template>
    <section class="help-step mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
        <h2 class="flex items-start gap-3 text-lg font-bold text-slate-900 dark:text-slate-100 sm:text-xl">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-600 text-base font-bold text-white shadow-sm">{{ step.number }}</span>
            <span class="pt-1">{{ step.heading }}</span>
        </h2>
        <div v-if="step.html" class="help-prose mt-2 ps-12 text-base leading-relaxed text-slate-700 dark:text-slate-300" v-html="step.html" />
        <HelpFigure v-for="(figure, i) in step.figures" :key="i" :figure="figure" :number="step.number" :alt="step.heading" />
    </section>
</template>
```

`HelpArticleBody.vue`:

```vue
<script setup>
import HelpCallout from '@/Components/Help/HelpCallout.vue';
import HelpFigure from '@/Components/Help/HelpFigure.vue';
import HelpStep from '@/Components/Help/HelpStep.vue';

// Renders the parser's typed blocks in order. Text/callout HTML is
// server-rendered Markdown with raw HTML stripped.
defineProps({ blocks: { type: Array, required: true } });
</script>

<template>
    <div class="help-article">
        <template v-for="(block, i) in blocks" :key="i">
            <HelpStep v-if="block.type === 'step'" :step="block" />
            <HelpCallout v-else-if="['tip', 'warning', 'example'].includes(block.type)" :type="block.type" :html="block.html" />
            <HelpFigure v-else-if="block.type === 'figure'" :figure="block" />
            <div v-else class="help-prose my-4 text-base leading-relaxed text-slate-700 dark:text-slate-300" v-html="block.html" />
        </template>
    </div>
</template>

<style>
.help-prose p + p { margin-top: 0.5rem; }
.help-prose ul { list-style: disc; padding-inline-start: 1.25rem; }
.help-prose ol { list-style: decimal; padding-inline-start: 1.25rem; }
.help-prose li + li { margin-top: 0.25rem; }
.help-prose strong { font-weight: 700; }
.help-prose kbd, .help-prose code { border-radius: 0.375rem; border: 1px solid rgb(203 213 225); padding: 0 0.35rem; font-family: inherit; font-size: 0.9em; }
@media print {
    .help-step { break-inside: avoid; box-shadow: none; }
}
</style>
```

- [ ] **Step 5: `Pages/Help/Index.vue`**

```vue
<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import { normalize } from '@/lib/navigation';
import { readJson } from '@/lib/safeStorage';

const props = defineProps({
    topics: { type: Array, required: true },
    startHere: { type: Array, required: true },
    fallback: { type: Boolean, default: false },
});

const { t } = useI18n();
const query = ref('');
const recent = readJson('help.recent', []);
const topicLabel = (slug) => t(`help.topic.${slug.replaceAll('-', '_')}`);

const all = computed(() => props.topics.flatMap((topic) => topic.articles.map((a) => ({
    ...a,
    haystack: normalize(`${a.title} ${a.summary} ${topicLabel(topic.slug)}`),
}))));
const results = computed(() => {
    const q = normalize(query.value);
    return q === '' ? [] : all.value.filter((a) => a.haystack.includes(q));
});
</script>

<template>
    <Head :title="t('help.center')" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('help.center') }}</h1>
        </template>

        <div class="mx-auto max-w-4xl">
            <p v-if="fallback" class="mb-4 rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('help.arabic_only') }}</p>

            <div class="mb-6 text-center">
                <p class="mb-4 text-2xl font-bold text-slate-900 dark:text-slate-100 sm:text-3xl">{{ t('help.welcome') }}</p>
                <label class="relative block">
                    <Icon name="search" class="pointer-events-none absolute start-4 top-1/2 -translate-y-1/2 text-xl text-slate-400" />
                    <input
                        v-model="query"
                        type="search"
                        data-help="help-search"
                        :placeholder="t('help.search_placeholder')"
                        class="w-full rounded-2xl border-2 border-primary-500 bg-white py-4 pe-4 ps-12 text-base shadow-sm focus:border-primary-600 focus:ring-primary-500 dark:border-primary-400 dark:bg-slate-900 dark:text-slate-100"
                    />
                </label>
            </div>

            <div v-if="query.trim() !== ''" class="mb-8 space-y-2">
                <Link v-for="a in results" :key="a.key" :href="a.href" class="block rounded-xl border border-slate-200 bg-white px-4 py-3 hover:border-primary-400 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-primary-500">
                    <p class="font-semibold text-slate-900 dark:text-slate-100">{{ a.title }}</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ a.summary }}</p>
                </Link>
                <p v-if="results.length === 0" class="py-6 text-center text-slate-500">{{ t('help.no_results') }}</p>
            </div>

            <template v-else>
                <section v-if="startHere.length" class="mb-8 rounded-2xl bg-gradient-to-br from-primary-600 to-primary-500 p-5 text-white shadow-glow sm:p-6">
                    <p class="text-xl font-bold"><span aria-hidden="true">🚀</span> {{ t('help.start_here') }}</p>
                    <p class="mb-4 text-sm text-white/90">{{ t('help.start_here_hint') }}</p>
                    <ol class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <li v-for="(a, i) in startHere" :key="a.key">
                            <Link :href="a.href" class="flex items-center gap-3 rounded-xl bg-white/15 px-3 py-2.5 font-semibold hover:bg-white/25">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white text-sm font-bold text-primary-700">{{ i + 1 }}</span>
                                <span>{{ a.title }}</span>
                            </Link>
                        </li>
                    </ol>
                </section>

                <section v-if="recent.length" class="mb-8">
                    <h2 class="mb-2 text-sm font-bold text-slate-500 dark:text-slate-400">{{ t('help.recent') }}</h2>
                    <div class="flex flex-wrap gap-2">
                        <Link v-for="r in recent" :key="r.key" :href="r.href" class="rounded-full border border-slate-200 bg-white px-3 py-1 text-sm text-slate-700 hover:border-primary-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">{{ r.title }}</Link>
                    </div>
                </section>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <section v-for="topic in topics" :key="topic.slug" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="mb-3 flex items-center gap-3 text-lg font-bold text-slate-900 dark:text-slate-100">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-50 text-xl text-primary-600 dark:bg-primary-500/15 dark:text-primary-300"><Icon :name="topic.icon" /></span>
                            {{ topicLabel(topic.slug) }}
                        </h2>
                        <ul class="space-y-1">
                            <li v-for="a in topic.articles" :key="a.key">
                                <Link :href="a.href" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-slate-700 hover:bg-slate-50 hover:text-primary-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-primary-300">
                                    <Icon name="document" class="shrink-0 text-slate-400" />
                                    <span>{{ a.title }}</span>
                                </Link>
                            </li>
                        </ul>
                    </section>
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: `Pages/Help/Show.vue`**

```vue
<script setup>
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import HelpArticleBody from '@/Components/Help/HelpArticleBody.vue';
import { readJson, writeJson } from '@/lib/safeStorage';

const props = defineProps({
    article: { type: Object, required: true },
    related: { type: Array, required: true },
    topic: { type: Object, required: true },
    fallback: { type: Boolean, default: false },
});

const { t } = useI18n();
const feedback = ref(readJson('help.feedback', {})[props.article.key] ?? null);

// `window` isn't reachable from the template.
function printPage() {
    window.print();
}

onMounted(() => {
    const entry = { key: props.article.key, title: props.article.title, href: props.article.href };
    writeJson('help.recent', [entry, ...readJson('help.recent', []).filter((r) => r.key !== entry.key)].slice(0, 5));
});

function rate(value) {
    feedback.value = value;
    writeJson('help.feedback', { ...readJson('help.feedback', {}), [props.article.key]: value });
}
</script>

<template>
    <Head :title="article.title" />
    <AuthenticatedLayout>
        <template #header>
            <nav class="flex min-w-0 items-center gap-2 text-sm">
                <Link :href="route('help.index')" class="shrink-0 font-semibold text-primary-700 hover:underline dark:text-primary-300">{{ t('help.center') }}</Link>
                <span class="text-slate-400" aria-hidden="true">/</span>
                <span class="truncate text-slate-600 dark:text-slate-300">{{ t(`help.topic.${topic.slug.replaceAll('-', '_')}`) }}</span>
            </nav>
        </template>

        <article class="mx-auto max-w-3xl">
            <p v-if="fallback" class="mb-4 rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300 print:hidden">{{ t('help.arabic_only') }}</p>

            <header class="mb-6">
                <h1 class="text-2xl font-bold leading-snug text-slate-900 dark:text-slate-100 sm:text-3xl">{{ article.title }}</h1>
                <p class="mt-2 text-lg text-slate-600 dark:text-slate-300">{{ article.summary }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-3 text-sm text-slate-500 dark:text-slate-400">
                    <span>⏱ {{ t('help.minutes', { n: article.minutes }) }}</span>
                    <button type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 hover:bg-slate-100 dark:hover:bg-slate-800 print:hidden" @click="printPage">
                        <Icon name="print" /> {{ t('help.print_page') }}
                    </button>
                </div>
            </header>

            <HelpArticleBody :blocks="article.blocks" />

            <section class="mt-8 flex flex-wrap items-center justify-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 print:hidden">
                <template v-if="feedback === null">
                    <span class="font-semibold text-slate-700 dark:text-slate-200">{{ t('help.helpful') }}</span>
                    <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-xl hover:border-primary-400 dark:border-slate-700" aria-label="👍" @click="rate('up')">👍</button>
                    <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-xl hover:border-primary-400 dark:border-slate-700" aria-label="👎" @click="rate('down')">👎</button>
                </template>
                <span v-else class="font-semibold text-primary-700 dark:text-primary-300">{{ t('help.thanks') }}</span>
            </section>

            <section v-if="related.length" class="mt-8 print:hidden">
                <h2 class="mb-3 text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('help.related') }}</h2>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Link v-for="r in related" :key="r.key" :href="r.href" class="rounded-xl border border-slate-200 bg-white p-4 hover:border-primary-400 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-primary-500">
                        <p class="font-semibold text-slate-900 dark:text-slate-100">{{ r.title }}</p>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ r.summary }}</p>
                    </Link>
                </div>
            </section>
        </article>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 7: `Pages/Auth/LoginHelp.vue` and the login link**

```vue
<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import HelpArticleBody from '@/Components/Help/HelpArticleBody.vue';

// The login article for people who can't sign in yet (no AppShell: Auth/ pages are outside it).
defineProps({ article: { type: Object, required: true } });
const { t } = useI18n();
</script>

<template>
    <Head :title="article.title" />
    <div class="min-h-screen bg-slate-50 px-4 py-8 dark:bg-slate-950">
        <article class="mx-auto max-w-3xl">
            <Link :href="route('login')" class="mb-4 inline-block text-sm font-semibold text-primary-700 hover:underline dark:text-primary-300">← {{ t('help.back_to_login') }}</Link>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 sm:text-3xl">{{ article.title }}</h1>
            <p class="mb-6 mt-2 text-lg text-slate-600 dark:text-slate-300">{{ article.summary }}</p>
            <HelpArticleBody :blocks="article.blocks" />
        </article>
    </div>
</template>
```

In `resources/js/Pages/Auth/Login.vue`, after the closing `</form>` tag add (and `Link` to the `@inertiajs/vue3` import if it isn't imported yet):

```vue
        <p class="mt-6 text-center text-sm">
            <Link :href="route('help.login')" data-help="login-help" class="font-semibold text-primary-700 hover:underline dark:text-primary-300">{{ t('help.login_help') }}</Link>
        </p>
```

- [ ] **Step 8: Build and look at it**

Run: `npm run build`
Expected: build succeeds, no Vue compile warnings for the new files.

Run: `php artisan test --filter=Help` — still PASS.

Then open the app (`php artisan serve --port=2026`, browser at `http://127.0.0.1:2026/help`) as an admin. `resources/help/ar` is still empty, so the page shows only the heading and search box. Temporarily copy the fixture articles in to look at a real article:

```bash
mkdir -p resources/help/ar && cp -r tests/Fixtures/help/ar/* resources/help/ar/
```

Check in light and dark (top-bar toggle) and at 320 px width: step card number, rings/placeholder, callout colours follow the club colour (change it in Settings → General to see it follow), no horizontal scroll. Then remove the copies — **do not commit them**:

```bash
rm -r resources/help/ar
```

- [ ] **Step 9: Commit**

```bash
git add resources/js/Components/Help/HelpCallout.vue resources/js/Components/Help/HelpFigure.vue resources/js/Components/Help/HelpStep.vue resources/js/Components/Help/HelpArticleBody.vue resources/js/Pages/Help/Index.vue resources/js/Pages/Help/Show.vue resources/js/Pages/Auth/LoginHelp.vue resources/js/Pages/Auth/Login.vue resources/js/Components/Icon.vue
git commit -m "feat(help): help center home, article page, step cards with rings and magnifier"
```

---

### Task 6: `?` panel on every page and help results in Ctrl+K

**Files:**
- Create: `resources/js/Components/Help/HelpDrawer.vue`
- Create: `resources/js/Composables/useHelpIndex.js`
- Modify: `resources/js/Layouts/AppShell.vue`, `resources/js/Components/CommandPalette.vue`

**Interfaces:**
- Consumes: `help.panel`, `help.search-index` JSON (Task 3); shared prop `routeName`; `normalize`, `searchItems` from `@/lib/navigation`; `help` icon (Task 5).
- Produces: `useHelpIndex(): { entries: Ref<list<{label, href, icon:'help', haystack}>>, load(): Promise }`; `<HelpDrawer v-model:open>`.

- [ ] **Step 1: `useHelpIndex.js`**

```js
import { ref } from 'vue';
import { normalize } from '@/lib/navigation';

// Help articles for the quick search. Fetched once, the first time the palette
// opens — never on page load. Module-level so every palette shares it.
const entries = ref([]);
let pending = null;

export function useHelpIndex() {
    function load() {
        pending ??= window.axios.get(route('help.search-index'))
            .then(({ data }) => {
                entries.value = data.articles.map((a) => ({
                    label: a.title,
                    href: a.href,
                    icon: 'help',
                    haystack: normalize([a.title, a.summary, ...a.keywords, ...a.headings].join(' ')),
                }));
            })
            .catch(() => { pending = null; });
        return pending;
    }

    return { entries, load };
}
```

- [ ] **Step 2: Wire it into `CommandPalette.vue`**

In `<script setup>`:
- add `import { useHelpIndex } from '@/Composables/useHelpIndex';` and change the navigation import to `import { flattenForSearch, searchItems } from '@/lib/navigation';` (unchanged if already so).
- after `const { t } = useI18n();` add `const { entries: helpEntries, load: loadHelp } = useHelpIndex();`
- replace `const results = computed(() => searchItems(entries.value, query.value));` with:

```js
// Help articles join only once something is typed, capped so pages stay on top.
const results = computed(() => {
    const pages = searchItems(entries.value, query.value);
    if (query.value.trim() === '') return pages;
    const help = searchItems(helpEntries.value, query.value)
        .slice(0, 6)
        .map((item) => ({ ...item, section: t('help.title') }));
    return [...pages, ...help];
});
```

- in the `watch(() => props.open, …)` callback, inside `if (isOpen) {`, add `loadHelp();` as the first line.

- [ ] **Step 3: `HelpDrawer.vue`**

```vue
<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';

// The `?` side panel: the help articles that declare this page in `pages:`.
const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['update:open']);

const { t } = useI18n();
const page = usePage();
const articles = ref([]);
const loading = ref(false);
let loadedFor = null;

function close() {
    emit('update:open', false);
}

watch(() => props.open, async (isOpen) => {
    if (!isOpen) return;
    const name = page.props.routeName ?? '';
    if (loadedFor === name) return;
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('help.panel'), { params: { route: name } });
        articles.value = data.articles;
        loadedFor = name;
    } catch {
        articles.value = [];
    } finally {
        loading.value = false;
    }
});

const onKey = (e) => { if (e.key === 'Escape' && props.open) close(); };
let stopNavigate = null;
onMounted(() => {
    document.addEventListener('keydown', onKey);
    stopNavigate = router.on('navigate', close);
});
onUnmounted(() => {
    document.removeEventListener('keydown', onKey);
    stopNavigate?.();
});
</script>

<template>
    <Teleport to="body">
        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition-opacity duration-150" leave-active-class="transition-opacity duration-150">
            <div v-if="open" class="fixed inset-0 z-50 print:hidden">
                <div class="absolute inset-0 bg-slate-900/40 dark:bg-black/60" @click="close" />
                <aside role="dialog" aria-modal="true" :aria-label="t('help.this_page')" class="absolute inset-y-0 end-0 flex w-full max-w-sm flex-col bg-white shadow-2xl dark:bg-slate-900">
                    <header class="flex items-center gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-50 text-lg text-primary-600 dark:bg-primary-500/15 dark:text-primary-300"><Icon name="help" /></span>
                        <h2 class="flex-1 font-bold text-slate-900 dark:text-slate-100">{{ t('help.this_page') }}</h2>
                        <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" :aria-label="t('help.close')" @click="close"><Icon name="close" /></button>
                    </header>

                    <div class="flex-1 space-y-2 overflow-y-auto p-4">
                        <template v-if="loading">
                            <div v-for="n in 3" :key="n" class="h-16 animate-pulse rounded-xl bg-slate-100 dark:bg-slate-800" />
                        </template>
                        <template v-else-if="articles.length">
                            <Link v-for="a in articles" :key="a.key" :href="a.href" class="block rounded-xl border border-slate-200 p-3 hover:border-primary-400 hover:bg-primary-50/50 dark:border-slate-800 dark:hover:border-primary-500 dark:hover:bg-primary-500/10">
                                <p class="font-semibold text-slate-900 dark:text-slate-100">{{ a.title }}</p>
                                <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ a.summary }}</p>
                                <p class="mt-1 text-xs text-slate-400">⏱ {{ t('help.minutes', { n: a.minutes }) }}</p>
                            </Link>
                        </template>
                        <p v-else class="py-8 text-center text-slate-500 dark:text-slate-400">{{ t('help.no_articles') }}</p>
                    </div>

                    <footer class="border-t border-slate-200 p-4 dark:border-slate-800">
                        <Link :href="route('help.index')" class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 font-semibold text-white hover:bg-primary-700">
                            <Icon name="help" /> {{ t('help.open_center') }}
                        </Link>
                    </footer>
                </aside>
            </div>
        </Transition>
    </Teleport>
</template>
```

- [ ] **Step 4: `?` button in `AppShell.vue`**

In `<script setup>`: `import HelpDrawer from '@/Components/Help/HelpDrawer.vue';` and `const helpOpen = ref(false);` (`ref` is already imported there — confirm).

In the template, next to `<CommandPalette v-model:open="paletteOpen" :sections="sections" />` add:

```vue
        <HelpDrawer v-model:open="helpOpen" />
```

Replace `<ThemeToggle class="ms-auto sm:ms-0 print:hidden" />` with:

```vue
                <button
                    type="button"
                    data-help="help-button"
                    class="ms-auto flex h-9 w-9 items-center justify-center rounded-lg text-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-primary-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-primary-300 sm:ms-0 print:hidden"
                    :title="t('help.this_page')"
                    :aria-label="t('help.this_page')"
                    @click="helpOpen = true"
                >
                    <Icon name="help" />
                </button>

                <ThemeToggle class="print:hidden" />
```

- [ ] **Step 5: Verify**

Run: `npm run build` — succeeds.
Run: `node --test tests/js/*.mjs` — all PASS (navigation tests unaffected).
Manual: with the fixture articles temporarily copied (as in Task 5 Step 8), open `/players` as an admin → `?` opens the panel listing the two players articles; Esc and clicking a link close it; Ctrl+K, type «لاعب» → a "المساعدة" group appears under the page results; empty query shows pages only. Remove the copies afterwards.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Help/HelpDrawer.vue resources/js/Composables/useHelpIndex.js resources/js/Layouts/AppShell.vue resources/js/Components/CommandPalette.vue
git commit -m "feat(help): ? panel on every page and help articles in quick search"
```

---

### Task 7: Demo club seeder

**Files:**
- Create: `database/seeders/HelpDemoSeeder.php`
- Test: `tests/Feature/Help/HelpDemoSeederTest.php`

**Interfaces:**
- Consumes: `DatabaseSeeder`, `RegistrationService::register(Player, ['branch_id','category_id'])`, `MembershipNumber::generateUnique(int $year)`, `WebsiteConfig::singleton()`.
- Produces: constants `HelpDemoSeeder::USERNAME = 'demo'`, `HelpDemoSeeder::PASSWORD = 'demo-help-2026'`; player id 1 is أحمد بن علي (shots.js relies on it); branches «كرة القدم» (Football) and «السباحة» (Swimming) from `BranchSeeder`; demo categories per branch; 3 document types.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Help;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\User;
use App\Models\WebsiteConfig;
use Database\Seeders\HelpDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_the_demo_club_used_by_the_screenshots(): void
    {
        $this->seed(HelpDemoSeeder::class);

        $this->assertSame('نادي المثال', WebsiteConfig::singleton()->club_name['ar']);
        $demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();
        $this->assertTrue($demo->isSuperadmin());
        $this->assertSame('ar', $demo->preferred_lng);

        $ahmed = Player::findOrFail(1);
        $this->assertSame('أحمد', $ahmed->firstname);
        $this->assertSame('بن علي', $ahmed->lastname);
        $this->assertSame(1, $ahmed->registrations()->count());

        $this->assertGreaterThanOrEqual(20, Player::count());
        // Players 2 and 4 also swim (branch-registrations article).
        $this->assertSame(2, Player::has('registrations', '>=', 2)->count());
        $this->assertGreaterThanOrEqual(3, DocumentType::count());
    }

    #[Test]
    public function the_demo_user_can_sign_in(): void
    {
        $this->seed(HelpDemoSeeder::class);

        $this->post('/login', ['username' => HelpDemoSeeder::USERNAME, 'password' => HelpDemoSeeder::PASSWORD])
            ->assertRedirect();
        $this->assertAuthenticated();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=HelpDemoSeederTest`
Expected: FAIL — `Class "Database\Seeders\HelpDemoSeeder" not found`.

- [ ] **Step 3: Write the seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Player\MembershipNumber;
use App\Services\Player\RegistrationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The fake club the Help Center screenshots are taken on (scripts/help-shots.mjs).
 * Runs only on the script's throwaway SQLite file — never on real data.
 * أحمد بن علي is created first so he is player 1: resources/help/shots.js uses /players/1.
 */
class HelpDemoSeeder extends Seeder
{
    public const USERNAME = 'demo';

    public const PASSWORD = 'demo-help-2026';

    /** [firstname, lastname, father, grandfather, gender, birthdate, branch key, category name] */
    private const PLAYERS = [
        ['أحمد', 'بن علي', 'محمد', 'الطاهر', 'Male', '2012-03-14', 'foot', 'أقل من 14 سنة'],
        ['ياسين', 'بوزيد', 'عمر', 'سعيد', 'Male', '2011-07-02', 'foot', 'أقل من 14 سنة'],
        ['أمين', 'قاسمي', 'رشيد', 'الهادي', 'Male', '2010-01-22', 'foot', 'أقل من 17 سنة'],
        ['رياض', 'حمداني', 'كمال', 'صالح', 'Male', '2009-11-05', 'foot', 'أقل من 17 سنة'],
        ['إسلام', 'مرابط', 'نور الدين', 'أحمد', 'Male', '2013-05-19', 'foot', 'أقل من 12 سنة'],
        ['أيوب', 'شريف', 'مراد', 'بلقاسم', 'Male', '2014-02-11', 'foot', 'أقل من 12 سنة'],
        ['عبد الرحمن', 'بلعيد', 'فريد', 'يوسف', 'Male', '2010-09-30', 'foot', 'أقل من 17 سنة'],
        ['زكرياء', 'عمراني', 'سفيان', 'عيسى', 'Male', '2012-12-08', 'foot', 'أقل من 14 سنة'],
        ['وليد', 'سعداوي', 'حسين', 'علي', 'Male', '2005-04-17', 'foot', 'أكابر'],
        ['هشام', 'بوعلام', 'جمال', 'مصطفى', 'Male', '2003-08-25', 'foot', 'أكابر'],
        ['مريم', 'زروقي', 'عبد القادر', 'محمد', 'Female', '2013-06-03', 'swim', 'براعم'],
        ['سارة', 'بن يوسف', 'ناصر', 'الطيب', 'Female', '2012-10-21', 'swim', 'أصاغر'],
        ['إيناس', 'حداد', 'كريم', 'عمر', 'Female', '2011-03-09', 'swim', 'أصاغر'],
        ['آية', 'بلحاج', 'مالك', 'سليمان', 'Female', '2014-01-15', 'swim', 'براعم'],
        ['نور', 'شعبان', 'لطفي', 'إبراهيم', 'Female', '2010-05-28', 'swim', 'أشبال'],
        ['آدم', 'ميهوبي', 'خالد', 'رابح', 'Male', '2013-09-12', 'swim', 'براعم'],
        ['يوسف', 'بوشامة', 'هارون', 'عمار', 'Male', '2011-11-30', 'swim', 'أصاغر'],
        ['لينا', 'قادري', 'عادل', 'الحاج', 'Female', '2012-07-07', 'swim', 'أصاغر'],
        ['محمد', 'عيساوي', 'بشير', 'المختار', 'Male', '2009-02-19', 'swim', 'أشبال'],
        ['ريم', 'بن عمر', 'توفيق', 'قدور', 'Female', '2014-04-24', 'swim', 'براعم'],
        ['أنس', 'لعريبي', 'منير', 'حمزة', 'Male', '2010-12-02', 'foot', 'أقل من 17 سنة'],
        ['شيماء', 'مزياني', 'رضا', 'عبد الله', 'Female', '2011-08-16', 'swim', 'أصاغر'],
    ];

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        WebsiteConfig::singleton()->update([
            'club_name' => ['ar' => 'نادي المثال', 'fr' => 'Club Exemple', 'en' => 'Example Club'],
        ]);

        User::create([
            'name' => 'مستخدم تجريبي',
            'username' => self::USERNAME,
            'email' => 'demo@example.invalid',
            'password' => Hash::make(self::PASSWORD),
            'firstname' => 'كريم',
            'lastname' => 'المدرب',
            'gender' => 'Male',
            'phones' => [],
            'is_user' => true,
            'is_active' => true,
            'approved' => true,
            'privileges' => ['superadmin', 'admin'],
            'preferred_lng' => 'ar',
            'email_verified_at' => now(),
        ]);

        $branches = [
            'foot' => Branch::where('name', 'Football')->firstOrFail(),
            'swim' => Branch::where('name', 'Swimming')->firstOrFail(),
        ];
        $categories = [];
        foreach (self::PLAYERS as $row) {
            [, , , , , , $branchKey, $categoryName] = $row;
            $categories[$branchKey][$categoryName] ??= Category::create([
                'name' => $categoryName,
                'branch_id' => $branches[$branchKey]->id,
            ]);
        }

        foreach ([['PHOTO', 'صورة شمسية'], ['BIRTH', 'شهادة الميلاد'], ['MEDICAL', 'شهادة طبية']] as [$code, $name]) {
            DocumentType::create(['code' => $code, 'name' => $name, 'name_ar' => $name, 'is_required' => true]);
        }

        $registrations = app(RegistrationService::class);
        foreach (self::PLAYERS as $i => [$first, $last, $father, $grandfather, $gender, $birth, $branchKey, $categoryName]) {
            $player = Player::create([
                'membership_id' => MembershipNumber::generateUnique(2024),
                'firstname' => $first,
                'lastname' => $last,
                'father' => $father,
                'grandfather' => $grandfather,
                'gender' => $gender,
                'birthdate' => $birth,
                'join_year' => 2024,
                'outstanding_debt' => 0,
            ]);
            $registrations->register($player, [
                'branch_id' => $branches[$branchKey]->id,
                'category_id' => $categories[$branchKey][$categoryName]->id,
            ]);
            // Two players also swim, so the branch-registrations article has a real example.
            if (in_array($i, [1, 3], true)) {
                $registrations->register($player, [
                    'branch_id' => $branches['swim']->id,
                    'category_id' => $categories['swim']['أشبال']->id,
                ]);
            }
        }
    }
}
```

If `DocumentType::create` fails on a NOT NULL column (e.g. `validity`), open `database/migrations/*document_types*` and add that column with the default the migration documents. If `Category::create` or `register()` rejects the data, mirror what `tests/Support/RegistrationFixtures.php` passes.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=HelpDemoSeederTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoSeederTest.php
git add database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoSeederTest.php
git commit -m "feat(help): demo club seeder for help screenshots"
```

---

### Task 8: Screenshot script

**Files:**
- Create: `scripts/help-shots.mjs`
- Create: `resources/help/shots.js`
- Modify: `package.json` (devDependencies `playwright`, `sharp`; script `help:shots`), `.gitignore`

**Interfaces:**
- Consumes: `HelpDemoSeeder::USERNAME/PASSWORD` (Task 7); `data-help` attributes (Tasks 5, 6, 9, 10).
- Produces: `public/help/shots/ar/{light,dark}/<name>.webp`, `public/help/shots/ar/<name>.json` in the format Task 2 reads.
- `shots.js` entry shape: `{ name: string, url: string, guest?: boolean, targets: string[], before?: async (page) => void }`.

- [ ] **Step 1: Install tools (dev machine only)**

```bash
npm install --save-dev playwright sharp
npx playwright install chromium
```

Add to `package.json` `scripts`:

```json
        "help:shots": "node scripts/help-shots.mjs",
```

Append to `.gitignore`:

```
/storage/help-demo.sqlite
/storage/framework/help-demo-config.php
```

- [ ] **Step 2: `resources/help/shots.js` (starter list — Tasks 9–10 extend it)**

```js
// Every screenshot the help articles use. `targets` are data-help names whose
// boxes are saved for rings and magnifiers; `before` runs after the page loads
// (open a menu, type a search). Player 1 is أحمد بن علي (HelpDemoSeeder).
export default [
    { name: 'login', url: '/login', guest: true, targets: ['login-username', 'login-password', 'login-submit', 'login-help'] },
    { name: 'help-center', url: '/help', targets: ['help-search', 'help-button'] },
];
```

- [ ] **Step 3: `scripts/help-shots.mjs`**

```js
// Regenerates the Help Center screenshots on a throwaway demo club.
//
//   npm run build && npm run help:shots          all shots
//   npm run help:shots -- players-index          one shot
//
// Dev machine only (needs `npx playwright install chromium` once). Never touches
// the real database: it migrates + seeds storage/help-demo.sqlite and serves it
// on its own port. Fails loudly when a data-help target is missing.
import { execFileSync, spawn } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { chromium } from 'playwright';
import sharp from 'sharp';
import shots from '../resources/help/shots.js';

const root = resolve(import.meta.dirname, '..');
const locale = 'ar';
const port = 8765;
const base = `http://127.0.0.1:${port}`;
const viewport = { width: 1280, height: 800 };
const db = join(root, 'storage', 'help-demo.sqlite');
const out = join(root, 'public', 'help', 'shots', locale);
const only = process.argv[2] ?? null;

// APP_CONFIG_CACHE points at a file that doesn't exist, so a cached config
// can't override DB_DATABASE (see the db-swap gotchas).
const env = {
    ...process.env,
    APP_ENV: 'local',
    APP_URL: base,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: db,
    APP_CONFIG_CACHE: join(root, 'storage', 'framework', 'help-demo-config.php'),
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'file',
};

const list = only ? shots.filter((s) => s.name === only) : shots;
if (list.length === 0) {
    console.error(`No shot named "${only}" in resources/help/shots.js`);
    process.exit(1);
}
if (existsSync(join(root, 'public', 'hot'))) {
    console.warn('public/hot exists: pages will load assets from the Vite dev server, keep it running.');
}

rmSync(db, { force: true });
writeFileSync(db, '');
execFileSync('php', ['artisan', 'migrate:fresh', '--force', '--seed', '--seeder=Database\\Seeders\\HelpDemoSeeder'], { cwd: root, env, stdio: 'inherit' });

const server = spawn('php', ['artisan', 'serve', '--no-reload', '--host=127.0.0.1', `--port=${port}`], { cwd: root, env, stdio: 'ignore' });
const stopServer = () => {
    if (process.platform === 'win32') {
        try { execFileSync('taskkill', ['/pid', String(server.pid), '/T', '/F'], { stdio: 'ignore' }); } catch { /* already gone */ }
    } else {
        server.kill();
    }
};

async function waitForServer() {
    for (let i = 0; i < 60; i++) {
        try {
            const res = await fetch(`${base}/login`);
            if (res.ok) return;
        } catch { /* not up yet */ }
        await new Promise((r) => setTimeout(r, 500));
    }
    throw new Error('php artisan serve did not start on ' + base);
}

const pct = (n) => Math.round(n * 100) / 100;

async function capture(context, shot, theme) {
    const page = await context.newPage();
    await page.goto(base + shot.url, { waitUntil: 'networkidle' });
    if (shot.before) await shot.before(page);
    await page.waitForTimeout(400);

    const targets = {};
    for (const name of shot.targets) {
        const el = page.locator(`[data-help="${name}"]`).first();
        if ((await el.count()) === 0) throw new Error(`Shot "${shot.name}": no element with data-help="${name}" on ${shot.url}`);
        const box = await el.boundingBox();
        if (!box) throw new Error(`Shot "${shot.name}": data-help="${name}" is hidden on ${shot.url}`);
        targets[name] = {
            x: pct((box.x / viewport.width) * 100),
            y: pct((box.y / viewport.height) * 100),
            w: pct((box.width / viewport.width) * 100),
            h: pct((box.height / viewport.height) * 100),
        };
    }

    const png = await page.screenshot({ animations: 'disabled', caret: 'hide' });
    mkdirSync(join(out, theme), { recursive: true });
    await sharp(png).webp({ quality: 82 }).toFile(join(out, theme, `${shot.name}.webp`));
    if (theme === 'light') {
        writeFileSync(join(out, `${shot.name}.json`), JSON.stringify({ ...viewport, targets }, null, 2) + '\n');
    }
    await page.close();
}

let browser;
try {
    await waitForServer();
    browser = await chromium.launch();

    const loginContext = await browser.newContext({ viewport, locale });
    const loginPage = await loginContext.newPage();
    await loginPage.goto(`${base}/login`);
    await loginPage.fill('#username', 'demo');
    await loginPage.fill('#password', 'demo-help-2026');
    // Enter submits the form (PrimaryButton may not carry type="submit").
    await Promise.all([
        loginPage.waitForURL((url) => !url.pathname.startsWith('/login')),
        loginPage.press('#password', 'Enter'),
    ]);
    await loginPage.goto(`${base}/lang/${locale}`);
    const signedIn = await loginContext.storageState();
    await loginContext.close();

    for (const theme of ['light', 'dark']) {
        for (const shot of list) {
            const context = await browser.newContext({
                viewport,
                locale,
                colorScheme: theme,
                storageState: shot.guest ? undefined : signedIn,
            });
            await context.addInitScript((t) => localStorage.setItem('theme', t), theme);
            await capture(context, shot, theme);
            await context.close();
            console.log(`✓ ${theme} ${shot.name}`);
        }
    }
} catch (error) {
    console.error(`\n✗ ${error.message}`);
    process.exitCode = 1;
} finally {
    await browser?.close();
    stopServer();
    rmSync(db, { force: true });
}
```

The login page shot needs `data-help` on the login fields: in `resources/js/Pages/Auth/Login.vue` add `data-help="login-username"` to the username `<TextInput>`, `data-help="login-password"` to the password `<TextInput>`, and `data-help="login-submit"` to the `<PrimaryButton>` (attributes fall through to the root element of these components — confirm in the rendered HTML).

- [ ] **Step 4: Run it**

Run: `npm run build && npm run help:shots`
Expected: `✓ light login`, `✓ light help-center`, `✓ dark login`, `✓ dark help-center`; files `public/help/shots/ar/{light,dark}/{login,help-center}.webp` and `public/help/shots/ar/{login,help-center}.json` exist. Open two WebPs: Arabic RTL, demo club name, dark one dark. Check `git status` shows no `storage/help-demo.sqlite`.

Break-test: temporarily add `'nope'` to the `login` targets, run `npm run help:shots -- login`, expect `✗ Shot "login": no element with data-help="nope" on /login` and exit code 1; revert.

- [ ] **Step 5: Commit**

```bash
git add scripts/help-shots.mjs resources/help/shots.js package.json package-lock.json .gitignore resources/js/Pages/Auth/Login.vue public/help/shots/ar
git commit -m "feat(help): screenshot script on a throwaway demo club"
```

---

### Task 9: Getting-started articles

**Files:**
- Create: `resources/help/ar/getting-started/login.md`, `find-your-way.md`, `profile-password.md`
- Modify: `resources/help/shots.js`; `data-help` attributes in `resources/js/Layouts/AppShell.vue`, `resources/js/Pages/Profile/Edit.vue` / its `Partials/*` (password form)
- Generated: `public/help/shots/ar/...`

**Writing rules (apply to every article in Tasks 9–10):**
- Simple Modern Standard Arabic, singular imperative («اضغط»، «اكتب»، «اختر»). Short sentences (≤ 15 words). One action per step.
- Name buttons exactly as the app shows them, in «…». Check the label in `resources/js/i18n/ar.json` (grep the key the Vue file uses) — never guess.
- Every article: front matter with `title` starting «كيف…», one-line `summary`, `pages`, `keywords` (3–6 words people would type), `related`; one `:::example` near the top using the demo club data (أحمد بن علي, نادي المثال, كرة القدم/السباحة); 3–7 steps, each with a figure where something must be clicked; at most one tip and one warning.
- `ring=` every element the step asks the user to click; `zoom=` the one most likely to be missed (small icon buttons).
- After writing, read the article aloud once as someone who has never used a computer: if a step needs knowledge not given, add it.

**Article outlines:**

`login.md` — title «كيف أدخل إلى التطبيق»; pages `[login]`; steps: open the app/desktop icon (no figure) → type username (`login` ring=login-username) → type password, eye button shows it (`login` ring=login-password) → press «تسجيل الدخول» (`login` ring=login-submit zoom=login-submit); warning: password forgotten → ask the club administrator (no self-service reset exists — confirm in `routes/auth.php`); example: username `demo`.

`find-your-way.md` — title «كيف أتنقل داخل التطبيق»; pages `[dashboard]`; steps: the menu on the right (`app-dashboard` ring=sidebar) → open a section (`app-dashboard` ring=nav-members) → quick search Ctrl+K (`app-palette` ring=palette-input) → the `?` button for help on any page (`app-dashboard` ring=help-button zoom=help-button) → dark/light mode (`app-dashboard` ring=theme-toggle zoom=theme-toggle) → change language (`app-dashboard` ring=language-switch); tip: Ctrl+K works with an Arabic keyboard too.

`profile-password.md` — title «كيف أغيّر كلمة المرور»; pages `[profile.edit]`; steps: open your name at the bottom of the menu (`app-dashboard` ring=sidebar-profile) → find «تغيير كلمة المرور» section (`profile` ring=password-form) → current password, new password twice (`profile` ring=password-current,password-new,password-confirm) → save (`profile` ring=password-save zoom=password-save); warning: choose a password others can't guess.

**data-help attributes to add** (attribute only, no behaviour change):
- `AppShell.vue`: `data-help="sidebar"` on the `<aside>`; `data-help="nav-members"` on the members `SidebarSection` (pass it through: add `:data-help="section.key === 'members' ? 'nav-members' : null"` on the wide-mode `<SidebarSection>`); `data-help="theme-toggle"` on `<ThemeToggle>`; `data-help="language-switch"` on the locale button group `<div class="flex flex-1 items-center gap-1 rounded-xl bg-slate-100 p-1 …">`; `data-help="sidebar-profile"` on the footer profile `<Link>`.
- `CommandPalette.vue`: `data-help="palette-input"` on the `<input>`.
- Profile password form (find it: `grep -rn "current_password" resources/js/Pages/Profile`): `password-form` on the section/form root, `password-current`, `password-new`, `password-confirm` on the three inputs, `password-save` on the submit button.

**Shots to add to `resources/help/shots.js`:**

```js
    { name: 'app-dashboard', url: '/dashboard', targets: ['sidebar', 'nav-members', 'help-button', 'theme-toggle', 'language-switch', 'sidebar-profile'] },
    {
        name: 'app-palette',
        url: '/dashboard',
        targets: ['palette-input'],
        before: async (page) => {
            await page.keyboard.press('Control+k');
            await page.locator('[data-help="palette-input"]').fill('لاعب');
        },
    },
    { name: 'profile', url: '/profile', targets: ['password-form', 'password-current', 'password-new', 'password-confirm', 'password-save'] },
```

(If the profile route path is not `/profile`, use the path of `route('profile.edit')` from `php artisan route:list --name=profile.edit`.)

- [ ] **Step 1:** Add the data-help attributes listed above.
- [ ] **Step 2:** Add the shots; run `npm run build && npm run help:shots`; expect every shot ✓.
- [ ] **Step 3:** Write the three articles following the outlines and writing rules. Full example of the format (use as the model for every article):

```md
---
title: كيف أدخل إلى التطبيق
summary: ادخل إلى حسابك باسم المستخدم وكلمة المرور التي أعطاك إياها مسؤول النادي.
pages: [login]
keywords: [دخول, تسجيل الدخول, كلمة المرور, اسم المستخدم]
related: [getting-started/find-your-way, getting-started/profile-password]
---

ستحتاج إلى **اسم المستخدم** و**كلمة المرور**. يعطيك إياهما مسؤول النادي.

:::example
في هذا الدليل نستعمل اسم المستخدم **demo**. اكتب أنت اسم المستخدم الخاص بك.
:::

:::step افتح التطبيق
اضغط مرتين على أيقونة التطبيق في سطح المكتب، أو افتح رابط النادي في المتصفح.
:::

:::step اكتب اسم المستخدم
اضغط داخل الخانة الأولى، ثم اكتب اسم المستخدم.
::figure login ring=login-username
:::

:::step اكتب كلمة المرور
اضغط داخل الخانة الثانية، ثم اكتب كلمة المرور. لرؤية ما كتبته اضغط على رمز العين 👁.
::figure login ring=login-password
:::

:::step اضغط «تسجيل الدخول»
ستظهر لك لوحة القيادة. لقد دخلت بنجاح!
::figure login ring=login-submit zoom=login-submit
:::

:::warning
نسيت كلمة المرور؟ اطلب من مسؤول النادي أن يضع لك كلمة مرور جديدة.
:::
```

Check the real button text: `grep -n '"log_in"' resources/js/i18n/ar.json` and use exactly that label in «…».

- [ ] **Step 4:** Run `php artisan help:check`. Expected: only problems about articles not written yet (none for these three). Open `/help`, each article, light + dark, 320 px; open `/help/login` signed out.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/getting-started resources/help/shots.js public/help/shots/ar resources/js/Layouts/AppShell.vue resources/js/Components/CommandPalette.vue resources/js/Pages/Profile
git commit -m "docs(help): getting-started articles with screenshots"
```

---

### Task 10: Players articles

**Files:**
- Create: `resources/help/ar/players/add-player.md`, `find-player.md`, `player-page.md`, `edit-player.md`, `documents.md`, `branch-registrations.md`
- Modify: `resources/help/shots.js`; `data-help` attributes in `resources/js/Pages/Players/Index.vue`, `Create.vue` (and its `Partials/*` form), `Show.vue`, `Edit.vue`, `JoinDates.vue`, `resources/js/Components/PlayerDocumentsCard.vue`
- Generated: `public/help/shots/ar/...`

Same writing rules as Task 9. Each step names the real label (grep `ar.json` for the key the Vue file uses).

**Article outlines** (pages → shots → steps):

1. `add-player.md` «كيف أضيف لاعباً جديداً» — pages `[players.index, players.create]`; example: أحمد بن علي، مولود 14/03/2012، كرة القدم، صنف «أقل من 14 سنة». Steps: open «اللاعبون» (`players-index` ring=nav-players) → press «لاعب جديد» (`players-index` ring=add-player zoom=add-player) → fill name fields: first name, family name, father, grandfather (`players-create` ring=player-names) → birth date and gender (`players-create` ring=player-birthdate,player-gender) → branch + category card (`players-create` ring=player-branch-card) → save (`players-create-bottom` ring=player-save zoom=player-save). Warning: birth date decides the category and the student default under 18.
2. `find-player.md` «كيف أبحث عن لاعب» — pages `[players.index]`; example: search «أحمد». Steps: search box (`players-search` ring=players-search) → filters (`players-index` ring=players-filters) → open the player (`players-search` ring=player-row-first). Tip: search ignores hamza/tashkeel (أحمد = احمد).
3. `player-page.md` «كيف أقرأ صفحة اللاعب» — pages `[players.show]`; example: أحمد بن علي. Steps: header with photo, name, membership ID (`player-show` ring=player-header) → tabs (`player-show` ring=player-tabs) → branch registration cards (`player-show` ring=player-registrations) → documents card (`player-show` ring=player-documents) → actions edit/print (`player-show` ring=player-actions zoom=player-edit).
4. `edit-player.md` «كيف أعدّل بيانات لاعب» — pages `[players.edit, players.show]`. Steps: open the player → «تعديل» (`player-show` ring=player-edit zoom=player-edit) → change a field (`player-edit` ring=player-names) → save (`player-edit-bottom` ring=player-save). Warning: name changes update everywhere (board member names follow the player).
5. `documents.md` «كيف أسجّل وثائق اللاعب» — pages `[players.show]`; example: أحمد brought شهادة الميلاد and a medical certificate. Steps: documents card (`player-show` ring=player-documents) → mark a document received (`player-documents` ring=document-toggle zoom=document-toggle) → copies received (`player-documents` ring=document-copies). Tip: the photo document can be marked received without uploading a picture.
6. `branch-registrations.md` «كيف أسجّل لاعباً في فرع ثانٍ» — pages `[players.show, players.edit, players.join-dates.index]`; example: ياسين بوزيد (player 2) plays football and also swims. Steps: open the player's edit page → add a branch card (`player-edit-branches` ring=player-add-branch zoom=player-add-branch) → choose branch, category, join date (`player-edit-branches` ring=player-branch-card) → save → see both cards on the player page (`player-show-2` ring=player-registrations) → join dates page for corrections (`join-dates` ring=join-dates-table).

**data-help attributes** — add on the element named; find each with the grep given:
- `Players/Index.vue`: `add-player` on the `<IconButton :href="route('players.create')" …>` (line ~378); `players-search` on the search `<SearchInput>`/input (`grep -n "SearchInput\|search" resources/js/Pages/Players/Index.vue`); `players-filters` on the filters container; `player-row-first` on the first row link (`:data-help="index === 0 ? 'player-row-first' : null"` on the `v-for` `<Link>`; add `index` to the `v-for`).
- `AppShell` sidebar link for players: in `resources/js/Components/Sidebar/SidebarSection.vue` add `:data-help="item.href === '/players' ? 'nav-players' : null"` on each item link.
- `Players/Create.vue` / `Edit.vue` (the form is likely a shared partial — `grep -rn "firstname" resources/js/Pages/Players`): `player-names` on the wrapper around first/last/father/grandfather inputs; `player-birthdate`, `player-gender` on those inputs; `player-branch-card` on the first branch registration card; `player-add-branch` on the add-branch button; `player-edit-branches` is not an attribute — it's a shot name; `player-save` on the submit button.
- `Players/Show.vue`: `player-header`, `player-tabs`, `player-registrations`, `player-actions` (the header action group), `player-edit` (edit button).
- `PlayerDocumentsCard.vue`: `player-documents` on the card root; `document-toggle` on the first document's received control (`:data-help="i === 0 ? 'document-toggle' : null"`); `document-copies` on the first copies input.
- `Players/JoinDates.vue`: `join-dates-table` on the table.

**Shots to add:**

```js
    { name: 'players-index', url: '/players', targets: ['nav-players', 'add-player', 'players-filters'] },
    {
        name: 'players-search',
        url: '/players',
        targets: ['players-search', 'player-row-first'],
        before: async (page) => {
            await page.locator('[data-help="players-search"] input, input[data-help="players-search"]').first().fill('أحمد');
            await page.waitForLoadState('networkidle');
        },
    },
    { name: 'players-create', url: '/players/create', targets: ['player-names', 'player-birthdate', 'player-gender', 'player-branch-card'] },
    {
        name: 'players-create-bottom',
        url: '/players/create',
        targets: ['player-save'],
        before: async (page) => { await page.locator('[data-help="player-save"]').scrollIntoViewIfNeeded(); },
    },
    { name: 'player-show', url: '/players/1', targets: ['player-header', 'player-tabs', 'player-registrations', 'player-documents', 'player-actions', 'player-edit'] },
    { name: 'player-show-2', url: '/players/2', targets: ['player-registrations'] },
    {
        name: 'player-documents',
        url: '/players/1',
        targets: ['player-documents', 'document-toggle', 'document-copies'],
        before: async (page) => { await page.locator('[data-help="player-documents"]').scrollIntoViewIfNeeded(); },
    },
    { name: 'player-edit', url: '/players/1/edit', targets: ['player-names'] },
    {
        name: 'player-edit-bottom',
        url: '/players/1/edit',
        targets: ['player-save'],
        before: async (page) => { await page.locator('[data-help="player-save"]').scrollIntoViewIfNeeded(); },
    },
    {
        name: 'player-edit-branches',
        url: '/players/2/edit',
        targets: ['player-add-branch', 'player-branch-card'],
        before: async (page) => { await page.locator('[data-help="player-add-branch"]').scrollIntoViewIfNeeded(); },
    },
    { name: 'join-dates', url: '/players/join-dates', targets: ['join-dates-table'] },
```

Boxes are measured after `before` runs (scrolled position), so rings land correctly on scrolled shots. If a page's tab hides the documents card, click the tab inside `before`.

- [ ] **Step 1:** Add the data-help attributes; `npm run build`.
- [ ] **Step 2:** Add the shots; run `npm run help:shots`; fix any missing-target failure at its source; expect all ✓. Open every WebP in both themes.
- [ ] **Step 3:** Write the six articles following the outlines and writing rules (model: `login.md` in Task 9).
- [ ] **Step 4:** `php artisan help:check` → `Help content OK — 9 article(s).` (exit 0). `php artisan test --filter=Help` → PASS. Browse each article in light/dark and at 320 px; open `?` on `/players`, `/players/1`, `/players/1/edit`, `/players/join-dates` → the declared articles appear.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/players resources/help/shots.js public/help/shots/ar resources/js/Pages/Players resources/js/Components/PlayerDocumentsCard.vue resources/js/Components/Sidebar/SidebarSection.vue
git commit -m "docs(help): players articles with screenshots"
```

---

### Task 11: Authoring guide and final verification

**Files:**
- Create: `resources/help/README.md`

- [ ] **Step 1: Write the authoring guide**

```md
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

## Rules

- Simple Arabic, singular imperative, ≤ 15 words a sentence, one action per step.
- Button names exactly as the app shows them, in «…» (check `ar.json`).
- 3–7 steps; at most one tip and one warning.
- `ring=` what the user must click; `zoom=` the small thing they'd miss.

## Screenshots

1. Put `data-help="<name>"` on the element in the Vue page (attribute only).
2. Add or extend the shot in `resources/help/shots.js`.
3. `npm run build && npm run help:shots` (or `npm run help:shots -- <shot>`).
4. Commit `public/help/shots/ar/*`.

After any UI change, rerun `npm run help:shots`; it fails naming the shot and
target if a marked element disappeared.

## Check before committing

    php artisan help:check
    php artisan test --filter=Help
```

- [ ] **Step 2: Full verification**

Run each; all must pass:

```bash
php artisan help:check
php artisan test --filter=Help
node --test tests/js/*.mjs
npm run i18n:check
npm run build
vendor/bin/pint --test app tests database routes
```

Then run the whole suite once: `php artisan test` — expected PASS (no regressions from the shared `routeName` prop or AppShell changes).

Manual pass (light + dark, wide + 320 px): `/help` start-here banner and topic tiles; each of the 9 articles; `?` on dashboard (getting-started/find-your-way listed), `/players`, `/players/1`; Ctrl+K «كلمة المرور» finds the profile article; signed-out `/login` → «تحتاج مساعدة في الدخول؟» → login article → back link; a user whose role lacks Players sees only Getting started; switch to French → Help chrome in French with the "Arabic only" note.

- [ ] **Step 3: Commit**

```bash
git add resources/help/README.md
git commit -m "docs(help): authoring guide for help articles"
```
