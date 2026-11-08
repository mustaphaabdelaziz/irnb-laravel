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
                throw new HelpParseException("{$where}: front matter line ".($i + 2).' is not `key: value`');
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

        // CommonMark drops a whole line that opens with a raw HTML tag, so tags go first and the text stays.
        $text = preg_replace('#</?[a-zA-Z][^>]*>#', '', $text);

        return trim((string) Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }
}
