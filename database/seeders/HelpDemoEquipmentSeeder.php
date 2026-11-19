<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Models\Player;
use App\Models\StorageLocation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Equipment\EquipmentLifecycleService;
use App\Services\Equipment\EquipmentStockService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The demo store room for the Help Center equipment articles. Called only from
 * the guarded HelpDemoSeeder. Everything goes through the equipment services as
 * the demo user, each step run "on its day" so dates, history and timestamps agree.
 * No money moves: HelpDemoFinanceSeeder's «شراء كرات وأقماع» already paid for the
 * balls and cones. No activity entries either: the users articles count them.
 */
class HelpDemoEquipmentSeeder extends Seeder
{
    public const BALL = 'كرة قدم مقاس 5';

    public const CONE = 'قمع تدريب';

    public const BIB = 'صدرية تدريب';

    public const STOPWATCH = 'ساعة توقيت';

    public const GOAL = 'مرمى متنقل';

    public const GOGGLES = 'نظارات سباحة';

    public const MAIN_STORE = 'المخزن الرئيسي';

    public const CHANGING_ROOM = 'غرفة الملابس';

    public const POOL_STORE = 'مخزن المسبح';

    public const OUTSIDER = 'مراد بلقاسم';

    public const OUTSIDER_PHONE = '0550123456';

    public const STOCKTAKE_NOTES = 'جرد شهري';

    /** The completed stock-take's day, D−35. */
    public const COMPLETED_STOCKTAKE_DAYS_AGO = 35;

    /** HelpDemoFinanceSeeder's expense that paid for the balls and cones. */
    public const BALLS_AND_CONES_EXPENSE = 'شراء كرات وأقماع';

    /** The migration's English categories => [Arabic name, code]. */
    private const CATEGORIES = [
        'Balls' => ['كرات', 'BALL'],
        'Goals & Nets' => ['مرامي وشباك', 'GOAL'],
        'Training Equipment' => ['معدات التدريب', 'TRN'],
        'Apparel' => ['ملابس', 'APP'],
        'Protective Gear' => ['معدات الحماية', 'PRT'],
        'Accessories' => ['إكسسوارات', 'ACC'],
        'Maintenance' => ['صيانة', 'MNT'],
        'Other' => ['أخرى', 'OTH'],
    ];

    private CarbonImmutable $today;

    private User $demo;

    private EquipmentStockService $stock;

    private EquipmentLifecycleService $lifecycle;

