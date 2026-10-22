{{-- 80mm thermal ticket. Black only: thermal heads print no grey or colour, and light text fades first. --}}
@php
    $money = fn ($value) => number_format((float) $value, 2).' '.$club['currency'];
    // mPDF mirrors the tables only from <body dir>; values then sit at the far end of the line.
    $end = $rtl ? 'left' : 'right';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 8.5pt; color: #000; }
        .center { text-align: center; }
        .club { font-size: 11pt; font-weight: bold; }
        .small { font-size: 7pt; }
        .branch { font-size: 8.5pt; font-weight: bold; margin-top: 1mm; }
        .title { font-size: 11pt; font-weight: bold; margin-top: 2mm; }
        .copy { border: 0.4mm solid #000; padding: 0.6mm 2mm; font-weight: bold; font-size: 9pt; }
        .void { border: 0.6mm solid #000; padding: 1mm; font-size: 13pt; font-weight: bold; text-align: center; margin-top: 2mm; }
        .rule { border-top: 0.3mm dashed #000; margin: 1.6mm 0; height: 0; }
        .rule-strong { border-top: 0.5mm solid #000; margin: 1.6mm 0; height: 0; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows td { padding: 0.5mm 0; vertical-align: top; }
        table.rows td.value { font-weight: bold; }
        .amount-label { font-size: 8pt; font-weight: bold; }
        .amount { font-size: 17pt; font-weight: bold; }
        .code { font-size: 10pt; font-weight: bold; letter-spacing: 0.4mm; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    <div class="center">
        @if (!empty($club['logo']))
            <img src="{{ $club['logo'] }}" style="width:16mm; height:16mm;"><br>
        @endif
        <div class="club">{{ $club['name'] }}</div>
        @if (!empty($club['address']))
            <div class="small">{{ $club['address'] }}</div>
        @endif
        @if (!empty($club['phone']))
            <div class="small">{{ $club['phone'] }}</div>
        @endif
        @if ($branches)
            <div class="branch">{{ __('Branch') }}: {{ $branches }}</div>
        @endif

        <div class="title">{{ __('Payment Receipt') }}</div>
        <div style="margin-top:1mm;">
            <span class="copy">{{ $print->isOriginal() ? __('ORIGINAL') : __('DUPLICATE No. :n', ['n' => $print->copy_number]) }}</span>
        </div>
    </div>

    @if ($transaction->archived)
        <div class="void">{{ __('CANCELLED — NOT VALID') }}</div>
    @endif

    <div class="rule-strong"></div>

    <table class="rows">
        <tr><td>{{ __('Receipt No.') }}</td><td class="value" align="{{ $end }}">#{{ $receiptNumber }}</td></tr>
        <tr><td>{{ __('Payment date') }}</td><td class="value" align="{{ $end }}">{{ $transaction->transaction_date?->format('Y-m-d') }}</td></tr>
    </table>

    @if ($player)
        <div class="rule"></div>
        <table class="rows">
            <tr><td colspan="2" style="font-weight:bold; font-size:9.5pt;">{{ $player->fullname }}</td></tr>
            @if ($player->membership_id)
                <tr><td>{{ __('Membership No.') }}</td><td class="value" align="{{ $end }}">{{ $player->membership_id }}</td></tr>
            @endif
            @if ($player->category)
                <tr><td>{{ __('Category') }}</td><td class="value" align="{{ $end }}">{{ $player->category->localized_name }}</td></tr>
            @endif
        </table>
    @endif

    <div class="rule"></div>
    <table class="rows">
        @if ($subscription)
            <tr><td colspan="2" style="font-weight:bold;">{{ $subscription['label'] ?: $categoryLabel }}</td></tr>
            @if ($subscription['season'])
                <tr><td>{{ __('Season') }}</td><td class="value" align="{{ $end }}">{{ $subscription['season'] }}</td></tr>
            @endif
            @if ($subscription['exempt'])
                <tr><td>{{ __('Status') }}</td><td class="value" align="{{ $end }}">{{ __('Exempt') }}</td></tr>
            @else
                <tr><td>{{ __('Amount due') }}</td><td class="value" align="{{ $end }}">{{ $money($subscription['owed']) }}</td></tr>
                <tr><td>{{ __('Paid to date') }}</td><td class="value" align="{{ $end }}">{{ $money($subscription['paid']) }}</td></tr>
                <tr><td>{{ __('Remaining') }}</td><td class="value" align="{{ $end }}">{{ $subscription['remaining'] > 0 ? $money($subscription['remaining']) : __('Fully paid') }}</td></tr>
            @endif
        @else
            <tr><td colspan="2" style="font-weight:bold;">{{ $title }}</td></tr>
            <tr><td>{{ __('Category') }}</td><td class="value" align="{{ $end }}">{{ $categoryLabel }}</td></tr>
        @endif
    </table>

    <div class="rule"></div>
    <table class="rows">
        <tr><td>{{ __('Payment Method') }}</td><td class="value" align="{{ $end }}">{{ $paymentLabel ?: '—' }}</td></tr>
        @if ($transaction->payment_reference)
            <tr><td>{{ __('Reference') }}</td><td class="value" align="{{ $end }}">{{ $transaction->payment_reference }}</td></tr>
        @endif
        @if ($accountLabel)
            <tr><td>{{ __('Cash register') }}</td><td class="value" align="{{ $end }}">{{ $accountLabel }}</td></tr>
        @endif
        @if ($transaction->receivedBy)
            <tr><td>{{ __('Received by') }}</td><td class="value" align="{{ $end }}">{{ $transaction->receivedBy->name }}</td></tr>
        @endif
        @if ($transaction->recordedBy)
            <tr><td>{{ __('Recorded by') }}</td><td class="value" align="{{ $end }}">{{ $transaction->recordedBy->name }}</td></tr>
        @endif
    </table>

    <div class="rule-strong"></div>
    <div class="center">
        <div class="amount-label">{{ __('Amount paid') }}</div>
        <div class="amount">{{ $money($transaction->amount) }}</div>
    </div>
    <div class="rule-strong"></div>

    <div class="center" style="margin-top:1mm;">
        <barcode code="{{ $print->code }}" type="QR" size="0.88" error="M" disableborder="1" />
        <div class="small">{{ __('Verification code') }}</div>
        {{-- LTR, or Arabic reverses the digit groups and the typed code no longer matches. --}}
        <div class="code" dir="ltr"><bdo dir="ltr">{{ $print->displayCode() }}</bdo></div>
    </div>

    <div class="rule"></div>
    <table class="rows small">
        <tr><td>{{ __('Printed at') }}</td><td class="value" align="{{ $end }}"><bdo dir="ltr">{{ $print->created_at?->format('Y-m-d H:i') }}</bdo></td></tr>
        <tr><td>{{ __('Printed by') }}</td><td class="value" align="{{ $end }}">{{ $print->printedBy?->name ?? '—' }}</td></tr>
    </table>
    <div class="center small" style="margin-top:1mm;">{{ __('Keep this receipt. It can be checked at the club with its code.') }}</div>
</body>
</html>
