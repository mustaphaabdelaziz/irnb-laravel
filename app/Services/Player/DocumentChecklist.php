<?php

namespace App\Services\Player;

use App\Models\BranchRegistration;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which documents a player's registration has, lacks, or no longer needs. A
 * player with no registration holds them alone. A left registration owes nothing.
 *
 * Two forms of ONE rule set:
 *  - for() / evaluate(): the per-document states the player page shows;
 *  - missingCountSql() / expiringSoonSql(): the same rules as SQL, so the
 *    players list can count and filter over every player without loading
 *    their documents into PHP.
 *  - requiredTypeSql() / expiringTypeSql() / missingRowsSql(): per-type counts,
 *    summed by DocumentStats into completion figures. They count rows: one per
 *    active registration (only that branch's when given), or one for a player
 *    with no registration at all. missingTypeSql() says 1/0 for "any of them".
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

    /** Not left. A subquery, so it costs no extra lookup. */
    private const ACTIVE_ROW = "(r.status_id IS NULL OR r.status_id NOT IN (SELECT id FROM player_statuses WHERE code = 'left'))";

    /**
     * One registration's checklist (the main one when none is named; the player
     * alone when they have none): every active type, plus any inactive type it
     * still has a record for (shown greyed, never counted).
     *
     * @return array{registration_id: ?int, left: bool, items: list<array<string, mixed>>, missing_count: int, expiring_count: int}
     */
    public static function for(Player $player, ?CarbonInterface $today = null, ?BranchRegistration $registration = null): array
    {
        $today = self::today($today);
        $registration ??= app(RegistrationService::class)->primary($player);
        $left = $registration !== null && Player::isLeftStatus($registration->status_id);

        $documents = $player->documents()
            ->where('branch_registration_id', $registration?->id)
            ->with(['files.uploadedBy:id,name', 'recordedBy:id,name'])
            ->get()
            ->keyBy('document_type_id');

        $types = DocumentType::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $documents->keys()->all()))
            ->ordered()
            ->get();

        $items = $types
            ->map(fn (DocumentType $type) => self::item($type, $documents->get($type->id), $player, $today, $left))
            ->values()
            ->all();

        return [
            'registration_id' => $registration?->id,
            'left' => $left,
            'items' => $items,
            'missing_count' => count(array_filter($items, fn (array $item) => $item['counts_missing'])),
            'expiring_count' => $left ? 0 : count(array_filter(
                $items,
                fn (array $item) => $item['state'] === self::EXPIRES_SOON && $item['type']['is_active']
            )),
        ];
    }

    /**
     * What the player page needs: the main registration's checklist, plus one tab
     * per registration, main first. A player with no registration has no tabs.
     *
     * @return array<string, mixed>
     */
    public static function forPlayer(Player $player, ?CarbonInterface $today = null): array
    {
        $main = app(RegistrationService::class)->primary($player);

        $tabs = $player->registrations()->with('branch')->orderBy('id')->get()
            ->sortBy(fn (BranchRegistration $r) => $r->id === $main?->id ? 0 : 1)
            ->values()
            ->map(fn (BranchRegistration $r) => [
                'branch_id' => $r->branch_id,
                'branch_name' => $r->branch?->localized_name,
                'logo_url' => $r->branch?->logo_url,
                ...self::for($player, $today, $r),
            ])
            ->all();

        return [...($tabs[0] ?? self::for($player, $today)), 'tabs' => $tabs];
    }

    /**
     * The state of one type for one player. See the rule table in the P3 plan
     * (Task 6); the order of the checks below IS that table. On a left
     * registration nothing is expected, so nothing counts as missing.
     *
     * @return array{state: string, reason: ?string, counts_missing: bool}
     */
    public static function evaluate(DocumentType $type, ?PlayerDocument $document, Player $player, ?CarbonInterface $today = null, bool $left = false): array
    {
        $today = self::today($today);
        $applies = $type->appliesTo($player, $today);
        $expected = ! $left && $type->is_active && $type->is_required && $applies;

        // Owner decision: the profile picture counts as the Photo, on every
        // registration, whatever its record says. Without a picture the Photo is
        // a document like any other, so a paper photo can be marked received by hand.
        if ($type->isPhoto()) {
            $picture = $player->getAttributes()['picture_url'] ?? null;

            if ($picture !== null && $picture !== '') {
                return self::result(self::RECEIVED_SCANNED);
            }
        }

        if ($document === null) {
            return $left ? self::result(self::NOT_REQUIRED, 'left') : self::absent($applies, $expected);
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
     * `players`: for()['missing_count'] added up over their active registrations,
     * or for that one branch's. With $onlyTypeId it counts that single type (the
     * "missing type X" filter).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function missingCountSql(?CarbonInterface $today = null, ?int $onlyTypeId = null, ?int $branchId = null): array
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
            [$term, $termBindings] = self::missingRowsSql($type, $today, $branchId);
            $terms[] = $term;
            array_push($bindings, ...$termBindings);
        }

        return ['('.implode(' + ', $terms).')', $bindings];
    }

    /**
     * One document type's "missing" test as an SQL expression over `players`
     * (1 = missing on an active registration, or on that branch's; 0 = settled),
     * for lists that need to know WHICH types are missing (match eligibility).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function missingTypeSql(DocumentType $type, ?CarbonInterface $today = null, ?int $branchId = null): array
    {
        [$rows, $bindings] = self::missingRowsSql($type, $today, $branchId);

        return ["(CASE WHEN {$rows} > 0 THEN 1 ELSE 0 END)", $bindings];
    }

    /**
     * How many of the player's rows lack this type: the term missingCountSql() sums.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function missingRowsSql(DocumentType $type, ?CarbonInterface $today = null, ?int $branchId = null, bool $joinedRow = false): array
    {
        $today = self::today($today);
        [$conditions, $bindings] = self::ageConditions($type, $today);

        if ($type->isPhoto()) {
            $conditions[] = "(players.picture_url IS NULL OR players.picture_url = '')";
        }

        $unsettled = 'NOT EXISTS (SELECT 1 FROM player_documents pd'
            .' WHERE {owner} AND pd.document_type_id = ?'
            ." AND (pd.state = 'exempt' OR (pd.state = 'received'"
            .' AND (pd.valid_until IS NULL OR pd.valid_until >= ?))))';

        return self::whenPlayer($conditions, $bindings, self::rowsSql($unsettled, [$type->id, $today->toDateString()], $branchId, $joinedRow));
    }

    /**
     * "Has an active document that expires within 30 days", as an SQL condition
     * over `players`: on an active registration, or on that branch's. The twin of
     * for()['expiring_count'] > 0.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function expiringSoonSql(?CarbonInterface $today = null, ?int $branchId = null): array
    {
        $today = self::today($today);

        return [
            'EXISTS (SELECT 1 FROM player_documents pd'
                .' INNER JOIN document_types dt ON dt.id = pd.document_type_id'
                .' WHERE pd.player_id = players.id AND dt.is_active IS TRUE'
                // A profile picture settles the Photo, so its record cannot expire.
                ." AND (dt.code <> ? OR players.picture_url IS NULL OR players.picture_url = '')"
                ." AND pd.state = 'received' AND pd.valid_until >= ? AND pd.valid_until < ?"
                .' AND (pd.branch_registration_id IS NULL OR pd.branch_registration_id IN (SELECT r.id FROM branch_player r'
                .' WHERE r.player_id = players.id AND '.self::ACTIVE_ROW
                .($branchId !== null ? ' AND r.branch_id = ?' : '')
                .')))',
            [
                DocumentType::PHOTO,
                $today->toDateString(),
                $today->addDays(self::EXPIRES_SOON_DAYS + 1)->toDateString(),
                ...($branchId !== null ? [$branchId] : []),
            ],
        ];
    }

    /**
     * One type's "required" test as a count of the player's rows: the twin of
     * evaluate(): active, required, applies to the player's age, and not exempted.
     * A profile picture settles the Photo whatever its record says, so a picture
     * beats an exempt record (the age limit still applies). Inactive and optional
     * types are never required.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function requiredTypeSql(DocumentType $type, ?CarbonInterface $today = null, ?int $branchId = null, bool $joinedRow = false): array
    {
        if (! $type->is_active || ! $type->is_required) {
            return ['0', []];
        }

        [$conditions, $bindings] = self::ageConditions($type, self::today($today));

        $notExempt = 'NOT EXISTS (SELECT 1 FROM player_documents pd'
            .' WHERE {owner} AND pd.document_type_id = ?'
            ." AND pd.state = 'exempt')";
        $test = $type->isPhoto()
            ? "((players.picture_url IS NOT NULL AND players.picture_url <> '') OR {$notExempt})"
            : $notExempt;

        return self::whenPlayer($conditions, $bindings, self::rowsSql($test, [$type->id], $branchId, $joinedRow));
    }

    /**
     * One type's "required and expires within 30 days" test as a count of the
     * player's rows: the twin of evaluate() returning EXPIRES_SOON for a required
     * document. A received record valid today is never missing, and one record per
     * registration and type means it is not exempt either. A Photo settled by a
     * picture never expires.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function expiringTypeSql(DocumentType $type, ?CarbonInterface $today = null, ?int $branchId = null, bool $joinedRow = false): array
    {
        if (! $type->is_active || ! $type->is_required) {
            return ['0', []];
        }

        $today = self::today($today);
        [$conditions, $bindings] = self::ageConditions($type, $today);

        if ($type->isPhoto()) {
            $conditions[] = "(players.picture_url IS NULL OR players.picture_url = '')";
        }

        $expiring = 'EXISTS (SELECT 1 FROM player_documents pd'
            .' WHERE {owner} AND pd.document_type_id = ?'
            ." AND pd.state = 'received' AND pd.valid_until >= ? AND pd.valid_until < ?)";

        return self::whenPlayer($conditions, $bindings, self::rowsSql($expiring, [
            $type->id,
            $today->toDateString(),
            $today->addDays(self::EXPIRES_SOON_DAYS + 1)->toDateString(),
        ], $branchId, $joinedRow));
    }

    /**
     * How many of a player's rows a test holds on: one per active registration
     * (only that branch's when given), or one for a player with no registration
     * at all. {owner} in the test names the documents of the row being counted.
     * With $joinedRow only the registration joined as `reg` counts, for a query
     * with one line per registration.
     *
     * @param  list<mixed>  $bindings  the test's bindings
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function rowsSql(string $test, array $bindings, ?int $branchId, bool $joinedRow): array
    {
        $registered = '(SELECT COUNT(*) FROM branch_player r WHERE r.player_id = players.id AND '.self::ACTIVE_ROW
            .($branchId !== null ? ' AND r.branch_id = ?' : '')
            .($joinedRow ? ' AND r.id = reg.id' : '')
            .' AND '.str_replace('{owner}', 'pd.branch_registration_id = r.id', $test).')';

        $alone = '(CASE WHEN NOT EXISTS (SELECT 1 FROM branch_player r0 WHERE r0.player_id = players.id) AND '
            .str_replace('{owner}', 'pd.player_id = players.id AND pd.branch_registration_id IS NULL', $test)
            .' THEN 1 ELSE 0 END)';

        return ["({$registered} + {$alone})", [...($branchId !== null ? [$branchId] : []), ...$bindings, ...$bindings]];
    }

    /**
     * The rows' count, for a player who meets conditions on the player alone.
     *
     * @param  list<string>  $conditions
     * @param  list<mixed>  $bindings
     * @param  array{0: string, 1: array<int, mixed>}  $rows
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function whenPlayer(array $conditions, array $bindings, array $rows): array
    {
        if ($conditions === []) {
            return $rows;
        }

        return ['(CASE WHEN '.implode(' AND ', $conditions).' THEN '.$rows[0].' ELSE 0 END)', [...$bindings, ...$rows[1]]];
    }

    /**
     * The age test of the terms (DocumentType::earliestApplicableBirthdate), no
     * birth date = not limited.
     *
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private static function ageConditions(DocumentType $type, CarbonImmutable $today): array
    {
        $earliest = $type->earliestApplicableBirthdate($today);

        if ($earliest === null) {
            return [[], []];
        }

        return [['(players.birthdate IS NULL OR players.birthdate >= ?)'], [$earliest->toDateString()]];
    }

    /** @return array<string, mixed> */
    private static function item(DocumentType $type, ?PlayerDocument $document, Player $player, CarbonImmutable $today, bool $left = false): array
    {
        return [
            'type' => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->localized_name,
                'is_required' => $type->is_required,
                'validity' => $type->validity,
                'max_age' => $type->max_age,
                'copies' => $type->copies,
                'is_active' => $type->is_active,
                'is_photo' => $type->isPhoto(),
            ],
            ...self::evaluate($type, $document, $player, $today, $left),
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
