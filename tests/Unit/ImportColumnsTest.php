<?php

namespace Tests\Unit;

use App\Support\Import\ImportColumns;
use App\Support\UiLang;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportColumnsTest extends TestCase
{
    private function columns(): ImportColumns
    {
        return new ImportColumns([
            ['key' => 'transaction_date', 'label' => 'col.date', 'legacy' => ['Date (YYYY-MM-DD)']],
            ['key' => 'title', 'label' => 'col.title', 'legacy' => ['Title']],
            ['key' => 'amount', 'label' => 'col.amount', 'legacy' => ['Amount']],
        ]);
    }

    #[Test]
    public function it_finds_headers_in_any_of_the_three_languages_in_any_order(): void
    {
        foreach (['ar', 'fr', 'en'] as $locale) {
            $headers = $this->columns()->headers($locale);
            [$row, $map] = $this->columns()->locate([[$headers[2], $headers[0], $headers[1]]]);

            $this->assertSame(0, $row, $locale);
            $this->assertSame(['transaction_date' => 1, 'title' => 2, 'amount' => 0], $map, $locale);
        }
    }

    #[Test]
    public function it_skips_the_export_title_and_spacer_rows(): void
    {
        [$row, $map] = $this->columns()->locate([['Transactions'], [], ['Date', 'Title', 'Amount'], ['2026-01-01', 'x', '5']]);

        $this->assertSame(2, $row);
        $this->assertSame(['transaction_date' => 0, 'title' => 1, 'amount' => 2], $map);
    }

    #[Test]
    public function legacy_headers_and_hints_in_parentheses_match(): void
    {
        [, $map] = $this->columns()->locate([['Date (YYYY-MM-DD)', 'Amount (DZD)', 'Title']]);

        $this->assertSame(['transaction_date' => 0, 'title' => 2, 'amount' => 1], $map);
    }

    #[Test]
    public function without_a_recognised_header_it_falls_back_to_position(): void
    {
        [$row, $map] = $this->columns()->locate([['h', 'h', 'h'], ['2026-01-01', 'x', '5']]);

        $this->assertSame(0, $row);
        $this->assertSame(['transaction_date' => 0, 'title' => 1, 'amount' => 2], $map);
    }

    #[Test]
    public function a_repeated_header_goes_to_the_next_free_column(): void
    {
        $cols = new ImportColumns([
            ['key' => 'state', 'label' => 'col.state', 'legacy' => ['الولاية']],
            ['key' => 'wilaya', 'label' => 'col.wilaya', 'legacy' => ['الولاية (الرمز أو الاسم)']],
        ]);

        [, $map] = $cols->locate([['الولاية', 'الولاية (الرمز أو الاسم)']]);

        $this->assertSame(['state' => 0, 'wilaya' => 1], $map);
    }

    #[Test]
    public function values_match_codes_or_labels_in_any_language(): void
    {
        $codes = ['income' => 'income', 'expense' => 'expense'];

        $this->assertSame('expense', ImportColumns::value('expense', $codes));
        $this->assertSame('expense', ImportColumns::value(' EXPENSE ', $codes));
        foreach (['ar', 'fr', 'en'] as $locale) {
            $this->assertSame('income', ImportColumns::value(UiLang::get('income', null, $locale), $codes), $locale);
        }
        $this->assertNull(ImportColumns::value('nonsense', $codes));
        $this->assertNull(ImportColumns::value(null, $codes));
    }
}
