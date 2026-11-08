<?php

namespace Database\Seeders;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\SessionGenerator;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Attendance for the Help Center demo club, relative to the day it runs: three
 * weeks of held sessions, the next sessions planned. Called from HelpDemoSeeder.
 * Sessions the articles open by id are created first (ids 1–3 on the fresh DB).
 */
class HelpDemoAttendanceSeeder extends Seeder
{
    public const FOOT_CATEGORY = 'أقل من 17 سنة';

    public const SWIM_CATEGORY = 'أصاغر';

    public const CLOSURE_REASON = 'عطلة مدرسية';

    public const CANCEL_REASON = 'المسبح مغلق للصيانة';

    public const PRESEASON_TITLE = 'جري 7.2 كم في 40 دقيقة';

    public const PRESEASON_TARGET = 12;

    public function run(): void
    {
        HelpDemoSeeder::assertThrowawayDatabase();

        $demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();
        $football = Branch::where('name', 'Football')->firstOrFail();
        $swim = Branch::where('name', 'Swimming')->firstOrFail();
        $u17 = Category::where('branch_id', $football->id)->where('name', self::FOOT_CATEGORY)->firstOrFail();
        $cadets = Category::where('branch_id', $swim->id)->where('name', self::SWIM_CATEGORY)->firstOrFail();

        $today = CarbonImmutable::today();
        $from = $today->subDays(21);

        // Weekly timetable from three weeks ago: U17 Mon + Wed, «أصاغر» Tue + Thu.
        $u17Slots = $this->schedules($football, $u17, [1, 3], '18:00', '19:30', $from);
        $swimSlots = $this->schedules($swim, $cadets, [2, 4], '17:00', '18:30', $from);

        // A closure three weeks ahead, before generation, so no session lands in it.
        ClubClosure::create([
            'start_date' => $today->addDays(20)->toDateString(),
            'end_date' => $today->addDays(22)->toDateString(),
            'reason' => self::CLOSURE_REASON,
        ]);

        // 1 — the next U17 session, left planned for the take-attendance article.
        $this->regular($football, $u17, $u17Slots, $today, '18:00', '19:30');

        // 2 — a pre-season session last Saturday (held below with the regular ones).
        TrainingSession::create([
            'branch_id' => $football->id,
            'category_id' => $u17->id,
            'date' => $today->previous(CarbonInterface::SATURDAY)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:30',
            'kind' => SessionKind::Preseason,
            'state' => SessionState::Planned,
            'title' => self::PRESEASON_TITLE,
        ]);

        // 3 — the next «أصاغر» session, cancelled.
        $this->regular($swim, $cadets, $swimSlots, $today, '17:00', '18:30')
            ->update(['state' => SessionState::Cancelled, 'cancel_reason' => self::CANCEL_REASON]);

        // Every other timetable session, as opening the calendar would generate them.
        app(SessionGenerator::class)->forRange($from->toDateString(), $today->addDays(35)->toDateString());

        PreseasonTarget::create([
            'branch_id' => $football->id,
            'category_id' => $u17->id,
            'season_start_year' => Season::current()->start()->year,
            'target_count' => self::PRESEASON_TARGET,
        ]);

        $players = Player::orderBy('id')->get()->values();
        $recorder = app(MarkRecorder::class);
        $log = ['coach' => 'كريم المدرب']; // no title key: it would blank the pre-season title

        // U17, newest first: a 3-session unexcused streak, an open injury, one late, one excused.
        [$amine, $riad, $abderrahmane, $anes] = [$players[2], $players[3], $players[6], $players[20]];
        foreach ($this->past($u17, $today) as $rank => $session) {
            $recorder->save($session, [
                ['player_id' => $amine->id, 'status' => $rank === 1 ? 'late' : 'present', 'minutes' => $rank === 1 ? 15 : null],
                ['player_id' => $riad->id] + ($rank < 2 ? ['status' => 'not_training', 'reason' => 'injury'] : ['status' => 'present']),
                ['player_id' => $abderrahmane->id, 'status' => $rank < 3 ? 'absent_unexcused' : 'present'],
                ['player_id' => $anes->id] + ($rank === 4 ? ['status' => 'absent_excused', 'reason' => 'school'] : ['status' => 'present']),
            ], $demo, $log);
        }

        // «أصاغر»: one left early, one late, the rest present.
        [$sara, $inas] = [$players[11], $players[12]];
        $swimmers = [$players[11], $players[12], $players[16], $players[17], $players[21]];
        foreach ($this->past($cadets, $today) as $rank => $session) {
            $recorder->save($session, array_map(fn (Player $p) => match (true) {
                $p->is($sara) && $rank === 0 => ['player_id' => $p->id, 'status' => 'left_early', 'minutes' => 10],
                $p->is($inas) && $rank === 2 => ['player_id' => $p->id, 'status' => 'late', 'minutes' => 5],
                default => ['player_id' => $p->id, 'status' => 'present'],
            }, $swimmers), $demo, $log);
        }
    }

    /** @return array<int, TrainingSchedule> keyed by ISO weekday */
    private function schedules(Branch $branch, Category $category, array $weekdays, string $start, string $end, CarbonImmutable $from): array
    {
        $slots = [];
        foreach ($weekdays as $weekday) {
            $slots[$weekday] = TrainingSchedule::create([
                'branch_id' => $branch->id,
                'category_id' => $category->id,
                'weekday' => $weekday,
                'start_time' => $start,
                'end_time' => $end,
                'valid_from' => $from->toDateString(),
                'valid_to' => null,
            ]);
        }

        return $slots;
    }

    /** The category's first timetable session on or after $day, created by hand so its id is known. */
    private function regular(Branch $branch, Category $category, array $slots, CarbonImmutable $day, string $start, string $end): TrainingSession
    {
        while (! isset($slots[$day->dayOfWeekIso])) {
            $day = $day->addDay();
        }

        return TrainingSession::create([
            'branch_id' => $branch->id,
            'category_id' => $category->id,
            'schedule_id' => $slots[$day->dayOfWeekIso]->id,
            'date' => $day->toDateString(),
            'start_time' => $start,
            'end_time' => $end,
            'kind' => SessionKind::Regular,
            'state' => SessionState::Planned,
        ]);
    }

    /** @return Collection<int, TrainingSession> the category's sessions before today, newest first */
    private function past(Category $category, CarbonImmutable $today): Collection
    {
        return TrainingSession::where('category_id', $category->id)
            ->where('date', '<', $today->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('start_time')
            ->get()
            ->values();
    }
}
