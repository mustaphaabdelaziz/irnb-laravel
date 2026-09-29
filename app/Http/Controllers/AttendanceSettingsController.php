<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceCode;
use App\Support\AttendanceSettings;
use App\Support\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceSettingsController extends Controller
{
    public function index(): Response
    {
        $current = Season::current();

        return Inertia::render('Attendance/Settings', [
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values(),
            'schedules' => TrainingSchedule::orderBy('category_id')->orderBy('weekday')->orderBy('start_time')
                ->get(['id', 'category_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to']),
            'closures' => ClubClosure::orderByDesc('start_date')->get(['id', 'start_date', 'end_date', 'reason']),
            'targets' => PreseasonTarget::get(['category_id', 'season_start_year', 'target_count']),
            'seasons' => collect([$current, Season::forStartYear($current->startYear + 1)])
                ->map(fn (Season $s) => ['start_year' => $s->startYear, 'label' => $s->label()])->values(),
            'settings' => AttendanceSettings::get(),
            'statuses' => AttendanceStatus::values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [
            'rules.lates_per_unexcused' => ['required', 'integer', 'between:0,20'],
            'rules.late_minutes_as_absent' => ['required', 'integer', 'between:0,240'],
            'alerts.min_score_pct' => ['required', 'integer', 'between:0,100'],
            'alerts.unexcused_streak' => ['required', 'integer', 'between:0,20'],
        ];
        foreach (AttendanceStatus::values() as $status) {
            $rules["points.$status"] = ['required', 'numeric', 'between:-5,5'];
            $rules["codes.$status.code"] = ['required', 'string', 'regex:/^\p{L}{1,3}$/u'];
            $rules["codes.$status.color"] = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
            $rules["codes.$status.label"] = ['nullable', 'array'];
            foreach (AttendanceSettings::LOCALES as $locale) {
                $rules["codes.$status.label.$locale"] = ['nullable', 'string', 'max:40'];
            }
        }
        $data = $request->validate($rules, [
            'codes.*.code.required' => 'att.error.code_format',
            'codes.*.code.regex' => 'att.error.code_format',
            'codes.*.color.required' => 'att.error.color_format',
            'codes.*.color.regex' => 'att.error.color_format',
        ]);

        AttendanceSettings::save([
            'points' => array_map('floatval', $data['points']),
            'rules' => array_map('intval', $data['rules']),
            'alerts' => array_map('intval', $data['alerts']),
            'codes' => $this->normaliseCodes($data['codes']),
        ]);

        return back()->with('success', 'flash.attendance_settings_saved');
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        TrainingSchedule::create($this->validateSchedule($request));

        return back()->with('success', 'flash.training_schedule_saved');
    }

    public function updateSchedule(Request $request, TrainingSchedule $schedule): RedirectResponse
    {
        $schedule->update($this->validateSchedule($request));
        $this->purgeFuturePlanned($schedule);

        return back()->with('success', 'flash.training_schedule_saved');
    }

    public function destroySchedule(TrainingSchedule $schedule): RedirectResponse
    {
        $this->purgeFuturePlanned($schedule);
        $schedule->delete();

        return back()->with('success', 'flash.training_schedule_deleted');
    }

    public function storeClosure(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:100'],
        ]);

        ClubClosure::create($data);
        TrainingSession::unmarkedPlanned()
            ->where('kind', SessionKind::Regular->value)
            ->whereNull('moved_from')
            ->whereBetween('date', [$data['start_date'], $data['end_date']])
            ->delete();

        return back()->with('success', 'flash.club_closure_saved');
    }

    public function destroyClosure(ClubClosure $closure): RedirectResponse
    {
        $closure->delete(); // the next month view regenerates the freed dates

        return back()->with('success', 'flash.club_closure_deleted');
    }

    public function storeTarget(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'season_start_year' => ['required', 'integer', 'between:2000,2100'],
            'target_count' => ['required', 'integer', 'between:0,200'],
        ]);

        PreseasonTarget::updateOrCreate(
            ['category_id' => $data['category_id'], 'season_start_year' => $data['season_start_year']],
            ['target_count' => $data['target_count']],
        );

        return back()->with('success', 'flash.preseason_target_saved');
    }

    /**
     * Codes are stored upper-cased and must differ from each other ignoring
     * case (the grid compares them that way); the later status in the list
     * gets the error. Colours are stored lower-cased, empty names as null.
     */
    private function normaliseCodes(array $input): array
    {
        $codes = [];
        $seen = [];
        $errors = [];

        foreach (AttendanceStatus::values() as $status) {
            $row = $input[$status];
            $code = AttendanceCode::normalise($row['code']);
            if (isset($seen[$code])) {
                $errors["codes.$status.code"] = 'att.error.code_taken';
            }
            $seen[$code] = true;

            $labels = [];
            foreach (AttendanceSettings::LOCALES as $locale) {
                $label = $row['label'][$locale] ?? null;
                $labels[$locale] = $label === null || $label === '' ? null : $label;
            }

            $codes[$status] = ['code' => $code, 'color' => strtolower($row['color']), 'label' => $labels];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $codes;
    }

    private function validateSchedule(Request $request): array
    {
        return $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ]);
    }

    /**
     * Upcoming generated sessions nobody marked are rebuilt from the new
     * schedule on the next month view. Moved ones are left alone: move()
     * keeps the schedule's `schedule_id`, and purging one here would drop
     * its `moved_from` guard, so the generator would recreate the original
     * date right back.
     */
    private function purgeFuturePlanned(TrainingSchedule $schedule): void
    {
        TrainingSession::unmarkedPlanned()
            ->where('schedule_id', $schedule->id)
            ->whereNull('moved_from')
            ->where('date', '>=', now()->toDateString())
            ->delete();
    }
}
