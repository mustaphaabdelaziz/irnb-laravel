<?php

namespace Tests\Unit;

use App\Services\Activity\ActivityAction;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guards the "explicit recording" rule: every action code is recorded by some
 * controller or service, and none is recorded from a model observer.
 */
class ActivityCoverageTest extends TestCase
{
    /** @return array<string, string> file path => contents, for PHP files under $dir */
    private function sources(string $dir): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $out[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    #[Test]
    public function every_action_is_recorded_somewhere_in_the_app(): void
    {
        $activityDir = app_path('Services'.DIRECTORY_SEPARATOR.'Activity');
        $sources = array_filter(
            $this->sources(app_path()),
            fn (string $path) => ! str_starts_with($path, $activityDir),
            ARRAY_FILTER_USE_KEY,
        );
        $code = implode("\n", $sources);

        $constants = (new ReflectionClass(ActivityAction::class))->getConstants();
        foreach ($constants as $name => $value) {
            if (! is_string($value)) {
                continue; // ALL / AREAS / QUALITY
            }
            $this->assertMatchesRegularExpression('/ActivityAction::'.$name.'\b/', $code, "{$value} is never recorded");
        }
    }

    #[Test]
    public function no_observer_records_activity(): void
    {
        foreach ($this->sources(app_path('Observers')) as $path => $contents) {
            $this->assertStringNotContainsString('ActivityRecorder', $contents, $path);
        }
    }
}
