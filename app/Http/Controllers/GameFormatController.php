<?php

namespace App\Http\Controllers;

use App\Enums\PeriodLabel;
use App\Enums\ScoringType;
use App\Enums\Sport;
use App\Models\Branch;
use App\Models\GameFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GameFormatController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/GameFormats', [
            'formats' => GameFormat::query()->with(['branch', 'formations:id,game_format_id,name'])
                ->orderBy('sport')->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
            'sports' => Sport::values(),
            'periodLabels' => PeriodLabel::values(),
            'scorings' => ScoringType::values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        GameFormat::create($this->validated($request) + ['is_preset' => false]);

        return back()->with('success', 'flash.game_format_created');
    }

    public function update(Request $request, GameFormat $gameFormat): RedirectResponse
    {
        $data = $this->validated($request);
        // Teams, competitions and matches using the format belong to its sport.
        if ($gameFormat->inUse()) {
            $errors = [];
            if ((int) ($data['branch_id'] ?? 0) !== (int) $gameFormat->branch_id) {
                $errors['branch_id'] = 'comp.error.format_in_use_sport';
            }
            if ($data['sport'] !== $gameFormat->sport->value) {
                $errors['sport'] = 'comp.error.format_in_use_sport';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }
        $gameFormat->update($data);

        return back()->with('success', 'flash.game_format_updated');
    }

    public function destroy(GameFormat $gameFormat): RedirectResponse
    {
        if ($gameFormat->inUse()) {
            return back()->with('error', 'flash.game_format_in_use');
        }
        $gameFormat->delete();

        return back()->with('success', 'flash.game_format_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'sport' => ['required', Rule::enum(Sport::class)],
            'name' => ['required', 'string', 'max:100'],
            'name_ar' => ['nullable', 'string', 'max:100'],
            'name_fr' => ['nullable', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'players_on_field' => ['required', 'integer', 'min:1', 'max:30'],
            'max_bench' => ['required', 'integer', 'min:0', 'max:30'],
            'max_called_up' => ['required', 'integer', 'max:60', 'gte:players_on_field'],
            'max_substitutions' => ['nullable', 'integer', 'min:1', 'max:30'],
            'periods_count' => ['required', 'integer', 'min:1', 'max:9'],
            'period_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'period_label' => ['required', Rule::enum(PeriodLabel::class)],
            'scoring' => ['required', Rule::enum(ScoringType::class)],
            'sets_to_win' => ['nullable', 'required_if:scoring,sets', 'integer', 'min:1', 'max:5'],
            'has_extra_time' => ['sometimes', 'boolean'],
            'extra_time_minutes' => ['nullable', 'integer', 'min:0', 'max:60'],
            'has_shootout' => ['sometimes', 'boolean'],
            'rolling_subs' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'sanction_types' => ['present', 'array', 'max:10'],
            'sanction_types.*.key' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'sanction_types.*.ends_play' => ['sometimes', 'boolean'],
            'sanction_types.*.counts_as' => ['nullable', Rule::in(['yellow', 'red'])],
        ]);

        foreach (['has_extra_time', 'has_shootout', 'rolling_subs', 'active'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        $data['extra_time_minutes'] = $data['has_extra_time'] ? (int) ($data['extra_time_minutes'] ?? 0) : 0;
        $data['sanction_types'] = array_map(fn (array $s) => [
            'key' => $s['key'],
            'ends_play' => (bool) ($s['ends_play'] ?? false),
            'counts_as' => $s['counts_as'] ?? null,
        ], $data['sanction_types']);
        if ($data['scoring'] !== ScoringType::Sets->value) {
            $data['sets_to_win'] = null;
        }

        return $data;
    }
}
