<?php

namespace App\Services\Player;

use App\Models\Player;
use App\Models\PlayerStatus;
use Illuminate\Support\Facades\DB;

class RegisterPlayerService
{
    /**
     * Owner decision: registering a member attaches NO subscription. A new
     * member starts with no debt; subscriptions are assigned by hand from the
     * player page or the subscription page (form create and spreadsheet
     * import alike).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, ?int $recordedByUserId = null): Player
    {
        return DB::transaction(function () use ($attributes) {
            $joinYear = (int) ($attributes['join_year'] ?? now()->year);

            // New members default to "registered" when no status is given (e.g.
            // spreadsheet import, which carries no membership-status column).
            // Matched by code: the display name is editable in Settings.
            if (empty($attributes['status_id'])) {
                $attributes['status_id'] = PlayerStatus::where('code', 'registered')->value('id');
            }

            if (empty($attributes['membership_id'])) {
                $attributes['membership_id'] = MembershipNumber::generateUnique($joinYear);
            }

            /** @var Player $player */
            $player = Player::query()->create($attributes);

            // The folder number is allocated once, inside the same transaction
            // that creates the member, so a failed registration leaves no gap.
            FileNumber::assign($player);

            return $player;
        });
    }
}
