<?php

namespace App\Services\Finance;

use App\Models\Subscription;
use App\Models\SubscriptionTarification;

/** Saves a subscription's forecast: its settings, and its cost lines in the order typed. */
class SaveSubscriptionTarification
{
    /** @param  array<string, mixed>  $data  the validated `tarification` block */
    public function save(Subscription $subscription, array $data): void
    {
        $settings = [
            'sessions_per_week' => $data['sessions_per_week'] ?? null,
            'weeks' => $data['weeks'] ?? SubscriptionTarification::DEFAULT_WEEKS,
            'expected_players' => $data['expected_players'] ?? null,
            'expected_payment_rate' => $data['expected_payment_rate'] ?? SubscriptionTarification::DEFAULT_PAYMENT_RATE,
        ];
        $lines = $data['lines'] ?? [];

        if ($lines === [] && $this->isUntouched($settings) && ! $subscription->tarification()->exists()) {
            return;
        }

        $subscription->tarification()->updateOrCreate([], $settings);

        $subscription->costLines()->reorder()->delete();
        $subscription->costLines()->createMany(
            collect($lines)->values()->map(fn (array $line, int $position): array => [
                'label' => $line['label'],
                'unit' => $line['unit'],
                'amount' => $line['amount'],
                'quantity' => $line['quantity'],
                'sort_order' => $position,
            ])->all(),
        );
    }

    /** @param  array<string, mixed>  $settings */
    private function isUntouched(array $settings): bool
    {
        return $settings['sessions_per_week'] === null
            && $settings['expected_players'] === null
            && (int) $settings['weeks'] === SubscriptionTarification::DEFAULT_WEEKS
            && (int) $settings['expected_payment_rate'] === SubscriptionTarification::DEFAULT_PAYMENT_RATE;
    }
}
