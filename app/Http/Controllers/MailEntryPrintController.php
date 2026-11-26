<?php

namespace App\Http\Controllers;

use App\Models\MailEntry;
use App\Services\Pdf\BoardSignatories;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use Symfony\Component\HttpFoundation\Response;

/** An outgoing letter of the mail register on the club letterhead (A4, app language). */
class MailEntryPrintController extends Controller
{
    public function __construct(private readonly PdfService $pdf) {}

    public function __invoke(MailEntry $mailEntry): Response
    {
        // Only an active outgoing entry with a text is a letter to print.
        abort_unless($mailEntry->isPrintable(), 404);

        $locale = app()->getLocale();
        $html = view('pdf.mail-letter', [
            'club' => ClubHeader::data(),
            'rtl' => $locale === 'ar',
            'entry' => $mailEntry,
            'signatories' => BoardSignatories::names(),
        ])->render();

        return $this->pdf->stream($html, 'courrier-'.str_replace('/', '-', $mailEntry->reference).'.pdf', $locale === 'ar');
    }
}
