<?php

namespace Tests\Unit\Services;

use App\Services\Pdf\PdfService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PdfServiceTest extends TestCase
{
    /**
     * A month sheet with ~300 players and ~20 sessions renders about 1,038 KB
     * of HTML. mPDF's WriteHTML runs PCRE over the whole string and throws
     * MpdfException past pcre.backtrack_limit (default 1,000,000 bytes), so
     * the request 500s. Build the smallest synthetic table over that limit
     * and check render() still returns a real PDF.
     */
    #[Test]
    public function it_renders_html_larger_than_the_default_pcre_backtrack_limit(): void
    {
        // The smallest size over the default 1,000,000-byte limit, shaped
        // to render quickly: plain divs, not a table (mPDF's table column
        // width auto-fit is expensive across many cells — that's what makes
        // the real ~300 players x ~20 sessions month sheet slow), and each
        // holding one moderately long unbroken "word" rather than many
        // space-separated words (mPDF's line breaker has to measure every
        // word, and a huge count of them is much slower than a few longer
        // ones) or one giant word (mPDF's line breaker chokes trying to
        // find a break point in it).
        $word = str_repeat('a', 1_260);
        $html = '<html><body>'.str_repeat("<div>{$word}</div>", 800).'</body></html>';
        $this->assertGreaterThan(1_000_000, strlen($html));

        $pdf = app(PdfService::class)->render($html);

        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
