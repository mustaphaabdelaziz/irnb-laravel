<?php

namespace App\Services\Attendance;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\TrainingSession;
use App\Support\AttendanceSettings;
use Illuminate\Support\Collection;

/**
 * Who is expected at a session. Before the first save it is the members of
 * every category in the session on that date whose player status is in the
 * session's roster set; after, it is exactly the saved marks. Freezing it
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
    // nickname, father, grandfather: Player::fullname, the name every attendance list shows.
    // picture_url: the photo beside each name on the session page.
    public const COLUMNS = ['id', 'firstname', 'lastname', 'nickname', 'father', 'grandfather', 'category_id', 'picture_url'];

    /** @var list<int>|null the settings' default set, read once per instance */
    private ?array $defaultStatusIds = null;

    /** @var array<int, ?string>|null every player status id => its code, read once per instance */
    private ?array $statuses = null;

    /**
     * A player has one category, so several categories never list anyone twice.
     *
     * @param  int|array<int, int>  $categoryIds
     * @param  list<int>|null  $statusIds  the roster set; null = the settings' default
     * @return Collection<int, Player>
     */
    public function expected(int|array $categoryIds, string $date, ?array $statusIds = null): Collection
    {
        $statusIds ??= $this->defaultStatusIds();

        return Player::whereIn('category_id', (array) $categoryIds)
            ->where('archived', false)
            ->where(fn ($q) => $q->whereNull('left_at')->orWhereDate('left_at', '>', $date))
            ->when($statusIds !== [], fn ($q) => $q->where(function ($q) use ($statusIds) {
                $q->whereIn('status_id', $statusIds);
                ['registered' => $registered, 'left' => $left] = $this->codedStatuses();
                if ($registered !== null && in_array($registered, $statusIds, true)) {
                    $q->orWhereNull('status_id');
                    if ($left !== null) {
                        // Left after the date (the date condition above); a legacy "left" with no date never counts.
                        $q->orWhere(fn ($q) => $q->where('status_id', $left)->whereNotNull('left_at'));
                    }
                }
            }))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
    }

    /** @return Collection<int, Player> */
    public function forSession(TrainingSession $session): Collection
    {
        if (! $session->attendances()->exists()) {
            return $this->expected($session->categoryIds(), $session->date, $this->statusIdsFor($session));
        }

        return Player::whereIn('id', $session->attendances()->select('player_id'))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
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
