<?php

namespace App\Services\Secretariat;

use App\Models\Game;
use App\Models\MailEntry;
use App\Models\Player;
use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * The printed documents a mail entry can come from — parent (absence)
 * letter, match convocation, purchase order: their source keys, and how the
 * register names and links them. A label or link is shown only to who may
 * view the document's module (the player page, the match, the order).
 */
final class MailSources
{
    /** One convocation per game. */
    public const GAME_KEY = 'convocation';

    /** One registration per purchase order. */
    public const PURCHASE_ORDER_KEY = 'order';

    /** The module guarding the page an entry links to. */
    public const LINK_MODULES = [
        MailEntry::SOURCE_ABSENCE_LETTER => 'players',
        MailEntry::SOURCE_GAME => 'games',
        MailEntry::SOURCE_PURCHASE_ORDER => 'purchase_orders',
    ];

    /** A parent letter is about one player, for one period, in one branch tab (0 = none). */
    public static function letterKey(int $playerId, ?int $branchId, string $from, string $to): string
    {
        return sprintf('player:%d|branch:%d|%s|%s', $playerId, $branchId ?? 0, $from, $to);
    }

    /** The page of an entry's document, when the viewer may open it. */
    public static function url(string $type, int $id, User $viewer): ?string
    {
        $module = self::LINK_MODULES[$type] ?? null;
        if ($module === null || ! $viewer->hasPermission($module, 'view')) {
            return null;
        }

        return match ($type) {
            MailEntry::SOURCE_ABSENCE_LETTER => route('players.show', $id),
            MailEntry::SOURCE_GAME => route('games.show', $id),
            MailEntry::SOURCE_PURCHASE_ORDER => route('purchase-orders.show', $id),
        };
    }

    /**
     * How the entry page shows its document: its type, a label (the player and
     * the letter's period, the opponent and date, the order's number) and a
     * link — or "#id" without a link when the viewer may not view that module
     * or the document is gone.
     *
     * @return array{type: string, label: string, url: ?string}|null
     */
    public function describe(MailEntry $entry, User $viewer): ?array
    {
        if (! $entry->isFromSource()) {
            return null;
        }
        $id = (int) $entry->source_id;
        $url = self::url($entry->source_type, $id, $viewer);
        $label = $url === null ? null : match ($entry->source_type) {
            MailEntry::SOURCE_ABSENCE_LETTER => self::letterLabel($id, (string) $entry->source_key),
            MailEntry::SOURCE_GAME => self::gameLabel($id),
            MailEntry::SOURCE_PURCHASE_ORDER => PurchaseOrder::find($id)?->reference,
            default => null,
        };

        return ['type' => $entry->source_type, 'label' => $label ?? '#'.$id, 'url' => $label === null ? null : $url];
    }

    private static function letterLabel(int $playerId, string $key): ?string
    {
        $player = Player::find($playerId);
        if ($player === null) {
            return null;
        }
        // player:{id}|branch:{id}|{from}|{to}
        $parts = explode('|', $key);

        return count($parts) === 4
            ? $player->fullname.' · '.self::day($parts[2]).' – '.self::day($parts[3])
            : $player->fullname;
    }

    private static function gameLabel(int $gameId): ?string
    {
        $game = Game::with('opponent:id,name')->find($gameId);

        return $game === null ? null : ($game->opponent?->name ?? '—').' · '.self::day((string) $game->date);
    }

    /** 'Y-m-d' → 'dd/mm/yyyy', as the prints show dates. */
    private static function day(string $date): string
    {
        return substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4);
    }
}
