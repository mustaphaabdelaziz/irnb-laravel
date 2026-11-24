<?php

namespace App\Services\Player;

use App\Models\BranchRegistration;
use App\Models\DocumentType;
use App\Models\Player;
use App\Support\UiLang;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The documents each player still owes, by name, for the printed sheets (the
 * players list's "missing documents" print and the Reports center report).
 *
 * Same rules as the player page: each state comes from DocumentChecklist::evaluate()
 * and only what it counts as missing is listed (never received, expired, or short
 * of copies). Each active registration owes the types its branch ticks; a player
 * with no registration owes every active type; a left registration owes nothing.
 * With branches or categories named, only those registrations count.
 */
final class MissingDocuments
{
    /** The relations of() reads; load them on the players first. */
    public const WITH = ['documents.files', 'registrations.branch'];

    private CarbonImmutable $today;

    /** @var Collection<int, DocumentType> active types, in their order */
    private Collection $types;

    /** @var array<int, list<int>> branch id => ticked type ids */
    private array $ticked;

    private ?int $left;

    public function __construct(?CarbonInterface $today = null)
    {
        $this->today = CarbonImmutable::parse($today ?? CarbonImmutable::today())->startOfDay();
        $this->types = DocumentType::query()->where('is_active', true)->ordered()->get();
        $this->ticked = DocumentType::idsByBranch();
        $this->left = Player::leftStatusId();
    }

    /** @return Collection<int, DocumentType> */
    public function types(): Collection
    {
        return $this->types;
    }

    /**
     * What one player still owes: one group per registration that owes something
     * (or the player alone, with no registration), main registration first.
     *
     * @param  list<int>|null  $branchIds  only these branches' registrations
     * @param  list<int>|null  $categoryIds  only registrations in these categories
     * @return list<array{branch: ?string, items: list<array{type_id: int, label: string}>}>
     */
    public function of(Player $player, ?array $branchIds = null, ?array $categoryIds = null): array
    {
        $lines = $player->registrations->isEmpty()
            ? collect([null])
            : $player->registrations
                ->reject(fn (BranchRegistration $r) => $this->left !== null && (int) $r->status_id === $this->left)
                ->filter(fn (BranchRegistration $r) => ($branchIds === null || in_array((int) $r->branch_id, $branchIds, true))
                    && ($categoryIds === null || in_array((int) $r->category_id, $categoryIds, true)))
                ->sortBy('id');

        $groups = [];
        foreach ($lines as $registration) {
            $documents = $player->documents
                ->where('branch_registration_id', $registration?->id)
                ->keyBy('document_type_id');
            $items = [];

            foreach ($this->types as $type) {
                if ($registration !== null && ! in_array($type->id, $this->ticked[(int) $registration->branch_id] ?? [], true)) {
                    continue;
                }

                $document = $documents->get($type->id);
                $result = DocumentChecklist::evaluate($type, $document, $player, $this->today);
                if (! $result['counts_missing']) {
                    continue;
                }

                $items[] = ['type_id' => $type->id, 'label' => $type->localized_name.match ($result['state']) {
                    DocumentChecklist::EXPIRED => ' ('.UiLang::get('doc_state_expired').')',
                    DocumentChecklist::INCOMPLETE => " ({$document->copies_received}/{$type->copies})",
                    default => '',
                }];
            }

            if ($items !== []) {
                $groups[] = ['branch' => $registration?->branch?->localized_name, 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * The groups as lines of text: the names joined by «،» (by ", " outside
     * Arabic), each line led by its branch when the player owes in several.
     *
     * @param  list<array{branch: ?string, items: list<array{type_id: int, label: string}>}>  $groups
     * @return list<string>
     */
    public static function lines(array $groups): array
    {
        $comma = app()->getLocale() === 'ar' ? '، ' : ', ';

        return array_map(function (array $group) use ($groups, $comma) {
            $names = implode($comma, array_column($group['items'], 'label'));

            return count($groups) > 1 && $group['branch'] !== null ? $group['branch'].': '.$names : $names;
        }, $groups);
    }

    /**
     * The figures over a sheet: players listed, complete, incomplete, % complete,
     * and how many players miss each type (types nobody misses left out).
     *
     * @param  iterable<list<array{branch: ?string, items: list<array{type_id: int, label: string}>}>>  $sheet  one entry per player
     * @return array{total: int, complete: int, incomplete: int, percent: float, per_type: list<array{name: string, count: int}>}
     */
    public function figures(iterable $sheet): array
    {
        $total = 0;
        $incomplete = 0;
        $perType = [];

        foreach ($sheet as $groups) {
            $total++;
            if ($groups === []) {
                continue;
            }
            $incomplete++;
            $typeIds = array_unique(array_merge(...array_map(fn (array $g) => array_column($g['items'], 'type_id'), $groups)));
            foreach ($typeIds as $id) {
                $perType[$id] = ($perType[$id] ?? 0) + 1;
            }
        }

        return [
            'total' => $total,
            'complete' => $total - $incomplete,
            'incomplete' => $incomplete,
            'percent' => $total === 0 ? 0.0 : round(($total - $incomplete) * 100 / $total, 1),
            'per_type' => $this->types
                ->filter(fn (DocumentType $type) => isset($perType[$type->id]))
                ->map(fn (DocumentType $type) => ['name' => $type->localized_name, 'count' => $perType[$type->id]])
                ->values()->all(),
        ];
    }
}
