<?php

namespace Tests\Unit\Help;

use App\Services\Help\HelpArticleParser;
use App\Services\Help\HelpParseException;
use PHPUnit\Framework\Attributes\DataProvider;
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
    #[DataProvider('brokenArticles')]
    public function broken_articles_throw_with_the_article_key(string $raw): void
    {
        $this->expectException(HelpParseException::class);
        $this->expectExceptionMessage('players/add-player');

        $this->parse($raw);
    }
}
