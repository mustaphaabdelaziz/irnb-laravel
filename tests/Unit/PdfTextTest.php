<?php

namespace Tests\Unit;

use App\Support\PdfText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PdfTextTest extends TestCase
{
    #[Test]
    public function a_latin_line_is_wrapped_and_an_arabic_line_is_not(): void
    {
        $this->assertSame('<bdo dir="ltr">12 Test Sport Shop</bdo>', (string) PdfText::ltrIfLatin('12 Test Sport Shop'));
        $this->assertSame('متجر الرياضة', (string) PdfText::ltrIfLatin('متجر الرياضة'));
        $this->assertSame('متجر Test', (string) PdfText::ltrIfLatin('متجر Test'));
    }

    #[Test]
    public function lines_are_joined_outside_the_bdo(): void
    {
        $this->assertSame(
            '<bdo dir="ltr">Line one</bdo><br /><bdo dir="ltr">Line two</bdo><br />سطر',
            (string) PdfText::ltrIfLatin("Line one\r\nLine two\nسطر")
        );
    }

    #[Test]
    public function null_and_empty_are_safe(): void
    {
        $this->assertSame('<bdo dir="ltr"></bdo>', (string) PdfText::ltrIfLatin(null));
        $this->assertSame('<bdo dir="ltr"></bdo>', (string) PdfText::ltrIfLatin(''));
    }

    #[Test]
    public function html_is_escaped(): void
    {
        $this->assertSame('<bdo dir="ltr">&lt;b&gt;A &amp; B&lt;/b&gt;</bdo>', (string) PdfText::ltrIfLatin('<b>A & B</b>'));
    }
}
