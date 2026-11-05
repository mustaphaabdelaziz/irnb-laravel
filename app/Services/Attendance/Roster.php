<?php

namespace App\Services\Attendance;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\TrainingSession;
use App\Services\Player\RegistrationDisplay;
use App\Support\AttendanceSettings;
use Illuminate\Database\Eloquent\Collection as PlayerRows;
use Illuminate\Support\Collection;

/**
 * Who is expected at a session. Before the first save it is the players with one
 * registration in the session's branch (any registration for a session with no
 * branch), in a category of the session, on the roster on that date, up to and
 * including their leave date; a player with no registration is judged on their
 * own columns. After the first save it is exactly the saved marks. Freezing it
 * keeps history fair: later category or status changes, departures and
 * newcomers never rewrite a past session.
 *
 * The roster set is the session's own `roster_status_ids` (chosen when it
 * was added by hand), else the settings' default (AttendanceSettings::
 * rosterStatusIds(): the saved set, else the status coded `registered`).
 * When the set holds the registered status, players with no status (older
 * records) and players marked `left` after the session date (members on
 * that date) count as registered too.
 */
final class Roster
{
    // Player::NAME_COLUMNS: Player::fullname, the name every attendance list shows.
    // picture_url: the photo beside each name on the session page.
    public const COLUMNS = ['id', ...Player::NAME_COLUMNS, 'category_id', 'picture_url'];

    /** @var list<int>|null the settings' default set, read once per instance */
    private ?array $defaultStatusIds = null;

    /** @var array<int, ?string>|null every player status id => its code, read once per instance */
    private ?array $statuses = null;

    /**
     * @param  int|array<int, int>  $categoryIds
     * @param  list<int>|null  $statusIds  the roster set; null = the settings' default
     * @param  int|null  $branchId  only this branch's registrations; null = any registration
     * @return Collection<int, Player>
     */
    public function expected(int|array $categoryIds, string $date, ?array $statusIds = null, ?int $branchId = null): Collection
    {
        $statusIds ??= $this->defaultStatusIds();

        return Player::registeredWhere(
            branches: $branchId,
            categories: (array) $categoryIds,
            also: fn ($row, string $status, string $leftAt) => $this->onRoster($row, $status, $leftAt, $date, $statusIds),
        )
            ->where('archived', false)
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
    }

    /**
     * Not gone before the date, with a status in the roster set. When the set holds
     * registered: no status at all (older records) and left later (members on that
     * date) count too. A legacy "left" with no date never does.
     *
     * @param  list<int>  $statusIds
     */
    private function onRoster(mixed $row, string $status, string $leftAt, string $date, array $statusIds): void
    {
        // SQLite may hold a date with a time: compare on the day alone.
        $row->where(fn ($q) => $q->whereNull($leftAt)->orWhere($leftAt, '>=', substr($date, 0, 10)));

        if ($statusIds === []) {
            return;
        }

        $row->where(function ($q) use ($status, $leftAt, $statusIds) {
            $q->whereIn($status, $statusIds);
            ['registered' => $registered, 'left' => $left] = $this->codedStatuses();
            if ($registered !== null && in_array($registered, $statusIds, true)) {
                $q->orWhereNull($status);
                if ($left !== null) {
                    $q->orWhere(fn ($q) => $q->where($status, $left)->whereNotNull($leftAt));
                }
            }
        });
    }

    /** @return Collection<int, Player> */
    public function forSession(TrainingSession $session): Collection
    {
        if (! $session->attendances()->exists()) {
            return $this->expected($session->categoryIds(), $session->date, $this->statusIdsFor($session), $session->branch_id);
        }

        return Player::whereIn('id', $session->attendances()->select('player_id'))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
    }

    /**
     * The category to tag each listed player with: their registration's in the
     * branch, else their main one, else their own. One load for any number of players.
     *
     * @param  PlayerRows<int, Player>  $players
     * @return array<int, int|null> by player id
     */
    public function categoryIdsIn(PlayerRows $players, ?int $branchId): array
    {
        $players->loadMissing('registrations');
        $display = RegistrationDisplay::make();

        return $players->mapWithKeys(fn (Player $player) => [$player->id => $display->values($player, $branchId)['category_id']])->all();
    }

    /**
     * The session's roster set: its own statuses that still exist, else (none
     * set, or all since deleted) the settings' default.
     *
     * @return list<int>
     */
    public function statusIdsFor(TrainingSession $session): array
    {
        $own = array_values(array_filter(
            array_map('intval', is_array($session->roster_status_ids) ? $session->roster_status_ids : []),
            fn (int $id) => array_key_exists($id, $this->statuses()),
        ));

        return $own !== [] ? $own : $this->defaultStatusIds();
    }

    /** @return list<int> */
    public function defaultStatusIds(): array
    {
        return $this->defaultStatusIds ??= AttendanceSettings::rosterStatusIds();
    }

    /** @return array{registered: ?int, left: ?int} */
    private function codedStatuses(): array
    {
        $byCode = array_flip(array_filter($this->statuses(), fn (?string $code) => $code !== null));

        return ['registered' => $byCode['registered'] ?? null, 'left' => $byCode['left'] ?? null];
    }

    /** @return array<int, ?string> */
    private function statuses(): array
    {
        return $this->statuses ??= PlayerStatus::pluck('code', 'id')->mapWithKeys(fn (?string $code, $id) => [(int) $id => $code])->all();
    }
}
