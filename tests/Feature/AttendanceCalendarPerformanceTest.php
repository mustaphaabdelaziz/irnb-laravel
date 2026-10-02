<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\TrainingSchedule;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

/**
 * Opening the calendar again with nothing changed must not write: the
 * desktop app serves one request at a time on SQLite, so a write transaction
 * per view (and a query per category and month) is what made it slow.
 */
class AttendanceCalendarPerformanceTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private User $user;

    /** @var array<int, Category> */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->admin();
        foreach (['U11', 'U13', 'U15', 'U17', 'U19', 'Seniors'] as $i => $name) {
            $category = $this->category($name);
            $this->categories[] = $category;
            foreach ([1 + $i % 2, 3 + $i % 2, 5] as $weekday) {
                TrainingSchedule::create([
                    'category_id' => $category->id, 'weekday' => $weekday, 'start_time' => '17:00',
                    'end_time' => '18:30', 'valid_from' => '2026-01-01',
                ]);
            }
        }
        ClubClosure::create(['start_date' => '2026-12-20', 'end_date' => '2027-01-03', 'reason' => 'Winter break']);
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function views(): array
    {
        return [
            'month' => [['view' => 'month', 'month' => '2026-10']],
            'week' => [['view' => 'week', 'date' => '2026-10-28']],
            'agenda' => [['view' => 'agenda', 'month' => '2026-10']],
            'timeline' => [['view' => 'timeline', 'from' => '2026-01', 'to' => '2026-12']],
        ];
    }

    #[Test]
    #[DataProvider('views')]
    public function a_repeat_view_with_nothing_changed_writes_nothing(array $params): void
    {
        if ($params['view'] === 'month') {
            $params['category_id'] = $this->categories[2]->id;
        }

        $first = $this->measure($params);
        $second = $this->measure($params);
        $this->assertNotEmpty($first['writes'], 'the first view generates sessions');
        $this->assertSame([], $second['writes']);
        $this->assertSame(0, $second['transactions']);
        $this->assertLessThanOrEqual(20, $second['queries']);
    }

    /** @return array{queries: int, writes: array<int, string>, transactions: int} */
    private function measure(array $params): array
    {
        $queries = 0;
        $writes = [];
        $transactions = 0;
        $listening = true;
        DB::listen(function ($query) use (&$queries, &$writes, &$listening) {
            if (! $listening) {
                return;
            }
            $queries++;
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        Event::listen(TransactionBeginning::class, function () use (&$transactions, &$listening) {
            if ($listening) {
                $transactions++;
            }
        });

        $this->actingAs($this->user)->get(route('attendance.index', $params))->assertOk();
        $listening = false;

        return ['queries' => $queries, 'writes' => $writes, 'transactions' => $transactions];
    }
}
