<?php

namespace Tests\Feature\Help;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The committed articles and screenshots (resources/help + public/help-shots), through the real container binding. */
class HelpContentTest extends TestCase
{
    #[Test]
    public function the_real_help_content_passes_help_check(): void
    {
        $this->artisan('help:check')->assertSuccessful();
    }

    /** French and English, once written, pass the same checks against their own screenshots. */
    #[Test]
    public function every_translation_passes_help_check(): void
    {
        foreach (['fr', 'en'] as $locale) {
            if (! is_dir(resource_path("help/{$locale}"))) {
                continue;
            }
            $this->artisan('help:check', ['--locale' => $locale])->assertSuccessful();
        }

        $this->addToAssertionCount(1);
    }

    /** A translation is all or nothing: the Help Center switches a whole language at once. */
    #[Test]
    public function a_translation_has_every_arabic_article(): void
    {
        $keys = fn (string $locale) => collect(glob(resource_path("help/{$locale}/*/*.md")))
            ->map(fn (string $f) => basename(dirname($f)).'/'.basename($f, '.md'))->sort()->values()->all();

        foreach (['fr', 'en'] as $locale) {
            if (is_dir(resource_path("help/{$locale}"))) {
                $this->assertSame($keys('ar'), $keys($locale), "{$locale} articles differ from ar");
            }
        }

        $this->addToAssertionCount(1);
    }
}
