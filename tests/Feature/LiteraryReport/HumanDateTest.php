<?php

namespace Tests\Feature\LiteraryReport;

use App\Support\HumanDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HumanDateTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'ar one day' => ['2025-03-28', null, 'ar', '28 مارس'],
            'ar same day end' => ['2025-03-28', '2025-03-28', 'ar', '28 مارس'],
            'ar same month' => ['2025-10-09', '2025-10-10', 'ar', 'من 09 إلى 10 أكتوبر'],
            'ar cross month' => ['2025-03-28', '2025-04-04', 'ar', 'من 28 مارس إلى 04 أفريل'],
            'ar cross year' => ['2025-12-28', '2026-01-04', 'ar', 'من 28 ديسمبر 2025 إلى 04 جانفي 2026'],
            'ar july' => ['2025-07-04', null, 'ar', '04 جويلية'],
            'fr one day' => ['2025-03-28', null, 'fr', '28 mars'],
            'fr same month' => ['2025-10-09', '2025-10-10', 'fr', 'du 09 au 10 octobre'],
            'fr cross month' => ['2025-03-28', '2025-04-04', 'fr', 'du 28 mars au 04 avril'],
            'fr cross year' => ['2025-12-28', '2026-01-04', 'fr', 'du 28 décembre 2025 au 04 janvier 2026'],
            'en same month' => ['2025-10-09', '2025-10-10', 'en', '9–10 October'],
            'en cross month' => ['2025-03-28', '2025-04-04', 'en', '28 March – 4 April'],
            'unknown locale falls back to en' => ['2025-03-28', null, 'de', '28 March'],
            'end before start prints the start' => ['2025-03-28', '2025-03-01', 'ar', '28 مارس'],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_writes_dates_in_words(string $start, ?string $end, string $locale, string $expected): void
    {
        $this->assertSame($expected, HumanDate::range(CarbonImmutable::parse($start), $end ? CarbonImmutable::parse($end) : null, $locale));
    }
}
