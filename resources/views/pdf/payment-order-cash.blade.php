@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ').' '.$club['currency'];
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
        table.info { width: 100%; border-collapse: collapse; }
        table.info td { padding: 7px 6px; border: 1px solid #cbd5e1; }
        table.info td.label { background: #f8fafc; color: #475569; width: 35%; font-weight: bold; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @include('pdf.partials.header')

    @if ($order->status === 'draft')
        <div class="draft">{{ $L('po.pdf.draft') }}</div>
    @endif

    <div class="title">{{ $L('po.pdf.cash_title') }}</div>
    <div class="ref">{{ $L('po.number') }} <bdo dir="ltr">{{ $order->reference }}</bdo> — <bdo dir="ltr">{{ $order->order_date->format('d/m/Y') }}</bdo></div>

    <table class="info">
        <tr><td class="label">{{ $L('po.pdf.paid_to') }}</td><td>{{ $beneficiary }}</td></tr>
        <tr><td class="label">{{ $L('amount') }}</td><td><bdo dir="ltr">{{ $money($order->amount) }}</bdo></td></tr>
        <tr><td class="label">{{ $L('po.pdf.amount_words') }}</td><td>{{ $words }}</td></tr>
        <tr><td class="label">{{ $L('po.subject') }}</td><td>{{ $order->subject }}</td></tr>
        @if ($order->payment_reference)
            <tr><td class="label">{{ $L('po.payment_reference') }}</td><td><bdo dir="ltr">{{ $order->payment_reference }}</bdo></td></tr>
        @endif
    </table>

    <table style="width:100%; margin-top:30px; text-align:center;">
        <tr>
            <td style="width:50%;"></td>
            <td style="width:50%; vertical-align:top;"><b>{{ $L('po.pdf.received_by') }}</b>@include('pdf.partials.sign-line')</td>
        </tr>
    </table>

    @include('pdf.partials.signatures')
</body>
</html>
