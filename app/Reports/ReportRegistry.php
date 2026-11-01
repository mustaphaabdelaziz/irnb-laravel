<?php

namespace App\Reports;

use App\Models\User;
use App\Reports\Attendance\SessionsOverview;
use App\Reports\Competitions\CompetitionAppearances;
use App\Reports\Competitions\CompetitionDiscipline;
use App\Reports\Competitions\CompetitionFixtures;
use App\Reports\Competitions\CompetitionResults;
use App\Reports\Competitions\CompetitionSuspensions;
use App\Reports\Equipment\EquipmentInventory;
use App\Reports\Equipment\EquipmentLifecycle;
use App\Reports\Equipment\EquipmentOut;
use App\Reports\Equipment\EquipmentValuation;
use App\Reports\Equipment\StocktakeLosses;
use App\Reports\Finance\BudgetReport;
use App\Reports\Finance\CashBook;
use App\Reports\Finance\FinanceSummary;
use App\Reports\Finance\OutstandingPayments;
use App\Reports\Finance\SubscriptionCollection;
use App\Reports\Finance\TransactionsJournal;
use App\Reports\Players\PlayerBirthdays;
use App\Reports\Players\PlayerDemographics;
use App\Reports\Players\PlayerDirectory;
use App\Reports\Players\PlayerDocuments;
use App\Reports\Players\PlayerMovement;

/**
 * Everything the Reports center lists: the reports built on the engine, and
 * link cards to the reports that already live on module pages (they keep
 * their own page, route and permission — the hub only points at them).
 */
final class ReportRegistry
{
    public const CATEGORIES = ['players', 'finance', 'equipment', 'competitions', 'attendance'];

    /** Card icon per category (Icon.vue names). */
    public const ICONS = [
        'players' => 'players',
        'finance' => 'money',
        'equipment' => 'equipment',
        'competitions' => 'flag',
        'attendance' => 'calendar',
    ];

    /** @var list<class-string<Report>> */
    private const REPORTS = [
        PlayerDirectory::class,
        PlayerDemographics::class,
        PlayerMovement::class,
        PlayerDocuments::class,
        PlayerBirthdays::class,
        FinanceSummary::class,
        TransactionsJournal::class,
        CashBook::class,
        BudgetReport::class,
        OutstandingPayments::class,
        SubscriptionCollection::class,
        EquipmentInventory::class,
        EquipmentOut::class,
        EquipmentValuation::class,
        EquipmentLifecycle::class,
        StocktakeLosses::class,
        CompetitionResults::class,
        CompetitionFixtures::class,
        CompetitionDiscipline::class,
        CompetitionSuspensions::class,
        CompetitionAppearances::class,
        SessionsOverview::class,
    ];

    /**
     * Existing reports: id => [category, route name, permission module, icon, opens a file].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool}>
     */
    private const LINKS = [
        'players.list' => ['players', 'players.index', 'players', 'print', false],
        'finance.overview' => ['finance', 'finance.index', 'finance', 'money', false],
        'finance.transactions' => ['finance', 'transactions.index', 'transactions', 'transactions', false],
        'finance.season_pdf' => ['finance', 'reports.financial', 'reports', 'document', true],
        'equipment.inventory_page' => ['equipment', 'equipment.inventory', 'equipment', 'equipment', false],
        'equipment.stocktake' => ['equipment', 'inventory.index', 'inventory', 'clipboard', false],
        'competitions.match_stats' => ['competitions', 'games.stats', 'games', 'clipboard', false],
        'attendance.stats' => ['attendance', 'attendance.stats', 'attendance', 'calendar', false],
        'attendance.alerts' => ['attendance', 'attendance.alerts', 'attendance', 'alert', false],
        'attendance.ranking' => ['attendance', 'attendance.ranking', 'attendance', 'flag', false],
        'attendance.injuries' => ['attendance', 'attendance.injuries', 'attendance', 'alert', false],
    ];

    /** @return list<Report> */
    public function reports(): array
    {
        return array_map(fn (string $class) => app($class), self::REPORTS);
    }

    public function find(string $category, string $key): ?Report
    {
        foreach ($this->reports() as $report) {
            if ($report->category() === $category && $report->key() === $key) {
                return $report;
            }
        }

        return null;
    }

    /**
     * The modules behind each category, for the sidebar: a category shows
     * when the user can view any of them.
     *
     * @return array<string, list<string>>
     */
    public function categoryModules(): array
    {
        $modules = array_fill_keys(self::CATEGORIES, []);
        foreach ($this->reports() as $report) {
            $modules[$report->category()][] = $report->module();
        }
        foreach (self::LINKS as [$category, , $module]) {
            $modules[$category][] = $module;
        }

        return array_map(fn (array $list) => array_values(array_unique($list)), $modules);
    }

    /**
     * The cards a user may open, new reports first within each category.
     *
     * @return list<array{kind: string, id: string, category: string, title: string, description: string, href: string, external: bool, icon: string}>
     */
    public function cards(User $user, ?string $category = null): array
    {
        $cards = [];

        foreach (self::CATEGORIES as $cat) {
            if ($category !== null && $cat !== $category) {
                continue;
            }

            foreach ($this->reports() as $report) {
                if ($report->category() === $cat && $report->allows($user)) {
                    $cards[] = [
                        'kind' => 'report',
                        'id' => $report->id(),
                        'category' => $cat,
                        'title' => $report->title(),
                        'description' => $report->description(),
                        'href' => route('reports.view', [$cat, $report->key()]),
                        'external' => false,
                        'icon' => $report->icon(),
                    ];
                }
            }

            foreach (self::LINKS as $id => [$linkCategory, $route, $module, $icon, $external]) {
                if ($linkCategory === $cat && $user->hasPermission($module, 'view')) {
                    $cards[] = [
                        'kind' => 'link',
                        'id' => $id,
                        'category' => $cat,
                        'title' => 'reports.link.'.$id.'.title',
                        'description' => 'reports.link.'.$id.'.description',
                        'href' => route($route),
                        'external' => $external,
                        'icon' => $icon,
                    ];
                }
            }
        }

        return $cards;
    }
}
