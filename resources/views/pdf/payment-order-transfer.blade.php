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
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.info td { padding: 7px 6px; border: 1px solid #cbd5e1; }
        table.info td.label { background: #f8fafc; color: #475569; width: 35%; font-weight: bold; }
        .section { font-weight: bold; margin: 10px 0 4px; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @include('pdf.partials.header')

    @if ($order->status === 'draft')
        <div class="draft">{{ $L('po.pdf.draft') }}</div>
    @endif

    <div class="title">{{ $L('po.pdf.transfer_title') }}</div>
    <div class="ref">{{ $L('po.number') }} <bdo dir="ltr">{{ $order->reference }}</bdo> — <bdo dir="ltr">{{ $order->order_date->format('d/m/Y') }}</bdo></div>

    <p>{{ $L('po.pdf.transfer_intro') }}</p>

    <div class="section">{{ $L('po.pdf.debit') }}</div>
    <table class="info">
        <tr><td class="label">{{ $L('po.pdf.account_holder') }}</td><td>{{ $club['name'] }}</td></tr>
        <tr><td class="label">{{ $L('supplier.field.rib') }}</td><td><bdo dir="ltr">{{ $clubRib ?? '' }}</bdo></td></tr>
    </table>

    <div class="section">{{ $L('po.pdf.credit') }}</div>
    <table class="info">
        <tr><td class="label">{{ $L('po.beneficiary') }}</td><td>{{ $beneficiary }}</td></tr>
        <tr><td class="label">{{ $L('supplier.field.bank_name') }}</td><td>{{ $order->beneficiary_bank }}</td></tr>
        <tr><td class="label">{{ $L('supplier.field.rib') }}</td><td><bdo dir="ltr">{{ $order->beneficiary_rib }}</bdo></td></tr>
    </table>

    <table class="info">
        <tr><td class="label">{{ $L('amount') }}</td><td><bdo dir="ltr">{{ $money($order->amount) }}</bdo></td></tr>
        <tr><td class="label">{{ $L('po.pdf.amount_words') }}</td><td>{{ $words }}</td></tr>
        <tr><td class="label">{{ $L('po.subject') }}</td><td>{{ $order->subject }}</td></tr>
        @if ($order->payment_reference)
            <tr><td class="label">{{ $L('po.payment_reference') }}</td><td><bdo dir="ltr">{{ $order->payment_reference }}</bdo></td></tr>
        @endif
    </table>

    @include('pdf.partials.signatures')
</body>
</html>
