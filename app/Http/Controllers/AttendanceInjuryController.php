<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InjuryNote;
use App\Models\Player;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\InjurySpells;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as PageResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injury details (injury_notes) for the spells InjurySpells builds from the
 * marks, and (Task 12) the club's injuries list. The detail writes answer
 * JSON: the profile's attendance card calls them and reloads itself.
 */
class AttendanceInjuryController extends Controller
{
    private const DETAIL_RULES = [
        'body_part' => ['nullable', 'string', 'max:60'],
        'description' => ['nullable', 'string', 'max:2000'],
    ];

    public function __construct(private readonly InjurySpells $spells) {}

    /**
     * The club's injuries (`attendance.injuries`, view): who is injured now,
     * and every spell in the period (default: the current season), for
     * active players, optionally those now in one category.
     */
    public function index(Request $request): PageResponse
    {
        $validated = $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $period = ActivityPeriod::fromRequestOrSeason($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return Inertia::render('Attendance/Injuries', [
            'period' => $period->toArray(),
            'categoryId' => $categoryId,
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
                ->values()->all(),
            ...$this->spells->club($from, $to, $categoryId),
        ]);
    }

    /** Adds the details of the spell starting on `start_date`, or replaces them if it has some. */
    public function store(Request $request, Player $player): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            ...self::DETAIL_RULES,
            'returned_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        if (! in_array($data['start_date'], array_column($this->spells->all($player->id), 'start'), true)) {
            throw ValidationException::withMessages(['start_date' => 'att.injury.error.no_spell']);
        }

        $key = ['player_id' => $player->id, 'start_date' => $data['start_date']];
        try {
            $note = InjuryNote::firstOrNew($key);
            $note->fill(self::details($data));
            if (! $note->exists) {
                $note->created_by = $request->user()?->id;
            }
            $note->save();
        } catch (UniqueConstraintViolationException) {
            // Another request added this spell's detail since the lookup: replace it, as saving again does.
            $note = InjuryNote::where($key)->firstOrFail();
            $note->update(self::details($data));
        }

        return response()->json(['note' => $note->toDetail()], $note->wasRecentlyCreated ? 201 : 200);
    }

    /** Edits a detail in place, matched to a spell or not (its start date never changes). */
    public function update(Request $request, InjuryNote $note): JsonResponse
    {
        $data = $request->validate([
            ...self::DETAIL_RULES,
            'returned_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$note->start_date],
        ]);
        $note->update(self::details($data));

        return response()->json(['note' => $note->toDetail()]);
    }

    public function destroy(InjuryNote $note): Response
    {
        $note->delete();

        return response()->noContent();
    }

    /** The three detail fields; a field left out is cleared, as the modal always sends all three. */
    private static function details(array $data): array
    {
        return [
            'body_part' => $data['body_part'] ?? null,
            'description' => $data['description'] ?? null,
            'returned_on' => $data['returned_on'] ?? null,
        ];
    }
}