    public function run(): void
    {
        HelpDemoSeeder::assertThrowawayDatabase();

        $this->today = CarbonImmutable::today();
        $this->demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();
        $this->stock = app(EquipmentStockService::class);
        $this->lifecycle = app(EquipmentLifecycleService::class);

        foreach (self::CATEGORIES as $english => [$arabic, $code]) {
            $category = EquipmentCategory::where('name', $english)->firstOrFail();
            // As EquipmentCategoryController::update: catalogs follow the category by name.
            $category->catalogs()->update(['category' => $arabic]);
            $category->update(['name' => $arabic, 'code' => $code]);
        }

        foreach ([self::MAIN_STORE, self::CHANGING_ROOM, self::POOL_STORE] as $name) {
            StorageLocation::create(['name' => $name]);
        }

        $football = Branch::where('name', 'Football')->firstOrFail()->id;
        $swimming = Branch::where('name', 'Swimming')->firstOrFail()->id;

        // Created in this order so the ids are 1–6 (the shots open /equipment/catalogs/{id}).
        $ball = $this->catalog(self::BALL, 'كرات', 3000, false);
        $cone = $this->catalog(self::CONE, 'معدات التدريب', 150, false);
        $bib = $this->catalog(self::BIB, 'ملابس', 600, false);
        $stopwatch = $this->catalog(self::STOPWATCH, 'إكسسوارات', 2500, true);
        $goal = $this->catalog(self::GOAL, 'مرامي وشباك', 18000, true);
        $goggles = $this->catalog(self::GOGGLES, 'معدات الحماية', 800, false);

        $bought = $this->ballsAndConesDay();

        $goals = $this->receive($goal, 2, 90, 'Good', self::MAIN_STORE, [$football], 'opening_balance', 'مرمى');
        $balls = $this->receive($ball, 20, $bought, 'New', self::MAIN_STORE, [$football], 'purchase');
        $cones = $this->receive($cone, 40, $bought, 'New', self::MAIN_STORE, [], 'purchase');
        $bibs = $this->receive($bib, 30, 45, 'Good', self::CHANGING_ROOM, [$football], 'donation');
        $gogglesLot = $this->receive($goggles, 25, 40, 'New', self::POOL_STORE, [$swimming], 'donation');
        $this->receive($stopwatch, 3, 30, 'New', self::MAIN_STORE, [], 'opening_balance', 'ساعة توقيت');

        // A count on D−35 (09:00), before anything below was lent (from D−35 17:00) or split.
        $this->completedStockTake();

        $ahmed = $this->player('أحمد', 'بن علي');
        $amine = $this->player('أمين', 'قاسمي');
        $swimmer = $this->player('مريم', 'زروقي');

        $this->on(35, '17:00', fn ($at) => $this->lifecycle->rentOut($gogglesLot, $swimmer, [
            'type' => 'assignment', 'quantity' => 1, 'checkout_date' => $at, 'user_id' => $this->demo->id,
        ]));

        $coneRental = $this->on(20, '17:00', fn ($at) => $this->lifecycle->rentOut($cones, $amine, [
            'type' => 'rental', 'quantity' => 10, 'checkout_date' => $at,
            'due_date' => $this->today->subDays(13)->toDateString(), 'user_id' => $this->demo->id,
        ]));
        $this->on(13, '18:30', fn ($at) => $this->lifecycle->returnItem($coneRental->fresh(), [
            'return_date' => $at, 'user_id' => $this->demo->id,
        ]));

        $this->on(15, '10:00', fn () => $this->stock->splitLot($bibs, 3, 'Damaged', $this->demo->id));

        $this->on(10, '17:00', fn ($at) => $this->lifecycle->rentOut($balls, $ahmed, [
            'type' => 'rental', 'quantity' => 2, 'checkout_date' => $at,
            'due_date' => $this->today->subDays(3)->toDateString(), 'user_id' => $this->demo->id,
        ]));

        $unit2 = EquipmentItem::where('catalog_id', $stopwatch->id)->where('designation', 'ساعة توقيت 2')->firstOrFail();
        $this->on(5, '10:00', fn () => $this->lifecycle->sendToRepair($unit2, $this->demo->id));

        $this->on(2, '15:00', fn ($at) => $this->lifecycle->rentOut($goals, null, [
            'type' => 'rental', 'quantity' => 1, 'checkout_date' => $at,
            'due_date' => $this->today->addDays(3)->toDateString(),
            'external_name' => self::OUTSIDER, 'external_phone' => self::OUTSIDER_PHONE,
            'user_id' => $this->demo->id,
        ]));

        $this->openStockTake($amine);
    }

    private function catalog(string $name, string $category, int $price, bool $tracked): EquipmentCatalog
    {
        return EquipmentCatalog::create([
            'name' => $name,
            'category' => $category,
            'purchase_price' => $price,
            'requires_serial' => $tracked,
        ]);
    }

    /**
     * The day of HelpDemoFinanceSeeder's «شراء كرات وأقماع» (D−44, or the season
     * start on an early-season seed day), so stock and expense agree. Never later
     * than D−21: the first loan from these lots is on D−20, so very early in a
     * season the units arrive a little before the (clamped) expense date.
     */
    private function ballsAndConesDay(): CarbonImmutable
    {
        $paid = Transaction::where('title', self::BALLS_AND_CONES_EXPENSE)->value('transaction_date');
        if ($paid === null) {
            throw new RuntimeException('HelpDemoEquipmentSeeder needs the «'.self::BALLS_AND_CONES_EXPENSE.'» expense of HelpDemoFinanceSeeder.');
        }

        return CarbonImmutable::parse($paid)->startOfDay()->min($this->today->subDays(21));
    }

    /** Receives on $day (or D−$day), no expense; returns the first row (the lot, or unit 1). */
    private function receive(EquipmentCatalog $catalog, int $quantity, int|CarbonImmutable $day, string $condition, string $location, array $branchIds, string $via, ?string $designation = null): EquipmentItem
    {
        return $this->on($day, '09:00', fn ($at) => $this->stock->receive([
            'catalog_id' => $catalog->id,
            'quantity' => $quantity,
            'unit_price' => (float) $catalog->purchase_price,
            'purchase_date' => $at->toDateString(),
            'condition' => $condition,
            'location' => $location,
            'received_via' => $via,
            'record_expense' => false,
            'branch_ids' => $branchIds,
            'designation' => $designation,
        ], $this->demo->id));
    }

