@php
    $money = fn ($value) => number_format((float) $value, 2).' '.$club['currency'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin: 4px 0 12px; }
        .copy { font-size: 11px; font-weight: bold; color: #0f172a; border: 1px solid #0f172a; padding: 2px 8px; }
        .void { border: 2px solid #b91c1c; color: #b91c1c; font-size: 16px; font-weight: bold; text-align: center; padding: 6px; margin-bottom: 12px; }
        table.info { width: 100%; border-collapse: collapse; }
        table.info td { padding: 7px 6px; border-bottom: 1px solid #e2e8f0; }
        table.info td.label { color: #64748b; width: 40%; }
        .amount { font-size: 20px; font-weight: bold; color: #02a85c; }
        .footer { margin-top: 28px; font-size: 10px; color: #94a3b8; text-align: center; }
        .badge { padding: 2px 8px; border-radius: 8px; background: #ecfdf5; color: #047857; font-weight: bold; }
        .code { font-size: 13px; font-weight: bold; letter-spacing: 1px; color: #0f172a; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @include('pdf.partials.header')

    <table style="width:100%; margin-bottom:8px;">
        <tr>
            <td><div class="title">{{ __('Payment Receipt') }}</div></td>
            <td style="text-align:{{ $rtl ? 'left' : 'right' }};">
                <span class="copy">{{ $print->isOriginal() ? __('ORIGINAL') : __('DUPLICATE No. :n', ['n' => $print->copy_number]) }}</span>
            </td>
        </tr>
    </table>

    @if ($transaction->archived)
        <div class="void">{{ __('CANCELLED — NOT VALID') }}</div>
    @endif

    <table class="info">
        <tr><td class="label">{{ __('Receipt No.') }}</td><td>#{{ $receiptNumber }}</td></tr>
        <tr><td class="label">{{ __('Title') }}</td><td>{{ $title }}</td></tr>
        <tr><td class="label">{{ __('Payment date') }}</td><td>{{ $transaction->transaction_date?->format('Y-m-d') }}</td></tr>
        @if ($relatedName)
            <tr><td class="label">{{ __('Member') }}</td><td>{{ $relatedName }}@if ($player?->membership_id) — {{ $player->membership_id }}@endif</td></tr>
            @if ($player?->category)
                <tr><td class="label">{{ __('Category') }}</td><td>{{ $player->category->localized_name }}</td></tr>
            @endif
        @endif
        @if ($branches)
            <tr><td class="label">{{ __('Branch') }}</td><td>{{ $branches }}</td></tr>
        @endif
        @if ($subscription)
            <tr><td class="label">{{ __('Subscription') }}</td><td>{{ $subscription['label'] ?: $categoryLabel }}@if ($subscription['season']) ({{ $subscription['season'] }})@endif</td></tr>
            @if ($subscription['exempt'])
                <tr><td class="label">{{ __('Status') }}</td><td>{{ __('Exempt') }}</td></tr>
            @else
                <tr><td class="label">{{ __('Amount due') }}</td><td>{{ $money($subscription['owed']) }}</td></tr>
                <tr><td class="label">{{ __('Paid to date') }}</td><td>{{ $money($subscription['paid']) }}</td></tr>
                <tr><td class="label">{{ __('Remaining') }}</td><td>{{ $subscription['remaining'] > 0 ? $money($subscription['remaining']) : __('Fully paid') }}</td></tr>
            @endif
        @else
            <tr><td class="label">{{ __('Type') }}</td><td>{{ $transaction->transaction_type === 'income' ? __('Income') : __('Expense') }}</td></tr>
            <tr><td class="label">{{ __('Category') }}</td><td>{{ $categoryLabel }}</td></tr>
        @endif
        <tr><td class="label">{{ __('Payment Method') }}</td><td>{{ $paymentLabel ?: '—' }}@if ($transaction->payment_reference) — {{ $transaction->payment_reference }}@endif</td></tr>
        @if ($accountLabel)
            <tr><td class="label">{{ __('Cash register') }}</td><td>{{ $accountLabel }}</td></tr>
        @endif
        <tr><td class="label">{{ __('Status') }}</td><td><span class="badge">{{ $statusLabel }}</span></td></tr>
        @if ($transaction->description)
            <tr><td class="label">{{ __('Description') }}</td><td>{{ $transaction->description }}</td></tr>
        @endif
        @if ($transaction->receivedBy)
            <tr><td class="label">{{ __('Received by') }}</td><td>{{ $transaction->receivedBy->name }}</td></tr>
        @endif
        @if ($transaction->recordedBy)
            <tr><td class="label">{{ __('Recorded by') }}</td><td>{{ $transaction->recordedBy->name }}</td></tr>
        @endif
        <tr>
            <td class="label">{{ __('Amount paid') }}</td>
            <td><span class="amount">{{ $money($transaction->amount) }}</span></td>
        </tr>
        <tr><td class="label">{{ __('Printed at') }}</td><td><bdo dir="ltr">{{ $print->created_at?->format('Y-m-d H:i') }}</bdo></td></tr>
        <tr><td class="label">{{ __('Printed by') }}</td><td>{{ $print->printedBy?->name ?? '—' }}</td></tr>
    </table>

    <table style="width:100%; margin-top:18px;">
        <tr>
            <td style="width:32mm;"><barcode code="{{ $print->code }}" type="QR" size="1" error="M" disableborder="1" /></td>
            <td style="vertical-align:middle; font-size:10px; color:#64748b;">
                {{ __('Verification code') }}<br>
                {{-- LTR, or Arabic reverses the digit groups and the typed code no longer matches. --}}
                <div class="code" dir="ltr"><bdo dir="ltr">{{ $print->displayCode() }}</bdo></div>
                {{ __('Keep this receipt. It can be checked at the club with its code.') }}
            </td>
        </tr>
    </table>

    <div class="footer">{{ $club['name'] }}</div>
</body>
</html>
