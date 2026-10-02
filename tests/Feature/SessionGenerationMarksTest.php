<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Attendance\SessionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

/**
 * A generated month is marked and skipped on later views; every change to
 * what the generator reads must make the next view generate it again.
 * March 2027 is in the future, so schedule edits purge its planned sessions.
 */
class SessionGenerationMarksTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private User $user;

    private Category $u15;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->admin();
        $this->u15 = $this->category();
    }

    private function schedule(Category $category, int $weekday = 1, string $start = '18:00'): TrainingSchedule
    {
        return TrainingSchedule::create([
            'category_id' => $category->id, 'weekday' => $weekday, 'start_time' => $start,
            'end_time' => '19:30', 'valid_from' => '2026-01-01',
        ]);
    }

    private function viewMonth(Category $category, string $month = '2027-03'): void
    {
        $this->actingAs($this->user)
            ->get(route('attendance.index', ['view' => 'month', 'category_id' => $category->id, 'month' => $month]))
            ->assertOk();
    }

    /** @return array<int, string> dates of the category's sessions in March 2027 */
    private function dates(Category $category): array
    {
        return TrainingSession::where('category_id', $category->id)
            ->whereBetween('date', ['2027-03-01', '2027-03-31'])
            ->orderBy('date')->pluck('date')->all();
    }

    private function marked(Category $category, string $month = '2027-03'): bool
    {
        return DB::table('session_generation_marks')->where('category_id', $category->id)->where('month', $month)->exists();
    }

    #[Test]
    public function generating_a_month_marks_it_and_a_month_without_schedule_is_not_marked(): void
    {
        $u17 = $this->category('U17');
        $this->schedule($this->u15);

        $this->viewMonth($this->u15);
        $this->viewMonth($u17);

        $this->assertTrue($this->marked($this->u15));
        $this->assertFalse($this->marked($u17));
        $this->assertSame(['2027-03-01', '2027-03-08', '2027-03-15', '2027-03-22', '2027-03-29'], $this->dates($this->u15));
    }

    #[Test]
    public function a_new_schedule_is_generated_into_an_already_viewed_month(): void
    {
        $this->schedule($this->u15);
        $this->viewMonth($this->u15);

        $this->actingAs($this->user)->post(route('attendance.schedules.store'), [
            'category_id' => $this->u15->id, 'weekday' => 3, 'start_time' => '17:00', 'end_time' => '18:30', 'valid_from' => '2026-01-01',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($this->marked($this->u15));

        $this->viewMonth($this->u15);
        $this->assertCount(5 + 5, $this->dates($this->u15)); // five Mondays, five Wednesdays
    }

    #[Test]
    public function an_edited_schedule_regenerates_its_category_and_the_one_it_moved_to(): void
    {
        $u17 = $this->category('U17');
        $schedule = $this->schedule($this->u15);
        $this->viewMonth($this->u15);
        $this->viewMonth($u17);

        $this->actingAs($this->user)->put(route('attendance.schedules.update', $schedule), [
            'category_id' => $u17->id, 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01',
        ])->assertSessionHasNoErrors();
        $this->viewMonth($this->u15);
        $this->viewMonth($u17);

        $this->assertSame([], $this->dates($this->u15));
        $this->assertSame(['2027-03-02', '2027-03-09', '2027-03-16', '2027-03-23', '2027-03-30'], $this->dates($u17));
    }

    /**
     * The schedule's version bump (its saved/deleted event) and the purge of
     * its planned sessions run in one transaction, so a concurrent generation
     * cannot mark a month between the two (its mark() waits on the version
     * row lock until both are committed).
     *
     * @return array<string, array{0: string}>
     */
    public static function scheduleChanges(): array
    {
        return ['update' => ['update'], 'destroy' => ['destroy']];
    }

    #[Test]
    #[DataProvider('scheduleChanges')]
    public function a_schedule_change_bumps_the_version_and_purges_in_one_transaction(string $change): void
    {
        $schedule = $this->schedule($this->u15);
        $this->viewMonth($this->u15);
        $this->assertNotEmpty($this->dates($this->u15));

        $baseline = DB::transactionLevel();
        $levels = ['bump' => [], 'purge' => []];
        DB::listen(function ($query) use (&$levels) {
            if (str_starts_with($query->sql, 'update "session_generation_version"')) {
                $levels['bump'][] = DB::transactionLevel();
            } elseif (str_starts_with($query->sql, 'delete from "training_sessions"')) {
                $levels['purge'][] = DB::transactionLevel();
            }
        });

        $request = $this->actingAs($this->user);
        $change === 'update'
            ? $request->put(route('attendance.schedules.update', $schedule), [
                'category_id' => $this->u15->id, 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01',
            ])->assertSessionHasNoErrors()
            : $request->delete(route('attendance.schedules.destroy', $schedule))->assertSessionHasNoErrors();

        $this->assertSame([$baseline + 1], $levels['bump']);
        $this->assertSame([$baseline + 1], $levels['purge']);
    }

    #[Test]
    public function a_deleted_schedule_forgets_its_category(): void
    {
        $schedule = $this->schedule($this->u15);
        $this->viewMonth($this->u15);

        $this->actingAs($this->user)->delete(route('attendance.schedules.destroy', $schedule))->assertSessionHasNoErrors();

        $this->assertFalse($this->marked($this->u15));
        $this->viewMonth($this->u15);
        $this->assertSame([], $this->dates($this->u15));
    }

    #[Test]
    public function closures_forget_every_category_in_their_months_only(): void
    {
        $u17 = $this->category('U17');
        $this->schedule($this->u15);
        $this->schedule($u17, 1, '17:00');
        $this->viewMonth($this->u15);
        $this->viewMonth($u17);
        $this->viewMonth($this->u15, '2027-05');

        $this->actingAs($this->user)->post(route('attendance.closures.store'), [
            'start_date' => '2027-03-07', 'end_date' => '2027-03-16', 'reason' => 'Holidays',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($this->marked($this->u15));
        $this->assertFalse($this->marked($u17));
        $this->assertTrue($this->marked($this->u15, '2027-05'));

        $this->viewMonth($this->u15);
        $this->assertSame(['2027-03-01', '2027-03-22', '2027-03-29'], $this->dates($this->u15));

        $this->actingAs($this->user)->delete(route('attendance.closures.destroy', ClubClosure::first()))->assertSessionHasNoErrors();
        $this->viewMonth($this->u15);
        $this->assertSame(['2027-03-01', '2027-03-08', '2027-03-15', '2027-03-22', '2027-03-29'], $this->dates($this->u15));
    }

    #[Test]
    public function a_deleted_session_comes_back_on_the_next_view_as_before(): void
    {
        $this->schedule($this->u15);
        $this->viewMonth($this->u15);

        TrainingSession::where('category_id', $this->u15->id)->where('date', '2027-03-08')->first()->delete();
        $this->viewMonth($this->u15);

        $this->assertContains('2027-03-08', $this->dates($this->u15));
    }

    #[Test]
    public function a_moved_session_forgets_both_months_and_its_old_slot_stays_free(): void
    {
        $this->schedule($this->u15);
        $this->viewMonth($this->u15);
        $this->viewMonth($this->u15, '2027-04');
        $session = TrainingSession::where('category_id', $this->u15->id)->where('date', '2027-03-29')->first();

        $this->actingAs($this->user)->post(route('attendance.sessions.move', $session), [
            'date' => '2027-04-03', 'start_time' => '10:00', 'end_time' => '11:30',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($this->marked($this->u15));
        $this->assertFalse($this->marked($this->u15, '2027-04'));
        $this->viewMonth($this->u15);
        $this->assertNotContains('2027-03-29', $this->dates($this->u15));
    }

    #[Test]
    public function a_category_dropped_from_a_joint_session_gets_its_regular_slot_back(): void
    {
        $u17 = $this->category('U17');
        $this->schedule($this->u15);
        $this->schedule($u17);
        $joint = TrainingSession::create([
            'category_id' => $this->u15->id, 'date' => '2027-03-01', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Planned,
        ]);
        $joint->categories()->syncWithoutDetaching([$u17->id]);
        $this->viewMonth($u17);
        $this->assertNotContains('2027-03-01', $this->dates($u17));

        $this->actingAs($this->user)->put(route('attendance.sessions.categories', $joint), ['category_ids' => [$this->u15->id]])
            ->assertSessionHasNoErrors();
        $this->viewMonth($u17);

        $this->assertContains('2027-03-01', $this->dates($u17));
    }

    #[Test]
    public function the_all_category_views_skip_marked_months_and_regenerate_forgotten_ones(): void
    {
        $u17 = $this->category('U17');
        $this->schedule($this->u15);
        $this->schedule($u17, 2);
        $agenda = fn () => $this->actingAs($this->user)
            ->get(route('attendance.index', ['view' => 'agenda', 'month' => '2027-03']))->assertOk();

        $agenda();
        $this->assertTrue($this->marked($this->u15));
        $this->assertTrue($this->marked($u17));

        $this->schedule($u17, 4);
        $this->assertTrue($this->marked($this->u15));
        $this->assertFalse($this->marked($u17));

        $agenda();
        $this->assertCount(5, $this->dates($this->u15));
        $this->assertCount(5 + 4, $this->dates($u17)); // five Tuesdays, four Thursdays
    }

    /**
     * Another request deletes a closure after this generation read the
     * closures but before it marks the month: its forget found no mark to
     * delete, so the generation itself must not mark the month either.
     */
    private function deleteClosureWhenItIsRead(ClubClosure $closure): void
    {
        $done = false;
        DB::listen(function ($query) use (&$done, $closure) {
            if (! $done && str_contains($query->sql, 'from "club_closures"')) {
                $done = true;
                $closure->delete();
            }
        });
    }

    #[Test]
    public function a_month_whose_inputs_change_during_generation_is_not_marked(): void
    {
        $this->schedule($this->u15);
        $this->deleteClosureWhenItIsRead(ClubClosure::create(['start_date' => '2027-03-07', 'end_date' => '2027-03-16', 'reason' => 'Holidays']));

        app(SessionGenerator::class)->forMonth($this->u15->id, 2027, 3);

        $this->assertSame(['2027-03-01', '2027-03-22', '2027-03-29'], $this->dates($this->u15));
        $this->assertFalse($this->marked($this->u15));
        $this->viewMonth($this->u15);
        $this->assertSame(['2027-03-01', '2027-03-08', '2027-03-15', '2027-03-22', '2027-03-29'], $this->dates($this->u15));
    }

    #[Test]
    public function a_range_whose_inputs_change_during_generation_is_not_marked(): void
    {
        $this->schedule($this->u15);
        $this->deleteClosureWhenItIsRead(ClubClosure::create(['start_date' => '2027-03-07', 'end_date' => '2027-03-16', 'reason' => 'Holidays']));

        app(SessionGenerator::class)->forRange('2027-03-01', '2027-03-31');

        $this->assertFalse($this->marked($this->u15));
        $this->viewMonth($this->u15);
        $this->assertCount(5, $this->dates($this->u15));
    }

    #[Test]
    public function the_regenerate_command_forgets_every_mark_or_one_categorys(): void
    {
        $u17 = $this->category('U17');
        $this->schedule($this->u15);
        $this->schedule($u17);
        $this->viewMonth($this->u15);
        $this->viewMonth($u17);

        $this->artisan('attendance:regenerate', ['--category' => $u17->id])->assertSuccessful();
        $this->assertTrue($this->marked($this->u15));
        $this->assertFalse($this->marked($u17));

        $this->artisan('attendance:regenerate')->assertSuccessful();
        $this->assertFalse($this->marked($this->u15));

        $this->artisan('attendance:regenerate', ['--category' => 999999])->assertFailed();
    }
}
