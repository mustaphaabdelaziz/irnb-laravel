@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ').' '.$club['currency'];
    $supplier = $order->supplier;
    // Free text without Arabic letters keeps its own left-to-right order inside the RTL page.
    // Line breaks stay outside the bdo (mPDF does not grow the row for a <br> inside it), so each line is wrapped on its own.
    $T = fn (?string $v) => new \Illuminate\Support\HtmlString(implode('<br />', array_map(
        fn (string $line) => preg_match('/\p{Arabic}/u', $line) === 0 ? '<bdo dir="ltr">'.e($line).'</bdo>' : e($line),
        preg_split('/\r\n|\r|\n/', (string) $v)
    )));
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .title { font-size: 20px; font-weight: bold; text-align: center; margin: 6px 0 2px; }
        .ref { text-align: center; font-size: 13px; margin-bottom: 14px; }
        .draft { border: 2px solid #b45309; color: #b45309; font-size: 16px; font-weight: bold; text-align: center; padding: 6px; margin-bottom: 12px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.info td { padding: 7px 6px; border: 1px solid #cbd5e1; vertical-align: top; }
        table.info td.label { background: #f8fafc; color: #475569; width: 35%; font-weight: bold; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.lines th { background: #f1f5f9; color: #334155; padding: 6px; border: 1px solid #cbd5e1; font-size: 11px; }
        table.lines td { padding: 6px; border: 1px solid #cbd5e1; vertical-align: top; }
        table.lines td.num { text-align: center; white-space: nowrap; }
        table.lines td.total-label { font-weight: bold; background: #f8fafc; }
        table.lines td.total { font-weight: bold; font-size: 14px; text-align: center; white-space: nowrap; }
        .words { margin: 6px 0 12px; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @include('pdf.partials.header')

    @if ($order->status === \App\Models\PurchaseOrder::DRAFT)
        <div class="draft">{{ $L('pur.pdf.draft') }}</div>
    @endif

    <div class="title">{{ $L('pur.pdf.title') }}</div>
    <div class="ref">{{ $L('po.number') }} <bdo dir="ltr">{{ $order->reference }}</bdo> — <bdo dir="ltr">{{ $order->order_date->format('d/m/Y') }}</bdo></div>

    <table class="info">
        <tr><td class="label">{{ $L('pur.supplier') }}</td><td>{{ $T($supplier?->name ?? '—') }}</td></tr>
        @if ($supplier?->address)
            <tr><td class="label">{{ $L('supplier.field.address') }}</td><td>{{ $T($supplier->address) }}</td></tr>
        @endif
        @if ($supplier?->phone)
            <tr><td class="label">{{ $L('supplier.field.phone') }}</td><td><bdo dir="ltr">{{ $supplier->phone }}</bdo></td></tr>
        @endif
        @if ($order->requested_by)
            <tr><td class="label">{{ $L('pur.requested_by') }}</td><td>{{ $T($order->requested_by) }}</td></tr>
        @endif
        @if ($order->delivery_mode)
            <tr><td class="label">{{ $L('pur.delivery_mode') }}</td><td>{{ $T($order->delivery_mode) }}</td></tr>
        @endif
        <tr><td class="label">{{ $L('pur.payment_mode') }}</td><td>{{ $method }}</td></tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:6%">#</th>
                <th>{{ $L('designation') }}</th>
                <th style="width:12%">{{ $L('quantity') }}</th>
                <th style="width:20%">{{ $L('unit_price') }}</th>
                <th style="width:20%">{{ $L('amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->lines as $i => $line)
                <tr>
                    <td class="num"><bdo dir="ltr">{{ $i + 1 }}</bdo></td>
                    <td>{{ $T($line->designation) }}</td>
                    <td class="num"><bdo dir="ltr">{{ $line->quantity }}</bdo></td>
                    <td class="num">@if ($line->unit_price !== null)<bdo dir="ltr">{{ $money($line->unit_price) }}</bdo>@endif</td>
                    <td class="num">@if ($line->amount !== null)<bdo dir="ltr">{{ $money($line->amount) }}</bdo>@endif</td>
                </tr>
            @endforeach
            @if ($priced)
                <tr>
                    <td colspan="4" class="total-label">{{ $L('total') }}</td>
                    <td class="total"><bdo dir="ltr">{{ $money($total) }}</bdo></td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($priced)
        <div class="words"><b>{{ $L('po.pdf.amount_words') }} :</b> {{ $words }}</div>
    @endif

    @if ($order->observation)
        <table class="info">
            <tr><td class="label">{{ $L('po.observation') }}</td><td>{{ $T($order->observation) }}</td></tr>
        </table>
    @endif

    @include('pdf.partials.signatures')
</body>
</html>
