<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\ReceiptPrint;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Finance\CashRegisterProvisioner;
use App\Services\Finance\PlayerPaymentRecorder;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\Finance\ReceiptData;
use App\Services\Finance\ReceiptPrinter;
use Illuminate\Database\Seeder;

/**
 * Subscriptions & payments for the Help Center demo club. Called only from the
 * guarded HelpDemoSeeder. Payments go through PlayerPaymentRecorder — the same
 * code the player page uses — so debts, statuses and receipts are real.
 */
class HelpDemoSubscriptionsSeeder extends Seeder
{
    public const ANNUAL = 'اشتراك سنوي';

    public const TRIP = 'رحلة صيفية';

    public const CAISSE = 'صندوق أقل من 14 سنة';

    public function run(): void
    {
        HelpDemoSeeder::assertThrowawayDatabase();

        $demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();
        $football = Branch::where('name', 'Football')->firstOrFail();
        $swim = Branch::where('name', 'Swimming')->firstOrFail();
        $u14 = Category::where('branch_id', $football->id)->where('name', 'أقل من 14 سنة')->firstOrFail();
        $cubs = Category::where('branch_id', $swim->id)->where('name', 'أشبال')->firstOrFail();

        // Same as FinanceAccountController: a branch caisse sits under its coffre.
        $coffre = $football->treasury()->first() ?? app(CashRegisterProvisioner::class)->forBranch($football);
        $caisse = FinanceAccount::create([
            'branch_id' => $football->id,
            'category_id' => $u14->id,
            'parent_account_id' => $coffre->id,
            'name' => self::CAISSE,
            'type' => 'cash',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_treasury' => false,
            'is_active' => true,
        ]);

        $annual = Subscription::create([
            'name' => self::ANNUAL,
            'kind' => Subscription::KIND_ANNUAL,
            'year' => Subscription::currentSeasonEndYear(),
            'amount_student' => 3000,
            'amount_worker' => 5000,
            'is_mandatory' => true,
            'is_active' => true,
        ]);
        $annual->branches()->attach([$football->id, $swim->id]);
        // Players 2 and 4 also swim: the club's main branch is Swimming (id 1, the default seed), so a
        // two-branch player is priced by the swimming «أشبال» row (2500), not the football one (3000).
        $annual->categories()->attach(
            Category::whereIn('branch_id', [$football->id, $swim->id])->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => $id === $cubs->id
                    ? ['amount_student' => 2500, 'amount_worker' => 4000]
                    : ['amount_student' => null, 'amount_worker' => null]])
                ->all(),
        );

        $trip = Subscription::create([
            'name' => self::TRIP,
            'kind' => Subscription::KIND_EXCEPTIONAL,
            'year' => null,
            'amount_student' => 1500,
            'amount_worker' => 1500,
            'is_mandatory' => false,
            'is_active' => true,
        ]);
        $trip->branches()->attach($football->id);
        $trip->categories()->attach($u14->id, ['amount_student' => null, 'amount_worker' => null]);

        $payments = app(PlayerPaymentRecorder::class);
        $debts = app(RecalculatePlayerDebtService::class);
        // By position in creation order (HelpDemoSeeder::PLAYERS), not by id: ids need not start at 1.
        $players = Player::orderBy('id')->get()->values();

        // 1 أحمد — annual paid in full into the U14 caisse; receipt printed once.
        $ahmed = $players[0];
        $ahmedAnnual = $annual->assignTo($ahmed);
        $created = $payments->subscription($ahmedAnnual, $ahmed, (float) $ahmedAnnual->remaining_amount, 'cash', null, $demo->id, $caisse->id);
        // As ReceiptController prints it: with the player and subscription loaded, so the
        // paper (and «التحقق من وصل») shows his name and the subscription's title.
        $lines = $created[0]->receiptLines(ReceiptData::RELATIONS);
        app(ReceiptPrinter::class)->record($lines->first(), ReceiptPrint::FORMAT_THERMAL, $demo, $lines);
        $debts->forPlayer($ahmed);

        // 2 ياسين — annual + trip in one grouped payment.
        $yassine = $players[1];
        $yAnnual = $annual->assignTo($yassine);
        $yTrip = $trip->assignTo($yassine);
        $payments->group(collect([$yAnnual, $yTrip]), $yassine,
            (float) $yAnnual->remaining_amount + (float) $yTrip->remaining_amount, 'cash', null, $demo->id, $caisse->id);
        $debts->forPlayer($yassine);

        // 3 أمين — partial payment.
        $amine = $players[2];
        $payments->subscription($annual->assignTo($amine), $amine, 1000, 'baridimob', null, $demo->id, null);
        $debts->forPlayer($amine);

        // 4 رياض — pays 500 more than owed: the excess becomes a donation.
        $riad = $players[3];
        $riadAnnual = $annual->assignTo($riad);
        $payments->subscription($riadAnnual, $riad, (float) $riadAnnual->remaining_amount + 500, 'cash', null, $demo->id, null);
        $debts->forPlayer($riad);

        // 5 إسلام — assigned, unpaid.
        $islam = $players[4];
        $annual->assignTo($islam);
        $debts->forPlayer($islam);

        // 6 أيوب — a debt from before the app.
        $ayoub = $players[5];
        PlayerSubscription::create([
            'player_id' => $ayoub->id,
            'subscription_id' => null,
            'label' => 'دين موسم 2023/2024',
            'transaction_id' => null,
            'year' => 2024,
            'status_at_time' => $ayoub->is_student ? 'student' : 'worker',
            'amount_owed' => 2000,
            'amount_paid' => 0,
            'is_legacy' => true,
            'is_mandatory' => true,
            'is_exempt' => false,
        ]);
        $debts->forPlayer($ayoub);

        // 7 عبد الرحمن — 10 % discount, unpaid.
        $abderrahmane = $players[6];
        $annual->assignTo($abderrahmane)->update(['discount_type' => 'percent', 'discount_value' => 10]);
        $debts->forPlayer($abderrahmane);

        // 8 زكرياء — owes the trip, unpaid. Not in the annual: the assign-players article adds him,
        // and the take-payment article pays both from his page (the annual is offered, not owed yet).
        $zakaria = $players[7];
        $trip->assignTo($zakaria);
        $debts->forPlayer($zakaria);
    }
}
