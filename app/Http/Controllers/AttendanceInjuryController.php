<?php

namespace App\Http\Controllers;

use App\Models\InjuryNote;
use App\Models\Player;
use App\Services\Attendance\InjurySpells;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

        $note = InjuryNote::firstOrNew(['player_id' => $player->id, 'start_date' => $data['start_date']]);
        $note->fill(self::details($data));
        if (! $note->exists) {
            $note->created_by = $request->user()?->id;
        }
        $note->save();

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
