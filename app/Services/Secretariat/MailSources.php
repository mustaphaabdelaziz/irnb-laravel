<?php

namespace App\Services\Secretariat;

use App\Models\Category;
use App\Models\Game;
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\Player;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\PlayerBranchTabs;
use App\Services\Pdf\ClubHeader;
use App\Services\Player\RegistrationDisplay;
use App\Support\AttendanceSettings;
use App\Support\CategoryLabels;
use App\Support\UiLang;
use Illuminate\Http\Request;

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

    /** One reference per mission order, taken at its first print; all its sheets share it. */
    public const MISSION_KEY = 'order';

    /** The module guarding the page an entry links to. */
    public const LINK_MODULES = [
        MailEntry::SOURCE_ABSENCE_LETTER => 'players',
        MailEntry::SOURCE_GAME => 'games',
        MailEntry::SOURCE_PURCHASE_ORDER => 'purchase_orders',
        MailEntry::SOURCE_MISSION => 'missions',
    ];

    /** The print dialog's URL segment → stored source type. */
    public const URL_TYPES = [
        'absence-letter' => MailEntry::SOURCE_ABSENCE_LETTER,
        'game' => MailEntry::SOURCE_GAME,
        'purchase-order' => MailEntry::SOURCE_PURCHASE_ORDER,
    ];

    /** The module whose view right printing the document needs (its PDF route's right). */
    public const PRINT_MODULES = [
        MailEntry::SOURCE_ABSENCE_LETTER => 'attendance',
        MailEntry::SOURCE_GAME => 'games',
        MailEntry::SOURCE_PURCHASE_ORDER => 'purchase_orders',
    ];

    /**
     * The document behind a print button, for the tick box: its stored type,
     * id and key, and the correspondent / address / subject a new entry
     * starts with (the document's own). 403 without the right to print it,
     * 404 when it does not exist, 422 for a purchase order that is not sent or
     * received, or is imported (the dialog is hidden for those).
     *
     * @return array{type: string, id: int, key: string, defaults: array{correspondent: ?string, address: ?string, subject: ?string}}
     */
    public function resolve(Request $request, string $urlType, int $id): array
    {
        $type = self::URL_TYPES[$urlType] ?? abort(404);
        abort_unless($request->user()->hasPermission(self::PRINT_MODULES[$type], 'view'), 403);

        return match ($type) {
            MailEntry::SOURCE_ABSENCE_LETTER => $this->absenceLetter($request, Player::findOrFail($id)),
            MailEntry::SOURCE_GAME => $this->game(Game::with(['team.category', 'opponent'])->findOrFail($id)),
            MailEntry::SOURCE_PURCHASE_ORDER => $this->purchaseOrder(PurchaseOrder::with('supplier:id,name,address')->findOrFail($id)),
        };
    }

    /**
     * Same tab and period as the letter's PDF (AttendancePlayerController::letter):
     * the dialog sends the PDF's own query string. Recipient and subject as the
     * letter prints them.
     */
    private function absenceLetter(Request $request, Player $player): array
    {
        [, $branchId] = PlayerBranchTabs::resolve($request, $player);
        ['from' => $from, 'to' => $to] = ActivityPeriod::fromRequestOrSeason($request)->toArray();
        $categoryId = RegistrationDisplay::make()->values($player, $branchId)['category_id'];
        $contact = $player->emergencyContacts()->orderBy('id')->value('name');
        $letter = AttendanceSettings::letter([
            'player' => $player->fullname,
            'category' => $categoryId === null ? '—' : (Category::find($categoryId)?->localized_name ?? '—'),
            'period' => self::day($from).' – '.self::day($to),
            'club' => (string) (ClubHeader::data()['name'] ?? ''),
        ]);

        return [
            'type' => MailEntry::SOURCE_ABSENCE_LETTER,
            'id' => $player->id,
            'key' => self::letterKey($player->id, $branchId, $from, $to),
            'defaults' => [
                'correspondent' => $contact ?: strtr(UiLang::get('att.letter.guardian_of'), ['{player}' => $player->fullname]),
                'address' => null,
                'subject' => $letter['subject'],
            ],
        ];
    }

    /** The convocation goes to the called-up players: the team's label by default (editable on the entry). */
    private function game(Game $game): array
    {
        $opponent = $game->opponent?->name ?? '—';
        $team = $game->team->labelWith(CategoryLabels::make());

        return [
            'type' => MailEntry::SOURCE_GAME,
            'id' => $game->id,
            'key' => self::GAME_KEY,
            'defaults' => [
                'correspondent' => $team,
                'address' => null,
                'subject' => UiLang::get('comp.convocation.title').' — '.$team
                    .' / '.$opponent.' — '.self::day((string) $game->date),
            ],
        ];
    }

    private function purchaseOrder(PurchaseOrder $order): array
    {
        abort_unless(in_array($order->status, [PurchaseOrder::SENT, PurchaseOrder::RECEIVED], true) && ! $order->is_legacy, 422);

        return [
            'type' => MailEntry::SOURCE_PURCHASE_ORDER,
            'id' => $order->id,
            'key' => self::PURCHASE_ORDER_KEY,
            'defaults' => [
                'correspondent' => $order->supplier?->name,
                'address' => $order->supplier?->address,
                'subject' => UiLang::get('pur.title').' '.$order->reference,
            ],
        ];
    }

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
            MailEntry::SOURCE_MISSION => route('missions.show', $id),
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
            MailEntry::SOURCE_MISSION => self::missionLabel($id),
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

    /** A mission order: its destination and order date. */
    private static function missionLabel(int $orderId): ?string
    {
        $order = MissionOrder::find($orderId, ['id', 'destination', 'order_date']);
        if ($order === null) {
            return null;
        }

        return ($order->destination ?? '—').($order->order_date ? ' · '.$order->order_date->format('d/m/Y') : '');
    }

    /** 'Y-m-d' → 'dd/mm/yyyy', as the prints show dates. */
    private static function day(string $date): string
    {
        return substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4);
    }
}
