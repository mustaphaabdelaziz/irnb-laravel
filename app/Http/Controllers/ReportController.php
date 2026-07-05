<?php

namespace App\Http\Controllers;

use App\Models\BoardMeeting;
use App\Models\InventorySession;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\WebsiteConfig;
use App\Services\Pdf\PdfService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(private PdfService $pdf) {}

    public function transactionReceipt(Transaction $transaction): Response
    {
        $transaction->load(['recordedBy', 'receivedBy']);

        $relatedName = null;
        if ($transaction->related_entity_type === 'Player' && $transaction->related_entity_id) {
            $relatedName = optional(Player::find($transaction->related_entity_id))->fullname;
        }

        $html = view('pdf.receipt', [
            'club' => $this->club(),
            'transaction' => $transaction,
            'relatedName' => $relatedName,
            'receiptNumber' => str_pad((string) $transaction->id, 6, '0', STR_PAD_LEFT),
        ])->render();

        return $this->pdf->stream($html, "receipt-{$transaction->id}.pdf");
    }

    public function playerCard(Player $player): Response
    {
        $player->load(['category', 'position']);

        $html = view('pdf.member-card', [
            'club' => $this->club(),
            'player' => $player,
            'photo' => $this->resolveMediaFile($player->picture_url),
        ])->render();

        return $this->pdf->stream($html, "member-card-{$player->membership_id}.pdf");
    }

    public function financialSummary(Request $request): Response
    {
        $year = (int) ($request->query('year') ?: Carbon::now()->year);

        $base = Transaction::query()->where('archived', false)->whereYear('transaction_date', $year);

        $totalIncome = (float) (clone $base)->where('transaction_type', 'income')->sum('amount');
        $totalExpense = (float) (clone $base)->where('transaction_type', 'expense')->sum('amount');
        $totalDonations = (float) (clone $base)->where('category', 'donation')->sum('amount');

        $byCategory = DB::table('transactions')
            ->where('archived', false)
            ->whereYear('transaction_date', $year)
            ->groupBy('transaction_type', 'category')
            ->selectRaw('transaction_type, category, SUM(amount) as total')
            ->orderBy('transaction_type')
            ->get();

        $html = view('pdf.financial-summary', [
            'club' => $this->club(),
            'year' => $year,
            'totalIncome' => $totalIncome,
            'totalExpense' => $totalExpense,
            'totalDonations' => $totalDonations,
            'netBalance' => $totalIncome - $totalExpense,
            'byCategory' => $byCategory,
        ])->render();

        return $this->pdf->stream($html, "financial-summary-{$year}.pdf");
    }

    public function boardMinutes(BoardMeeting $meeting): Response
    {
        $meeting->load(['attendances.member', 'tasks.member', 'createdBy:id,name']);

        $html = view('pdf.meeting-minutes', [
            'club' => $this->club(),
            'meeting' => $meeting,
        ])->render();

        return $this->pdf->stream($html, "minutes-{$meeting->id}.pdf");
    }

    public function inventoryReport(InventorySession $session): Response
    {
        $session->load(['items.item.catalog:id,name', 'conductedBy:id,name']);

        $html = view('pdf.inventory-report', [
            'club' => $this->club(),
            'session' => $session,
        ])->render();

        return $this->pdf->stream($html, "inventory-{$session->reference}.pdf");
    }

    /**
     * Localised club header data for document templates.
     *
     * @return array<string, mixed>
     */
    private function club(): array
    {
        $config = WebsiteConfig::singleton();
        $locale = app()->getLocale();

        $pick = function ($value) use ($locale) {
            if (! is_array($value)) {
                return $value;
            }

            return $value[$locale] ?? $value['en'] ?? $value['ar'] ?? collect($value)->filter()->first();
        };

        $branding = $config->branding ?? [];

        return [
            'name' => $pick($config->club_name),
            'logo' => $this->resolveMediaFile($branding['logo'] ?? null),
            'address' => $config->full_address ?: null,
            'phone' => $config->contact_phone,
            'email' => $config->contact_email,
            'currency' => $config->settings['currencySymbol'] ?? $config->settings['currency'] ?? 'DZD',
        ];
    }

    /**
     * mPDF needs a filesystem path for images, not a URL. Our stored media URLs
     * are host-relative (/media/... or /storage/...) or legacy absolute URLs;
     * resolve any of them to the public-disk file, which works in both the web
     * app and the packaged desktop app. Returns null if the file is missing.
     */
    private function resolveMediaFile(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $rel = preg_replace('#^https?://[^/]+#', '', $url);
        $rel = preg_replace('#^/?(?:media|storage)/#', '', $rel);

        $candidate = storage_path('app/public/'.$rel);
        if (! File::exists($candidate)) {
            $candidate = public_path('storage/'.$rel);
        }

        return File::exists($candidate) ? $candidate : null;
    }
}
