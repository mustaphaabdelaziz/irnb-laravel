<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCalendarBranchTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function makeSession(int $categoryId, int $branchId, array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'branch_id' => $branchId, 'category_id' => $categoryId, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function every_view_shows_the_chosen_branch_only(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $f = $this->makeSession($u15->id, $foot->id);
        $s = $this->makeSession($u15->id, $swim->id);
        $admin = $this->admin();

        foreach (['month', 'week', 'agenda'] as $view) {
            $this->actingAs($admin)->get(route('attendance.index', ['view' => $view, 'category_id' => $u15->id, 'month' => '2026-10', 'date' => '2026-10-05', 'branch_id' => $swim->id]))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('branchId', $swim->id)
                    ->has('branches', 2)
                    ->where('sessions', fn ($sessions) => collect($sessions)->pluck('id')->all() === [$s->id]));
        }
        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'month' => '2026-10', 'branch_id' => $foot->id]))
            ->assertInertia(fn (Assert $page) => $page->where('events', fn ($events) => collect($events)->where('type', 'session')->pluck('id')->all() === [$f->id]));
    }

    #[Test]
    public function the_branch_defaults_to_the_first_then_is_remembered(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id]))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $foot->id));
        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id, 'branch_id' => $swim->id]));
        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id]))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $swim->id));
    }

    #[Test]
    public function without_branches_the_calendar_shows_everything(): void
    {
        $u15 = $this->category();
        TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);

        $this->actingAs($this->admin())->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', null)->has('sessions', 1));
    }

    #[Test]
    public function pre_season_progress_counts_the_branch_only(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        PreseasonTarget::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 10]);
        PreseasonTarget::create(['branch_id' => $swim->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 4]);
        $this->makeSession($u15->id, $foot->id, ['kind' => SessionKind::Preseason, 'state' => SessionState::Held, 'date' => '2026-09-07']);

        $this->actingAs($this->admin())->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-09', 'branch_id' => $swim->id]))
            ->assertInertia(fn (Assert $page) => $page->where('preseason.done', 0)->where('preseason.target', 4));
    }
}
