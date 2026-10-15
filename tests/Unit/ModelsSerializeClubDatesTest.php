<?php

namespace Tests\Unit;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guards that every Eloquent model serializes dates on the club's clock,
 * either by using the SerializesClubDates trait or by declaring its own
 * serializeDate method, so date edit forms don't accidentally save the
 * previous day when midnight happens in the club's timezone.
 */
class ModelsSerializeClubDatesTest extends TestCase
{
    #[Test]
    public function every_model_serializes_dates_on_the_club_clock(): void
    {
        $models = $this->findConcreteModels();

        foreach ($models as $className) {
            $reflection = new ReflectionClass($className);

            // Check if the model uses the SerializesClubDates trait.
            $usesSerializesClubDates = in_array(
                SerializesClubDates::class,
                class_uses_recursive($className),
                true
            );

            // Check if the model declares its own serializeDate method.
            $hasOwnSerializeDate = false;
            if ($reflection->hasMethod('serializeDate')) {
                $method = $reflection->getMethod('serializeDate');
                // Only count it if this class (not a parent) declares it.
                $hasOwnSerializeDate = $method->getDeclaringClass()->getName() === $className;
            }

            $this->assertTrue(
                $usesSerializesClubDates || $hasOwnSerializeDate,
                "{$className}: add the SerializesClubDates trait so dates reach the screens on the club's clock"
            );
        }
    }

    /**
     * Find all concrete Model subclasses under app/Models (excluding Concerns).
     *
     * @return array<string> Fully qualified class names
     */
    private function findConcreteModels(): array
    {
        $models = [];
        $baseDir = app_path('Models');

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            // Skip non-PHP files and the Concerns directory.
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'Concerns')) {
                continue;
            }

            // Resolve the class name from the file path.
            $className = $this->resolveClassNameFromFile($file->getPathname(), $baseDir);
            if (! $className) {
                continue;
            }

            // Only consider concrete Model subclasses.
            if ($this->isConcreteModelSubclass($className)) {
                $models[] = $className;
            }
        }

        return $models;
    }

    /**
     * Extract the fully qualified class name from a PHP file path.
     *
     * @param  string  $filePath  Absolute path to the PHP file
     * @param  string  $baseDir  Base directory (e.g., app/Models)
     * @return string|null The fully qualified class name, or null if not found
     */
    private function resolveClassNameFromFile(string $filePath, string $baseDir): ?string
    {
        $contents = file_get_contents($filePath);
        if (! $contents) {
            return null;
        }

        // Extract namespace.
        $namespace = null;
        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $matches)) {
            $namespace = trim($matches[1]);
        }

        // Extract class name (skip traits and interfaces).
        $className = null;
        if (preg_match('/^class\s+(\w+)[\s{]/m', $contents, $matches)) {
            $className = $matches[1];
        }

        if (! $namespace || ! $className) {
            return null;
        }

        return $namespace.'\\'.$className;
    }

    /**
     * Check if a class is a concrete (non-abstract) subclass of Model.
     */
    private function isConcreteModelSubclass(string $className): bool
    {
        if (! class_exists($className)) {
            return false;
        }

        $reflection = new ReflectionClass($className);

        // Must be a subclass of Model and not abstract.
        return ! $reflection->isAbstract() && $reflection->isSubclassOf(Model::class);
    }
}
