<?php

namespace Tests\Unit;

use App\Support\AmountInWords;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AmountInWordsTest extends TestCase
{
    #[Test]
    public function french_and_english_spell_dinars_and_centimes(): void
    {
        $this->assertSame('quarante-cinq mille quatre cent soixante-dix dinars', AmountInWords::format(45470, 'fr'));
        $this->assertSame('one thousand two hundred fifty dinars and fifty centimes', AmountInWords::format(1250.5, 'en'));
    }

    #[Test]
    public function arabic_is_spelled_in_arabic(): void
    {
        $words = AmountInWords::format(45470, 'ar');

        $this->assertStringContainsString('ألف', $words);
        $this->assertStringContainsString('دينار', $words);
    }
}
