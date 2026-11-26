@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    // Free text: Latin lines keep their own left-to-right order inside the RTL page; line breaks become <br /> between bdo's.
    $T = fn (?string $v) => \App\Support\PdfText::ltrIfLatin($v === null || trim($v) === '' ? '—' : $v);
    // References and dates read left to right; a missing one is a dash.
    $ltr = fn (?string $v) => $v === null || $v === '' ? '—' : new \Illuminate\Support\HtmlString('<bdo dir="ltr">'.e($v).'</bdo>');
    $cancelled = $order->status === \App\Models\MissionOrder::CANCELLED;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; line-height: 1.5; }
        .banner { text-align: center; font-weight: bold; color: #b91c1c; border: 1px solid #b91c1c; padding: 4px; margin-bottom: 10px; }
        .title { font-size: 20px; font-weight: bold; text-align: center; margin: 4px 0 14px; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.meta td { padding: 2px 0; vertical-align: top; }
        table.fields { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.fields td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        table.fields td.label { width: 32%; color: #64748b; }
        .label { color: #64748b; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @foreach ($travelers as $i => $traveler)
        @if ($i > 0)
            <pagebreak />
        @endif

        @include('pdf.partials.header')

        @if ($cancelled)
            <div class="banner">{{ $L('mis.pdf.cancelled') }}</div>
        @endif

        <div class="title">{{ $L('mis.pdf.title') }}</div>

        <table class="meta">
            <tr>
                <td><span class="label">{{ $L('mreg.pdf.ref') }}</span> {{ $ltr($reference) }}</td>
                <td style="text-align: {{ $rtl ? 'left' : 'right' }};"><span class="label">{{ $L('mreg.pdf.date') }}</span> {{ $ltr($order->order_date?->format('d/m/Y')) }}</td>
            </tr>
        </table>

        <table class="fields">
            <tr><td class="label">{{ $L('mis.pdf.name') }}</td><td>{{ $T($traveler->name) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.function') }}</td><td>{{ $T($traveler->function) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.destination') }}</td><td>{{ $T($order->destination) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.reason') }}</td><td>{{ $T($order->reason) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.departure') }}</td><td>{{ $ltr($order->departure_date?->format('d/m/Y')) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.return') }}</td><td>{{ $ltr($order->return_date?->format('d/m/Y')) }}</td></tr>
            <tr><td class="label">{{ $L('mis.pdf.transport') }}</td><td>{{ $T($order->transport) }}</td></tr>
        </table>

        {{-- President + Secretary General (blank line when nobody holds the role). --}}
        <table style="width:100%; margin-top:30px; text-align:center; font-size:12px; page-break-inside: avoid;">
            <tr>
                <td style="width:50%; vertical-align:top;">
                    <div style="font-weight:bold;">{{ $L('mreg.pdf.secretary') }}</div>
                    <div style="margin-top:4px;">{{ $signatories['secretary'] ?? '' }}</div>
                    @include('pdf.partials.sign-line')
                </td>
                <td style="width:50%; vertical-align:top;">
                    <div style="font-weight:bold;">{{ $L('po.pdf.president') }}</div>
                    <div style="margin-top:4px;">{{ $signatories['president'] ?? '' }}</div>
                    @include('pdf.partials.sign-line')
                </td>
            </tr>
        </table>
    @endforeach
</body>
</html>
