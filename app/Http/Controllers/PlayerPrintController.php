<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\WebsiteConfig;
use App\Services\Pdf\PdfService;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the office prints: the label stuck on a paper folder, and the board
 * table pinned next to the cabinet.
 */
class PlayerPrintController extends Controller
{
    /**
     * Capped so a crafted link cannot force a giant, slow-to-render sheet —
     * same limit `BulkUpdatePlayersRequest` and the equipment bulk actions
     * already use for a selection of ids.
     */
    private const MAX_IDS = 500;

    public function __construct(private PdfService $pdf) {}

    public function label(Player $player): Response
    {
        return $this->renderLabels(collect([$player->load('category')]), "folder-label-{$player->membership_id}.pdf");
    }

    public function labels(Request $request): Response
    {
        $validated = $request->validate([
            // The string-length cap is a cheap first line of defence against a
            // pathologically long query string; the real cap is the count
            // check below, which runs after junk is filtered out.
            'ids' => ['required', 'string', 'max:8000'],
        ]);

        $ids = collect(explode(',', $validated['ids']))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->count() > self::MAX_IDS) {
            return back()->withErrors(['ids' => __('Select :count players or fewer at a time.', ['count' => self::MAX_IDS])]);
        }

        $players = Player::query()->with('category')->whereIn('id', $ids)->orderBy('file_number')->get();

        if ($players->isEmpty()) {
            return back()->withErrors(['ids' => __('No player matched that selection.')]);
        }

        return $this->renderLabels($players, 'folder-labels.pdf');
    }

    private function renderLabels(Collection $players, string $filename): Response
    {
        $html = view('pdf.folder-label', [
            'club' => $this->club(),
            'players' => $players,
        ])->render();

        return $this->pdf->stream($html, $filename);
    }

    /** The club header block, same shape ReportController uses. */
    private function club(): array
    {
        $config = WebsiteConfig::singleton();
        $locale = app()->getLocale();
        $name = $config->club_name;

        return [
            'name' => is_array($name) ? ($name[$locale] ?? $name['en'] ?? $name['ar'] ?? '') : $name,
            'logo' => Media::localFile($config->branding['logo'] ?? null),
            'address' => $config->full_address ?: null,
            'phone' => $config->contact_phone,
            'email' => $config->contact_email,
            'currency' => $config->settings['currencySymbol'] ?? 'DZD',
        ];
    }
}
