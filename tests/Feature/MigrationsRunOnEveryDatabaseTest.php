<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The suite runs on SQLite, which accepts what PostgreSQL refuses in a
 * migration, so nothing else here would catch these two.
 */
class MigrationsRunOnEveryDatabaseTest extends TestCase
{
    #[Test]
    public function no_migration_compares_a_yes_no_column_with_a_number(): void
    {
        $found = [];
        foreach (File::files(database_path('migrations')) as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (preg_match('/\bis_[a-z_]+ *(=|<>|!=) *[01]\b/', $line)) {
                    $found[] = $file->getFilename().':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $found, 'Use IS TRUE or IS FALSE, which every database accepts.');
    }

    #[Test]
    public function a_virtual_generated_column_in_a_migration_branches_by_driver(): void
    {
        $found = [];
        foreach (File::files(database_path('migrations')) as $file) {
            $code = $file->getContents();
            if (str_contains($code, 'virtualAs(') && ! str_contains($code, "'pgsql'")) {
                $found[] = $file->getFilename();
            }
        }

        $this->assertSame([], $found, 'PostgreSQL cannot index a virtual column: use storedAs() there.');
    }
}
