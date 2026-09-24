<?php

namespace App\Services\Player;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which documents a player has, lacks, or no longer needs.
 *
 * Two forms of ONE rule set:
 *  - for() / evaluate(): the per-document states the player page shows;
 *  - missingCountSql() / expiringSoonSql(): the same rules as SQL, so the
 *    players list can count and filter over every player without loading
 *    their documents into PHP.
 *
 * DocumentChecklistTest runs both over the same fixtures and asserts they
 * agree. Change one, change the other, and keep that test green.
 *
 * Dates: every SQL comparison is half-open against a Y-m-d string (>= / <),
 * because SQLite keeps these columns as "Y-m-d 00:00:00" text and a closed
 * comparison (<=) would miss the boundary day. The PHP side uses the same
 * boundaries: expired = valid_until < today; expires soon = valid_until <
 * today + 31 days; applies = birthdate >= DocumentType::earliestApplicableBirthdate().
 */
final class DocumentChecklist
{
    public const RECEIVED_SCANNED = 'received_scanned';

    public const RECEIVED_PAPER = 'received_paper';

    public const EXPIRES_SOON = 'expires_soon';

    public const EXPIRED = 'expired';

    public const MISSING = 'missing';

    public const NOT_REQUIRED = 'not_required';

    public const EXPIRES_SOON_DAYS = 30;

