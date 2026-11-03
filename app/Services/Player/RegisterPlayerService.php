<?php

namespace App\Services\Player;

use App\Models\Branch;
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
     * @param  list<array<string, mixed>>  $cards  one per branch; empty in a club with no branches
     */
    public function handle(array $attributes, array $cards = []): Player
    {
        return DB::transaction(function () use ($attributes, $cards) {
            // New members default to "registered" when no status is given (e.g.
            // spreadsheet import, which carries no membership-status column).
            // Matched by code: the display name is editable in Settings.
            if (empty($attributes['status_id'])) {
                $attributes['status_id'] = PlayerStatus::where('code', 'registered')->value('id');
            }

            if (empty($attributes['membership_id'])) {
                $attributes['membership_id'] = $this->firstId($attributes, $cards);
            }

            /** @var Player $player */
            $player = Player::query()->create($attributes);

            if ($cards !== []) {
                app(RegistrationService::class)->sync($player, $cards);
            }

            return $player->refresh();
        });
    }

    /** The ID the main branch's card adopts; the old format with no branches. */
    private function firstId(array $attributes, array $cards): string
    {
        if ($cards === []) {
            return MembershipNumber::generateUnique((int) ($attributes['join_year'] ?? now()->year));
        }

        $card = app(RegistrationService::class)->mainCardFirst($cards)[0];
        $year = ! empty($card['join_date']) ? (int) substr($card['join_date'], 0, 4) : (int) ($card['legacy_join_year'] ?? now()->year);

        return MembershipNumber::forBranch(Branch::findOrFail($card['branch_id']), $year);
    }
}
