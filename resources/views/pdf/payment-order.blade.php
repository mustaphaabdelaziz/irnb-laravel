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
        table.info td { padding: 7px 6px; border: 1px solid #cbd5e1; vertical-align: top; }
        table.info td.label { background: #f8fafc; color: #475569; width: 35%; font-weight: bold; }
        .amount { font-size: 16px; font-weight: bold; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @include('pdf.partials.header')

    @if ($order->status === 'draft')
        <div class="draft">{{ $L('po.pdf.draft') }}</div>
    @endif

    <div class="title">{{ $L('po.pdf.payment_title') }}</div>
    <div class="ref">{{ $L('po.number') }} <bdo dir="ltr">{{ $order->reference }}</bdo> — <bdo dir="ltr">{{ $order->order_date->format('d/m/Y') }}</bdo></div>

    <table class="info">
        <tr><td class="label">{{ $L('po.subject') }}</td><td>{{ $order->subject }}</td></tr>
        <tr><td class="label">{{ $L('amount') }}</td><td class="amount"><bdo dir="ltr">{{ $money($order->amount) }}</bdo></td></tr>
        <tr><td class="label">{{ $L('po.pdf.amount_words') }}</td><td>{{ $words }}</td></tr>
        <tr><td class="label">{{ $L('po.beneficiary') }}</td><td>{{ $beneficiary }}</td></tr>
        @if ($order->beneficiary_bank)
            <tr><td class="label">{{ $L('supplier.field.bank_name') }}</td><td>{{ $order->beneficiary_bank }}</td></tr>
        @endif
        @if ($order->beneficiary_rib)
            <tr><td class="label">{{ $L('supplier.field.rib') }}</td><td><bdo dir="ltr">{{ $order->beneficiary_rib }}</bdo></td></tr>
        @endif
        <tr><td class="label">{{ $L('payment_method') }}</td><td>{{ $method }}</td></tr>
        @if ($order->payment_reference)
            <tr><td class="label">{{ $order->payment_method === 'cheque' ? $L('tx.cheque_number') : $L('po.payment_reference') }}</td><td><bdo dir="ltr">{{ $order->payment_reference }}</bdo></td></tr>
        @endif
        @if ($order->invoice_number)
            <tr><td class="label">{{ $L('tx.invoice_number') }}</td><td><bdo dir="ltr">{{ $order->invoice_number }}</bdo>@if ($order->invoice_date) — <bdo dir="ltr">{{ $order->invoice_date->format('d/m/Y') }}</bdo>@endif</td></tr>
        @endif
        @if ($order->boardMeeting)
            <tr><td class="label">{{ $L('po.pdf.meeting') }}</td><td>@if ($order->boardMeeting->pv_number)<bdo dir="ltr">{{ $order->boardMeeting->pv_number }}</bdo> — @endif<bdo dir="ltr">{{ $order->boardMeeting->meeting_date?->format('d/m/Y') }}</bdo></td></tr>
        @endif
        @if ($order->observation)
            <tr><td class="label">{{ $L('po.observation') }}</td><td>{!! nl2br(e($order->observation)) !!}</td></tr>
        @endif
    </table>

    @include('pdf.partials.signatures')
</body>
</html>
