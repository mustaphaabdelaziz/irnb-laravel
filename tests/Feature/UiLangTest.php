<?php

namespace Tests\Feature;

use App\Support\UiLang;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UiLangTest extends TestCase
{
    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        return json_decode(file_get_contents(resource_path("js/i18n/{$locale}.json")), true);
    }

    #[Test]
    public function it_reads_the_label_in_the_current_locale(): void
    {
        app()->setLocale('fr');

        $this->assertSame($this->catalog('fr')['donation'], UiLang::get('donation'));
    }

    #[Test]
    public function an_explicit_locale_wins(): void
    {
        app()->setLocale('en');

        $this->assertSame($this->catalog('ar')['donation'], UiLang::get('donation', null, 'ar'));
    }

    #[Test]
    public function a_missing_key_returns_the_default_then_the_key(): void
    {
        $this->assertSame('Fallback', UiLang::get('no_such_key_zz', 'Fallback'));
        $this->assertSame('no_such_key_zz', UiLang::get('no_such_key_zz'));
    }

    #[Test]
    public function an_unsupported_locale_reads_english(): void
    {
        $this->assertSame($this->catalog('en')['donation'], UiLang::get('donation', null, 'de'));
    }
}
