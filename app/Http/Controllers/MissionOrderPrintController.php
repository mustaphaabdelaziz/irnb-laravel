<?php

namespace App\Http\Controllers;

use App\Models\MissionOrder;
use App\Models\MissionOrderTraveler;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Pdf\BoardSignatories;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Services\Secretariat\MissionOrders;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * «أمر بمهمة» / Ordre de mission on A4, in the app language: one sheet per
 * traveler, every traveler in one PDF or one traveler's sheet. The order's
 * reference is looked up, never taken (that is missions.register). Cancelled
 * orders (with a banner) and imported orders print too.
 */
class MissionOrderPrintController extends Controller
{
    public function __construct(
        private readonly PdfService $pdf,
        private readonly MissionOrders $missions,
    ) {}

    public function all(Request $request, MissionOrder $missionOrder): Response
    {
        return $this->sheets($request, $missionOrder, $missionOrder->travelers()->get());
    }

    public function traveler(Request $request, MissionOrder $missionOrder, MissionOrderTraveler $traveler): Response
    {
        abort_unless($traveler->mission_order_id === $missionOrder->id, 404);

        return $this->sheets($request, $missionOrder, collect([$traveler]), $traveler);
    }

    /** @param Collection<int, MissionOrderTraveler> $travelers */
    private function sheets(Request $request, MissionOrder $order, Collection $travelers, ?MissionOrderTraveler $one = null): Response
    {
        abort_if($travelers->isEmpty(), 404);
        // Lookup only: opening, refreshing or reprinting never takes a number.
        $reference = $this->missions->reference($order);
        $locale = app()->getLocale();

        $html = view('pdf.mission-order', [
            'club' => ClubHeader::data(),
            'rtl' => $locale === 'ar',
            'order' => $order,
            'travelers' => $travelers->values(),
            'reference' => $reference,
            'signatories' => BoardSignatories::names(),
        ])->render();

        ActivityRecorder::record($request->user(), ActivityAction::MISSION_ORDER_PRINTED, $order, [
            'reference' => $reference,
            'traveler_id' => $one?->id,
        ]);

        $slug = trim((string) preg_replace('/[^0-9A-Za-z]+/', '-', (string) $reference), '-');
        $name = 'ordre-de-mission-'.($slug !== '' ? $slug : $order->id).($one !== null ? '-'.$one->sort_order : '');

        return $this->pdf->stream($html, $name.'.pdf', $locale === 'ar');
    }
}
