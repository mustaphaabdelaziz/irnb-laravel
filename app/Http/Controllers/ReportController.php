<?php

namespace App\Http\Controllers;

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

        // mPDF needs a filesystem path (or http URL) for images — map local /storage URLs.
        $logo = $branding['logo'] ?? null;
        if ($logo && Str::startsWith($logo, '/storage/')) {
            $candidate = public_path(ltrim($logo, '/'));
            $logo = File::exists($candidate) ? $candidate : null;
        }

        return [
            'name' => $pick($config->club_name),
            'logo' => $logo,
            'address' => $config->full_address ?: null,
            'phone' => $config->contact_phone,
            'email' => $config->contact_email,
            'currency' => $config->settings['currencySymbol'] ?? $config->settings['currency'] ?? 'DZD',
        ];
    }
}
