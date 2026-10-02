<?php

namespace Tests\Feature;

use App\Models\TrainingSchedule;
use App\Services\Attendance\SessionGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

/**
 * A rare MySQL deadlock between two calendar generations is retried by the
 * outermost generation transaction instead of failing the page. Not
 * RefreshDatabase: inside its wrapping transaction a deadlock is (rightly)
 * rethrown to the outer level, which is the test's own. Each test migrates
 * its own in-memory database instead (gone with the app, so no rollback).
 */
class SessionGeneratorRetryTest extends TestCase
{
    use AttendanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);
    }

    /** The first link insert-select fails once as a deadlock. */
    private function deadlockOnce(): void
    {
        $thrown = false;
        DB::listen(function ($query) use (&$thrown) {
            if (! $thrown && str_starts_with($query->sql, 'insert or ignore into "training_session_category"')) {
                $thrown = true;
                throw new RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            }
        });
    }

    private function scheduleMondays(): int
    {
        $category = $this->category();
        TrainingSchedule::create([
            'category_id' => $category->id, 'weekday' => 1, 'start_time' => '18:00',
            'end_time' => '19:30', 'valid_from' => '2026-01-01',
        ]);

        return $category->id;
    }

    private function assertGenerated(int $categoryId): void
    {
        $this->assertSame(5, DB::table('training_sessions')->where('category_id', $categoryId)->whereBetween('date', ['2027-03-01', '2027-03-31'])->count());
        $this->assertSame(5, DB::table('training_session_category')->where('category_id', $categoryId)->count());
        $this->assertTrue(DB::table('session_generation_marks')->where('category_id', $categoryId)->where('month', '2027-03')->exists());
    }

    #[Test]
    public function a_month_generation_retries_a_deadlock(): void
    {
        $categoryId = $this->scheduleMondays();
        $this->deadlockOnce();

        app(SessionGenerator::class)->forMonth($categoryId, 2027, 3);

        $this->assertGenerated($categoryId);
    }

    #[Test]
    public function a_range_generation_retries_a_deadlock_at_its_outer_transaction(): void
    {
        $categoryId = $this->scheduleMondays();
        $this->deadlockOnce();

        app(SessionGenerator::class)->forRange('2027-03-01', '2027-03-31');

        $this->assertGenerated($categoryId);
    }
}
