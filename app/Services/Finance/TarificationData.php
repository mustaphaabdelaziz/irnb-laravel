<?php

namespace App\Services\Finance;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\SubscriptionTarification;
use App\Models\TrainingSchedule;
use Illuminate\Support\Collection;

/**
 * The inputs of a subscription's forecast. Results are computed in the browser
 * (resources/js/lib/tarification.js), so they follow unsaved edits in the form.
 */
class TarificationData
{
    /** @return array{roster: list<array<string, mixed>>, schedule: list<array<string, mixed>>, saved: array<string, mixed>, collected: float, realRate: float|null} */
    public function for(?Subscription $subscription): array
    {
        return [
            'roster' => $this->roster($subscription),
            'schedule' => $this->schedule(),
            'saved' => $this->saved($subscription),
            ...$this->paid($subscription),
        ];
    }

    /**
     * Players grouped by main category, status and exact set of registrations: a
     * small prop however big the club, and a player in two branches counts once.
     * Left registrations and players are left out: they can no longer be charged (owner, 2026-11-06).
     *
     * @return list<array{category_id: int|null, is_student: bool, registrations: list<array{branch_id: int, category_id: int|null}>, count: int}>
     */
    private function roster(?Subscription $subscription): array
    {
        $exempt = $subscription === null
            ? collect()
            : PlayerSubscription::query()
                ->where('subscription_id', $subscription->id)
                ->where('is_exempt', true)
                ->pluck('player_id');

        $registrations = BranchRegistration::query()->active()->orderBy('branch_id')->toBase()->get(['player_id', 'branch_id', 'category_id'])
            ->groupBy('player_id')
            ->map(fn (Collection $rows): array => $rows
                ->map(fn ($row): array => [
                    'branch_id' => (int) $row->branch_id,
                    'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                ])
                ->all());

        return Player::query()
            ->where('archived', false)
            ->registeredWhere(active: true)
            ->whereNotIn('id', $exempt)
            ->toBase()
            ->get(['id', 'category_id', 'is_student'])
            ->groupBy(fn ($player): string => implode('|', [
                $player->category_id ?? '',
                (int) $player->is_student,
                json_encode($registrations[$player->id] ?? []),
            ]))
            ->map(fn (Collection $players): array => [
                'category_id' => $players->first()->category_id === null ? null : (int) $players->first()->category_id,
                'is_student' => (bool) $players->first()->is_student,
                'registrations' => $registrations[$players->first()->id] ?? [],
                'count' => $players->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Weekly slots still valid, per branch and category.
     *
     * @return list<array{branch_id: int|null, category_id: int, slots: int}>
     */
    private function schedule(): array
    {
        return TrainingSchedule::query()
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString()))
            ->toBase()
            ->selectRaw('branch_id, category_id, count(*) as slots')
            ->groupBy('branch_id', 'category_id')
            ->get()
            ->map(fn ($row): array => [
                'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                'category_id' => (int) $row->category_id,
                'slots' => (int) $row->slots,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function saved(?Subscription $subscription): array
    {
        // Queried, not read as relations: a loaded relation would be serialised
        // into the page's `subscription` prop, for every viewer of that prop.
        $settings = $subscription?->tarification()->first();

        return [
            'sessions_per_week' => $settings?->sessions_per_week,
            'weeks' => $settings?->weeks ?? SubscriptionTarification::DEFAULT_WEEKS,
            'expected_players' => $settings?->expected_players,
            'expected_payment_rate' => $settings?->expected_payment_rate ?? SubscriptionTarification::DEFAULT_PAYMENT_RATE,
            'lines' => $subscription === null ? [] : $subscription->costLines()->get()
                ->map(fn ($line): array => [
                    'label' => $line->label,
                    'unit' => $line->unit,
                    'amount' => (float) $line->amount,
                    'quantity' => $line->quantity,
                ])
                ->all(),
        ];
    }

    /**
     * Collected counts every payment, as the page's own tile does. The rate is
     * over what non-exempt players owe after discounts.
     *
     * @return array{collected: float, realRate: float|null}
     */
    private function paid(?Subscription $subscription): array
    {
        if ($subscription === null) {
            return ['collected' => 0.0, 'realRate' => null];
        }

        $collected = 0.0;
        $paid = 0.0;
        $owed = 0.0;

        $rows = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->toBase()
            ->get(['amount_owed', 'amount_paid', 'is_exempt', 'discount_type', 'discount_value']);

        foreach ($rows as $row) {
            $exempt = (bool) $row->is_exempt;
            $money = PlayerSubscription::moneyFor($row->amount_owed, $row->amount_paid, $exempt, $row->discount_type, $row->discount_value);
            $collected += $money['paid'];

            if (! $exempt) {
                $paid += $money['paid'];
                $owed += $money['net_owed'];
            }
        }

        return [
            'collected' => round($collected, 2),
            'realRate' => $owed > 0 ? min(100.0, round($paid / $owed * 100, 1)) : null,
        ];
    }
}