    /**
     * Yearly count on D−35 (it may fall in the previous season), as
     * InventoryController::store + counts + complete with every unit found.
     * Only what had been received by then is on the sheet.
     */
    private function completedStockTake(): void
    {
        $day = $this->today->subDays(self::COMPLETED_STOCKTAKE_DAYS_AGO);

        $this->on($day, '09:00', function () use ($day) {
            $session = InventorySession::create([
                'reference' => $this->nextReference(),
                'type' => 'yearly',
                'session_date' => $day->toDateString(),
                'status' => 'in_progress',
                'conducted_by_user_id' => $this->demo->id,
            ]);
            $total = $this->snapshot($session, $day, found: true);
            $session->update([
                'status' => 'completed',
                'completed_at' => $day->setTime(12, 0),
                'total_found' => $total,
                'total_missing' => 0,
            ]);
        });
    }

    /** Today's monthly count, as InventoryController::store + participants; nothing counted yet. */
    private function openStockTake(Player $participant): void
    {
        $session = InventorySession::create([
            'reference' => $this->nextReference(),
            'type' => 'monthly',
            'session_date' => $this->today->toDateString(),
            'status' => 'in_progress',
            'conducted_by_user_id' => $this->demo->id,
            'notes' => self::STOCKTAKE_NOTES,
        ]);
        $this->snapshot($session, $this->today, found: false);
        $session->participants()->create([
            'participant_type' => Player::class,
            'participant_id' => $participant->id,
        ]);
    }

    /**
     * InventoryController::store's count sheet: one line per lot received by $day
     * that is neither retired nor lost, expecting the units not out on loan; a lot
     * wholly on loan gets no line. total_expected is in units. With $found every
     * line is counted and found in full. Returns the units expected.
     */
    private function snapshot(InventorySession $session, CarbonImmutable $day, bool $found): int
    {
        $items = EquipmentItem::whereNotIn('status', ['Retired', 'Lost'])
            ->whereDate('purchase_date', '<=', $day->toDateString())
            ->with(['rentals' => fn ($q) => $q->whereNull('return_date')])
            ->get(['id', 'status', 'condition', 'location', 'quantity']);

        $total = 0;
        foreach ($items as $it) {
            $onLoan = min($it->quantity, $this->stock->outstandingQuantity($it));
            $expected = $it->quantity - $onLoan;
            if ($expected < 1) {
                continue; // wholly on loan: nothing to find
            }

            InventorySessionItem::create([
                'inventory_session_id' => $session->id,
                'equipment_item_id' => $it->id,
                'expected_status' => $it->status,
                'expected_condition' => $it->condition,
                'expected_location' => $it->location,
                'expected_quantity' => $expected,
                'on_loan_quantity' => $onLoan,
            ] + ($found ? ['counted' => true, 'found' => true, 'found_quantity' => $expected] : []));
            $total += $expected;
        }

        $session->update(['total_expected' => $total]);

        return $total;
    }

    /** InventoryController::store's numbering: INV-<year>-NN after the highest used this year. */
    private function nextReference(): string
    {
        $prefix = 'INV-'.now()->format('Y').'-';
        $seq = InventorySession::query()
            ->where('reference', 'like', $prefix.'%')
            ->pluck('reference')
            ->map(fn (string $ref) => (int) substr($ref, strlen($prefix)))
            ->max() + 1;

        return $prefix.str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    }

    private function player(string $first, string $last): Player
    {
        return Player::where('firstname', $first)->where('lastname', $last)->firstOrFail();
    }

    /**
     * Runs $step with the clock set to $day (or D−$day) at $time, so every
     * created_at and history event_timestamp the services write falls on that
     * day; then puts the clock back the way it was (frozen in tests, live otherwise).
     */
    private function on(int|CarbonImmutable $day, string $time, Closure $step): mixed
    {
        $was = Carbon::hasTestNow() ? Carbon::getTestNow() : null;
        $day = is_int($day) ? $this->today->subDays($day) : $day;
        $at = $day->setTimeFromTimeString($time)->min(CarbonImmutable::now());

        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
        try {
            return $step($at);
        } finally {
            Carbon::setTestNow($was);
            CarbonImmutable::setTestNow($was);
        }
    }
}
