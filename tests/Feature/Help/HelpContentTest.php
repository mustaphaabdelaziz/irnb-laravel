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
}
