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
