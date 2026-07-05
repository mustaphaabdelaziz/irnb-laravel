<?php

namespace App\Services\Player;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class RegisterPlayerService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, ?int $recordedByUserId = null): Player
    {
        return DB::transaction(function () use ($attributes, $recordedByUserId) {
            $joinYear = (int) ($attributes['join_year'] ?? now()->year);
            $isStudent = (bool) ($attributes['is_student'] ?? true);
            $categoryId = $attributes['category_id'] ?? null;

            if (empty($attributes['membership_id'])) {
                $attributes['membership_id'] = $this->generateMembershipId($joinYear);
            }

            /** @var Player $player */
            $player = Player::query()->create($attributes);

            $subscriptions = Subscription::query()
                ->where('is_mandatory', true)
                ->where('is_active', true)
                ->where('year', '>=', $joinYear)
                ->where(function ($query) use ($categoryId) {
                    // Include subscriptions with no category restriction (available to all)
                    $query->whereDoesntHave('categories');

                    // OR subscriptions explicitly assigned to the player's category
                    if ($categoryId) {
                        $query->orWhereHas('categories', fn ($sub) => $sub->where('categories.id', $categoryId));
                    }
                })
                ->get();

            foreach ($subscriptions as $subscription) {
                $amountOwed = $isStudent ? (float) $subscription->amount_student : (float) $subscription->amount_worker;

                PlayerSubscription::query()->create([
                    'player_id' => $player->id,
                    'subscription_id' => $subscription->id,
                    'transaction_id' => null,
                    'year' => (int) $subscription->year,
                    'status_at_time' => $isStudent ? 'student' : 'worker',
                    'is_mandatory' => true,
                    'amount_owed' => $amountOwed,
                    'amount_paid' => 0,
                ]);
            }

            app(\App\Services\Finance\RecalculatePlayerDebtService::class)->forPlayer($player);

            return $player->load(['playerSubscriptions.subscription', 'playerSubscriptions.transaction']);
        });
    }

    private function generateMembershipId(int $joinYear): string
    {
        $prefix = str_pad((string) max(1900, min(9999, $joinYear)), 4, '0', STR_PAD_LEFT);

        do {
            $candidate = $prefix.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Player::query()->where('membership_id', $candidate)->exists());

        return $candidate;
    }
}
