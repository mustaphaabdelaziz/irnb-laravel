<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PdfTranslationsTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function catalog(string $locale): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2)."/lang/{$locale}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,array{string}> literal __('...') keys used by the PDF templates */
    public static function literalKeys(): array
    {
        $keys = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views/pdf'));
        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $key) {
                $key = stripslashes($key);
                $keys[$key] = [$key];
            }
        }

        return $keys;
    }

    #[DataProvider('literalKeys')]
    public function test_literal_keys_are_translated_in_arabic_and_french(string $key): void
    {
        $this->assertArrayHasKey($key, self::catalog('ar'), "ar.json lacks [{$key}]");
        $this->assertArrayHasKey($key, self::catalog('fr'), "fr.json lacks [{$key}]");
    }

    /** Values printed through __(ucfirst/ucwords(...)) in the meeting and inventory PDFs. */
    public static function dynamicKeys(): array
    {
        $values = [
            // meeting type, status, attendance, task status, board roles
            'Ordinary', 'Extraordinary', 'General Assembly',
            'Scheduled', 'Held', 'Cancelled',
            'Present', 'Absent', 'Excused',
            'Not Started', 'In Progress', 'Completed',
            'President', 'Vice President', 'Secretary', 'Treasurer', 'Member',
            // inventory type
            'Monthly', 'Quarterly', 'Yearly', 'Ad Hoc',
        ];

        return array_combine($values, array_map(fn ($v) => [$v], $values));
    }

    #[DataProvider('dynamicKeys')]
    public function test_dynamic_values_are_translated(string $key): void
    {
        $this->assertArrayHasKey($key, self::catalog('ar'), "ar.json lacks [{$key}]");
        $this->assertArrayHasKey($key, self::catalog('fr'), "fr.json lacks [{$key}]");
    }
}
