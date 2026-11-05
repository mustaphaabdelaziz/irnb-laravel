<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The suite runs on SQLite, where `is_student = 1` works. PostgreSQL refuses
 * it and the page gives a server error, so nothing else here would catch it.
 */
class NoBooleanNumberComparisonTest extends TestCase
{
    #[Test]
    public function raw_sql_does_not_compare_a_yes_no_column_with_a_number(): void
    {
        $found = [];
        foreach (File::allFiles(app_path()) as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (preg_match('/\b[a-z_.]*\bis_[a-z_]+ *(=|<>|!=) *[01]\b/', $line)) {
                    $found[] = $file->getRelativePathname().':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $found, 'Use IS TRUE or IS FALSE, which every database accepts.');
    }
}