    /**
     * The player page's checklist: every active type, plus any inactive type
     * the player still has a record for (shown greyed, never counted).
     *
     * @return array{items: list<array<string, mixed>>, missing_count: int, expiring_count: int}
     */
    public static function for(Player $player, ?CarbonInterface $today = null): array
    {
        $today = self::today($today);

        $documents = $player->documents()
            ->with(['files.uploadedBy:id,name', 'recordedBy:id,name'])
            ->get()
            ->keyBy('document_type_id');

        $types = DocumentType::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $documents->keys()->all()))
            ->ordered()
            ->get();

        $items = $types
            ->map(fn (DocumentType $type) => self::item($type, $documents->get($type->id), $player, $today))
            ->values()
            ->all();

        return [
            'items' => $items,
            'missing_count' => count(array_filter($items, fn (array $item) => $item['counts_missing'])),
            'expiring_count' => count(array_filter(
                $items,
                fn (array $item) => $item['state'] === self::EXPIRES_SOON && $item['type']['is_active']
            )),
        ];
    }

    /**
     * The state of one type for one player. See the rule table in the P3 plan
     * (Task 6); the order of the checks below IS that table.
     *
     * @return array{state: string, reason: ?string, counts_missing: bool}
     */
    public static function evaluate(DocumentType $type, ?PlayerDocument $document, Player $player, ?CarbonInterface $today = null): array
    {
        $today = self::today($today);
        $applies = $type->appliesTo($player, $today);
        $expected = $type->is_active && $type->is_required && $applies;

        // Owner decision: the Photo is the profile picture. A received row for it
        // is ignored — the picture is the only thing that makes it "received".
        if ($type->isPhoto()) {
            $picture = $player->getAttributes()['picture_url'] ?? null;

            if ($picture !== null && $picture !== '') {
                return self::result(self::RECEIVED_SCANNED);
            }

            if ($document?->state === PlayerDocument::EXEMPT) {
                return self::result(self::NOT_REQUIRED, 'exempt');
            }

            return self::absent($applies, $expected);
        }

        if ($document === null) {
            return self::absent($applies, $expected);
        }

        if ($document->state === PlayerDocument::EXEMPT) {
            return self::result(self::NOT_REQUIRED, 'exempt');
        }

        $validUntil = $document->valid_until;

        if ($validUntil !== null && $validUntil->lt($today)) {
            return self::result(self::EXPIRED, null, $expected);
        }

        if ($validUntil !== null && $validUntil->lt($today->addDays(self::EXPIRES_SOON_DAYS + 1))) {
            return self::result(self::EXPIRES_SOON);
        }

        return self::result($document->files->isNotEmpty() ? self::RECEIVED_SCANNED : self::RECEIVED_PAPER);
    }

    /**
     * How many documents a player is missing, as an SQL expression over
     * `players` — the twin of for()['missing_count']. With $onlyTypeId it is
     * 1/0 for that single type (the "missing type X" filter).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function missingCountSql(?CarbonInterface $today = null, ?int $onlyTypeId = null): array
    {
        $today = self::today($today);

        $types = DocumentType::query()
            ->where('is_active', true)
            ->where('is_required', true)
            ->when($onlyTypeId !== null, fn ($query) => $query->whereKey($onlyTypeId))
            ->orderBy('id')
            ->get();

        if ($types->isEmpty()) {
            return ['0', []];
        }

        $terms = [];
        $bindings = [];

        foreach ($types as $type) {
            [$term, $termBindings] = self::missingTermSql($type, $today);
            $terms[] = $term;
            array_push($bindings, ...$termBindings);
        }

        return ['('.implode(' + ', $terms).')', $bindings];
    }

    /**
     * "Has an active document that expires within 30 days", as an SQL
     * condition over `players` — the twin of for()['expiring_count'] > 0.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function expiringSoonSql(?CarbonInterface $today = null): array
    {
        $today = self::today($today);

        return [
            'EXISTS (SELECT 1 FROM player_documents pd'
                .' INNER JOIN document_types dt ON dt.id = pd.document_type_id'
                .' WHERE pd.player_id = players.id AND dt.is_active = 1 AND dt.code <> ?'
                ." AND pd.state = 'received' AND pd.valid_until >= ? AND pd.valid_until < ?)",
            [
                DocumentType::PHOTO,
                $today->toDateString(),
                $today->addDays(self::EXPIRES_SOON_DAYS + 1)->toDateString(),
            ],
        ];
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private static function missingTermSql(DocumentType $type, CarbonImmutable $today): array
    {
        $conditions = [];
        $bindings = [];

        $earliest = $type->earliestApplicableBirthdate($today);
        if ($earliest !== null) {
            $conditions[] = '(players.birthdate IS NULL OR players.birthdate >= ?)';
            $bindings[] = $earliest->toDateString();
        }

        if ($type->isPhoto()) {
            $conditions[] = "(players.picture_url IS NULL OR players.picture_url = '')";
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM player_documents pd'
                .' WHERE pd.player_id = players.id AND pd.document_type_id = ?'
                ." AND pd.state = 'exempt')";
            $bindings[] = $type->id;
        } else {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM player_documents pd'
                .' WHERE pd.player_id = players.id AND pd.document_type_id = ?'
                ." AND (pd.state = 'exempt' OR (pd.state = 'received'"
                .' AND (pd.valid_until IS NULL OR pd.valid_until >= ?))))';
            $bindings[] = $type->id;
            $bindings[] = $today->toDateString();
        }

        return ['(CASE WHEN '.implode(' AND ', $conditions).' THEN 1 ELSE 0 END)', $bindings];
    }

    /** @return array<string, mixed> */
    private static function item(DocumentType $type, ?PlayerDocument $document, Player $player, CarbonImmutable $today): array
    {
        return [
            'type' => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->localized_name,
                'is_required' => $type->is_required,
                'validity' => $type->validity,
                'max_age' => $type->max_age,
                'is_active' => $type->is_active,
                'is_photo' => $type->isPhoto(),
            ],
            ...self::evaluate($type, $document, $player, $today),
            'document' => $document === null ? null : [
                'id' => $document->id,
                'state' => $document->state,
                'received_at' => $document->received_at?->toDateString(),
                'valid_until' => $document->valid_until?->toDateString(),
                'exempt_reason' => $document->exempt_reason,
                'notes' => $document->notes,
                'recorded_by' => $document->recordedBy?->name,
                'files' => $document->files->map(fn (PlayerDocumentFile $file) => [
                    'id' => $file->id,
                    'original_name' => $file->original_name,
                    'mime' => $file->mime,
                    'size' => $file->size,
                    'uploaded_at' => $file->created_at?->toDateString(),
                    'uploaded_by' => $file->uploadedBy?->name,
                ])->values()->all(),
            ],
        ];
    }

    /** No row (or a Photo without picture): missing if expected, otherwise why not. */
    private static function absent(bool $applies, bool $expected): array
    {
        if ($expected) {
            return self::result(self::MISSING, null, true);
        }

        return self::result(self::NOT_REQUIRED, $applies ? 'optional' : 'age');
    }

    /** @return array{state: string, reason: ?string, counts_missing: bool} */
    private static function result(string $state, ?string $reason = null, bool $countsMissing = false): array
    {
        return ['state' => $state, 'reason' => $reason, 'counts_missing' => $countsMissing];
    }

    private static function today(?CarbonInterface $today): CarbonImmutable
    {
        return CarbonImmutable::parse($today ?? CarbonImmutable::today())->startOfDay();
    }
}
