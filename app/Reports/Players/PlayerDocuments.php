<?php

namespace App\Reports\Players;

use App\Models\DocumentType;
use App\Models\Player;
use App\Reports\Column;
use App\Reports\Concerns\FiltersPlayers;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Player\DocumentChecklist;
use App\Support\UiLang;
use Illuminate\Support\Collection;

/**
 * One line per document a player still owes: missing, expired or expiring
 * within 30 days. Every state comes from DocumentChecklist::evaluate(), the
 * rule set the player page and the players list already use.
 */
class PlayerDocuments extends Report
{
    use FiltersPlayers;

    private const STATES = [DocumentChecklist::MISSING, DocumentChecklist::EXPIRED, DocumentChecklist::EXPIRES_SOON];

    /** @var array<string, Collection<int, array<string, mixed>>> per filter set, so summary/charts reuse one pass */
    private array $memo = [];

    public function key(): string
    {
        return 'documents';
    }

    public function category(): string
    {
        return 'players';
    }

    /** Documents have their own right (medical certificates, ID copies). */
    public function module(): string
    {
        return 'documents';
    }

    public function icon(): string
    {
        return 'document';
    }

    public function filters(): array
    {
        return [
            Filter::multi('document_type_id', 'reports.filter.document_type', DocumentType::query()->where('is_active', true)->ordered()->get()
                ->map(fn (DocumentType $t) => ['value' => $t->id, 'label' => $t->localized_name])->all()),
            Filter::multi('state', 'reports.filter.document_state', array_map(
                fn (string $s) => ['value' => $s, 'label' => UiLang::get('doc_state_'.$s)], self::STATES)),
            $this->categoryFilter(),
            $this->branchFilter(),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('membership_id', 'col.membership_id'),
            new Column('name', 'col.full_name'),
            new Column('category', 'col.category'),
            new Column('phone', 'col.phone'),
            new Column('document', 'reports.col.document'),
            new Column('state', 'col.status'),
            new Column('valid_until', 'reports.col.valid_until', Column::DATE),
        ];
    }

    public function sortable(): array
    {
        return ['name' => 'name', 'document' => 'document', 'state' => 'state', 'valid_until' => 'valid_until', 'category' => 'category'];
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function rows(ReportFilters $filters): Collection
    {
        $key = serialize($filters->values());

        return $this->memo[$key] ??= $this->evaluate($filters);
    }

    public function summary(ReportFilters $filters): array
    {
        $rows = $this->rows($filters);

        return [
            ['label' => 'reports.kpi.players_concerned', 'value' => $rows->pluck('player_id')->unique()->count(), 'format' => 'number'],
            ['label' => 'doc_state_missing', 'value' => $rows->where('state_code', DocumentChecklist::MISSING)->count(), 'format' => 'number'],
            ['label' => 'doc_state_expired', 'value' => $rows->where('state_code', DocumentChecklist::EXPIRED)->count(), 'format' => 'number'],
            ['label' => 'doc_state_expires_soon', 'value' => $rows->where('state_code', DocumentChecklist::EXPIRES_SOON)->count(), 'format' => 'number'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        return [
            ['type' => 'bar', 'title' => 'reports.chart.by_document', 'unit' => 'reports.col.document', 'stats' => $this->rows($filters)
                ->groupBy('document')->map(fn (Collection $g, string $doc) => ['key' => $doc, 'label' => $doc, 'count' => $g->count()])
                ->sortByDesc('count')->values()->all()],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function evaluate(ReportFilters $filters): Collection
    {
        $types = DocumentType::query()->where('is_active', true)->ordered()
            ->when($filters->filled('document_type_id'), fn ($q) => $q->whereIn('id', $filters->get('document_type_id')))
            ->get();
        $states = $filters->filled('state') ? $filters->get('state') : self::STATES;

        $query = Player::query()->where('archived', false)->with(['category', 'documents.files']);
        $this->applyCategoryBranch($query, $filters);

        $rows = collect();
        foreach ($query->lazy(200) as $player) {
            $documents = $player->documents->keyBy('document_type_id');
            foreach ($types as $type) {
                $document = $documents->get($type->id);
                $result = DocumentChecklist::evaluate($type, $document, $player);
                // A missing document only counts when the club expects it (as on the player page).
                $owed = $result['state'] === DocumentChecklist::MISSING ? $result['counts_missing'] : true;
                if (! $owed || ! in_array($result['state'], $states, true)) {
                    continue;
                }
                $rows->push([
                    'player_id' => $player->id,
                    'membership_id' => $player->membership_id,
                    'name' => $player->fullname,
                    'category' => $player->category?->localized_name,
                    'phone' => collect($player->phones ?? [])->filter()->first(),
                    'document' => $type->localized_name,
                    'state' => UiLang::get('doc_state_'.$result['state']),
                    'state_code' => $result['state'],
                    'valid_until' => $document?->valid_until?->format('Y-m-d'),
                ]);
            }
        }

        return $rows;
    }
}
