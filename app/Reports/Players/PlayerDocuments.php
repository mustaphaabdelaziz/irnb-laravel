<?php

namespace App\Reports\Players;

use App\Models\BranchRegistration;
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
 * One line per document a registration still owes: missing, expired or expiring
 * within 30 days. A left registration owes nothing; a player with no registration
 * owes on their own. Every state comes from DocumentChecklist::evaluate(), the
 * rule set the player page and the players list already use.
 */
class PlayerDocuments extends Report
{
    use FiltersPlayers;

    private const STATES = [DocumentChecklist::MISSING, DocumentChecklist::INCOMPLETE, DocumentChecklist::EXPIRED, DocumentChecklist::EXPIRES_SOON];

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
            new Column('branch', 'branch'),
            new Column('phone', 'col.phone'),
            new Column('document', 'reports.col.document'),
            new Column('state', 'col.status'),
            new Column('valid_until', 'reports.col.valid_until', Column::DATE),
        ];
    }

    public function sortable(): array
    {
        return ['name' => 'name', 'document' => 'document', 'state' => 'state', 'valid_until' => 'valid_until', 'category' => 'category', 'branch' => 'branch'];
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
            ['label' => 'doc_state_incomplete', 'value' => $rows->where('state_code', DocumentChecklist::INCOMPLETE)->count(), 'format' => 'number'],
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
        $branches = $filters->filled('branch_id') ? array_map('intval', $filters->get('branch_id')) : null;
        $categories = $filters->filled('category_id') ? array_map('intval', $filters->get('category_id')) : null;
        $left = Player::leftStatusId();

        $ticked = DocumentType::idsByBranch();
        $query = Player::query()->where('archived', false)
            ->with(['category', 'documents.files', 'registrations.branch', 'registrations.category'])
            ->registeredWhere(branches: $branches, categories: $categories);

        $rows = collect();
        foreach ($query->lazy(200) as $player) {
            // Only the registrations the filters speak of, and none the player left.
            $lines = $player->registrations->isEmpty()
                ? collect([null])
                : $player->registrations
                    ->reject(fn (BranchRegistration $r) => $left !== null && (int) $r->status_id === $left)
                    ->filter(fn (BranchRegistration $r) => ($branches === null || in_array((int) $r->branch_id, $branches, true))
                        && ($categories === null || in_array((int) $r->category_id, $categories, true)))
                    ->sortBy('id');

            foreach ($lines as $registration) {
                $documents = $player->documents
                    ->where('branch_registration_id', $registration?->id)
                    ->keyBy('document_type_id');
                foreach ($types as $type) {
                    // A branch owes only the types it ticks; a player with no registration owes them all.
                    if ($registration !== null && ! in_array($type->id, $ticked[(int) $registration->branch_id] ?? [], true)) {
                        continue;
                    }
                    $document = $documents->get($type->id);
                    $result = DocumentChecklist::evaluate($type, $document, $player);
                    // A missing document only counts when the club expects it (as on the player page).
                    $owed = $result['state'] === DocumentChecklist::MISSING ? $result['counts_missing'] : true;
                    if (! $owed || ! in_array($result['state'], $states, true)) {
                        continue;
                    }
                    $rows->push([
                        'player_id' => $player->id,
                        'membership_id' => $registration === null ? $player->membership_id : $registration->membership_id,
                        'name' => $player->fullname,
                        'category' => ($registration === null ? $player->category : $registration->category)?->localized_name,
                        'branch' => $registration?->branch?->localized_name,
                        'phone' => collect($player->phones ?? [])->filter()->first(),
                        'document' => $type->localized_name,
                        'state' => UiLang::get('doc_state_'.$result['state'])
                            .($result['state'] === DocumentChecklist::INCOMPLETE ? " ({$document->copies_received} / {$type->copies})" : ''),
                        'state_code' => $result['state'],
                        'valid_until' => $document?->valid_until?->format('Y-m-d'),
                    ]);
                }
            }
        }

        return $rows;
    }
}
