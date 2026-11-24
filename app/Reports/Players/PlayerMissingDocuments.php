<?php

namespace App\Reports\Players;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Reports\Column;
use App\Reports\Concerns\FiltersPlayers;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Player\MissingDocuments;
use App\Services\Player\RegistrationDisplay;
use App\Support\UiLang;
use Illuminate\Support\Collection;

/**
 * One line per player with the documents they still owe, by name: the same
 * sheet the players list prints (MissingDocuments). Complete players are listed
 * too unless the completeness filter leaves them out; a document type filter
 * keeps the players missing one of those types.
 */
class PlayerMissingDocuments extends Report
{
    use FiltersPlayers;

    /** @var array<string, array{rows: Collection<int, array<string, mixed>>, figures: array<string, mixed>}> per filter set */
    private array $memo = [];

    public function key(): string
    {
        return 'missing_documents';
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
            $this->categoryFilter(),
            $this->branchFilter(),
            Filter::multi('status_id', 'col.status', array_map(
                fn (array $s) => ['value' => $s['id'], 'label' => $s['name']], PlayerStatus::options())),
            Filter::select('completeness', 'reports.filter.completeness', [
                ['value' => 'incomplete', 'label' => UiLang::get('missing_docs.incomplete_players')],
                ['value' => 'complete', 'label' => UiLang::get('missing_docs.complete_players')],
            ]),
            Filter::multi('document_type_id', 'reports.filter.document_type', DocumentType::query()->where('is_active', true)->ordered()->get()
                ->map(fn (DocumentType $t) => ['value' => $t->id, 'label' => $t->localized_name])->all()),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('membership_id', 'col.membership_id'),
            new Column('name', 'col.full_name'),
            new Column('category', 'col.category'),
            new Column('missing', 'missing_docs.title'),
            new Column('missing_count', 'reports.col.missing_count', Column::NUMBER),
        ];
    }

    public function sortable(): array
    {
        return ['name' => 'name', 'category' => 'category', 'missing_count' => 'missing_count'];
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function rows(ReportFilters $filters): Collection
    {
        return $this->sheet($filters)['rows'];
    }

    public function summary(ReportFilters $filters): array
    {
        $figures = $this->sheet($filters)['figures'];

        return [
            ['label' => 'total_players', 'value' => $figures['total'], 'format' => 'number'],
            ['label' => 'missing_docs.complete_players', 'value' => $figures['complete'], 'format' => 'number'],
            ['label' => 'missing_docs.incomplete_players', 'value' => $figures['incomplete'], 'format' => 'number'],
            ['label' => 'missing_docs.complete_rate', 'value' => $figures['percent'], 'format' => 'percent'],
        ];
    }

    /** How many listed players miss each type. */
    public function charts(ReportFilters $filters): array
    {
        return [
            ['type' => 'bar', 'title' => 'missing_docs.per_type', 'unit' => 'reports.col.document', 'stats' => array_map(
                fn (array $type) => ['key' => $type['name'], 'label' => $type['name'], 'count' => $type['count']],
                $this->sheet($filters)['figures']['per_type'],
            )],
        ];
    }

    /** @return array{rows: Collection<int, array<string, mixed>>, figures: array<string, mixed>} */
    private function sheet(ReportFilters $filters): array
    {
        return $this->memo[serialize($filters->values())] ??= $this->build($filters);
    }

    /** @return array{rows: Collection<int, array<string, mixed>>, figures: array<string, mixed>} */
    private function build(ReportFilters $filters): array
    {
        $listed = $this->listFilters($filters);
        $branches = $listed['branches'] === null ? null : array_map('intval', $listed['branches']);
        $categories = $listed['categories'] === null ? null : array_map('intval', $listed['categories']);
        $statuses = $filters->filled('status_id') ? array_map('intval', (array) $filters->get('status_id')) : null;
        $types = $filters->filled('document_type_id') ? array_map('intval', (array) $filters->get('document_type_id')) : null;
        $shown = $this->shownBranch($filters);
        $display = RegistrationDisplay::make();

        // The players list's population: left people only when a status asks for them.
        $query = Player::query()->where('archived', false)
            ->with(['category', ...MissingDocuments::WITH, 'registrations.category'])
            ->listedWhere(branches: $branches, categories: $categories, statuses: $statuses, hideLeft: $statuses === null);

        $missing = new MissingDocuments;
        $sheet = collect();
        foreach ($query->lazy(200) as $player) {
            $groups = $missing->of($player, $branches, $categories);
            $owed = array_merge(...array_map(fn (array $g) => array_column($g['items'], 'type_id'), $groups));

            if ($types !== null && array_intersect($types, $owed) === []) {
                continue;
            }
            if ($filters->get('completeness') === 'complete' && $groups !== []
                || $filters->get('completeness') === 'incomplete' && $groups === []) {
                continue;
            }

            $display->overlay($player, $shown);
            $sheet->push([
                'groups' => $groups,
                'row' => [
                    'membership_id' => $player->membership_id,
                    'name' => $player->fullname,
                    'category' => $player->category?->localized_name,
                    'missing' => $groups === [] ? UiLang::get('doc_complete') : implode(' | ', MissingDocuments::lines($groups)),
                    'missing_count' => count($owed),
                ],
            ]);
        }

        return ['rows' => $sheet->pluck('row'), 'figures' => $missing->figures($sheet->pluck('groups'))];
    }
}
