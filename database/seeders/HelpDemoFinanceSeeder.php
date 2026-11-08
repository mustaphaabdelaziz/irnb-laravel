<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Budget;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransfer;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\FiscalPeriod;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Finance for the Help Center demo club. Called only from the guarded
 * HelpDemoSeeder, after the subscription payments. Entries go through
 * Transaction + TransactionObserver and FinanceService, so balances, season
 * totals and the carried opening balance are the app's own numbers.
 */
class HelpDemoFinanceSeeder extends Seeder
{
    public const BANK = 'الحساب البنكي للنادي';

    public const BANK_RIB = '00799999000123456789';

    public const DUPLICATE = 'فاتورة الكهرباء';

    /** The previous season's net (150 000 − 45 000 + 15 000), carried to the current one. */
    public const OPENING = 120000;

    /** Previous season: [days after its start, type, category code, title, amount, account, method]. */
    private const PREVIOUS = [
        [40, 'income', 'GRT', 'منحة البلدية', 150000, 'bank', 'bank'],
        [130, 'expense', 'EQP', 'شراء بدلات رياضية', 45000, 'bank', 'bank'],
        [220, 'income', 'DON', 'تبرع أحد المحسنين', 15000, 'cash', 'cash'],
    ];

    /** Current season: [days before the seed day, type, category code, title, amount, account, method]. */
    private const CURRENT = [
        [48, 'income', 'GRT', 'منحة البلدية', 200000, 'bank', 'bank'],
        [44, 'expense', 'EQP', 'شراء كرات وأقماع', 18000, 'football', 'cash'],
        [38, 'expense', 'UTL', self::DUPLICATE, 9500, 'cash', 'ccp'],
        // Entered twice by mistake: the cancel-transaction article removes this one.
        [37, 'expense', 'UTL', self::DUPLICATE, 9500, 'cash', 'ccp'],
        [33, 'income', 'DON', 'تبرع من مؤسسة محلية', 25000, 'cash', 'cash'],
        [27, 'expense', 'SAL', 'أجرة مدرب السباحة', 30000, 'bank', 'bank'],
        [22, 'income', 'EVR', 'مداخيل دورة الأحياء', 16000, 'football', 'cash'],
        [18, 'expense', 'MNT', 'صيانة مضخة المسبح', 14000, 'bank', 'bank'],
        [14, 'expense', 'SUP', 'لوازم الإسعافات الأولية', 4500, 'football', 'cash'],
        [9, 'income', 'MEM', 'رسوم العضوية', 6000, 'cash', 'cash'],
        [6, 'expense', 'ADM', 'طباعة بطاقات اللاعبين', 3200, 'cash', 'baridimob'],
        [3, 'expense', 'EVE', 'نقل الفريق إلى مباراة خارج الديار', 12000, 'football', 'cash'],
        [1, 'income', 'OIN', 'بيع قمصان النادي', 8000, 'cash', 'cash'],
    ];

    /** Current season budget: category code => planned amount. */
    private const BUDGET = ['GRT' => 200000, 'SUB' => 60000, 'EQP' => 30000, 'SAL' => 120000, 'UTL' => 40000];

    private User $demo;

    public function run(): void
    {
        HelpDemoSeeder::assertThrowawayDatabase();

        $this->demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();
        $football = Branch::where('name', 'Football')->firstOrFail();

        $accounts = [
            // The migration already seeds an empty English-named club bank account: name it, don't add a second one.
            'bank' => $this->bank(),
            'cash' => FinanceAccount::whereNull('branch_id')->where('type', 'cash')
                ->where('is_opening_fund', false)->orderBy('id')->firstOrFail(),
            'football' => $football->treasury()->firstOrFail(),
        ];

        $today = CarbonImmutable::today();
        $seasonStart = Season::current()->start();
        $previousStart = Season::forStartYear($seasonStart->year - 1)->start();

        foreach (self::PREVIOUS as [$days, $type, $code, $title, $amount, $account, $method]) {
            $this->entry($previousStart->addDays($days), $type, $code, $title, $amount, $accounts[$account], $method);
        }
        $finance = app(FinanceService::class);
        $finance->closeYear(FiscalYear::where('year', FiscalPeriod::keyForDate($previousStart))->firstOrFail(), $this->demo);

        foreach (self::CURRENT as [$days, $type, $code, $title, $amount, $account, $method]) {
            $this->entry($today->subDays($days)->max($seasonStart), $type, $code, $title, $amount, $accounts[$account], $method);
        }

        $u14 = FinanceAccount::where('name', HelpDemoSubscriptionsSeeder::CAISSE)->firstOrFail();
        $this->transfer($accounts['bank'], $accounts['football'], 50000, $today->subDays(45)->max($seasonStart), 'تموين خزينة كرة القدم');
        $this->transfer($u14, $accounts['football'], 6000, $today->subDays(10)->max($seasonStart), 'إيداع اشتراكات أقل من 14 سنة في الخزينة');
        $finance->recomputeAccountBalances();

        $year = FiscalYear::where('year', FiscalPeriod::currentKey())->firstOrFail();
        foreach (self::BUDGET as $code => $planned) {
            Budget::create([
                'fiscal_year_id' => $year->id,
                'finance_category_id' => $this->category($code)->id,
                'planned_amount' => $planned,
            ]);
        }
    }

    private function bank(): FinanceAccount
    {
        $bank = FinanceAccount::whereNull('branch_id')->where('type', 'bank')->orderBy('id')->first() ?? new FinanceAccount([
            'type' => 'bank', 'opening_balance' => 0, 'current_balance' => 0, 'currency' => 'DZD', 'is_treasury' => false, 'is_active' => true,
        ]);
        $bank->fill(['name' => self::BANK, 'account_number' => self::BANK_RIB])->save();

        return $bank;
    }

    private function entry(CarbonImmutable $date, string $type, string $code, string $title, int $amount, FinanceAccount $account, string $method): Transaction
    {
        $category = $this->category($code);

        return Transaction::create([
            'title' => $title,
            'amount' => $amount,
            'transaction_date' => $date->toDateString(),
            'transaction_type' => $type,
            'finance_category_id' => $category->id,
            // As TransactionController::store.
            'category' => Str::slug($category->name, '_'),
            'payment_method' => $method,
            'finance_account_id' => $account->id,
            'status' => 'Paid',
            'recorded_by_user_id' => $this->demo->id,
        ]);
    }

    private function transfer(FinanceAccount $from, FinanceAccount $to, int $amount, CarbonImmutable $date, string $notes): void
    {
        FinanceTransfer::create([
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => $amount,
            'transfer_date' => $date->toDateString(),
            'notes' => $notes,
            'created_by_user_id' => $this->demo->id,
        ]);
    }

    private function category(string $code): FinanceCategory
    {
        return FinanceCategory::where('code', $code)->firstOrFail();
    }
}
