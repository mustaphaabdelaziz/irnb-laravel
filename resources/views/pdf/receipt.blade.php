<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin: 4px 0 12px; }
        table.info { width: 100%; border-collapse: collapse; }
        table.info td { padding: 7px 6px; border-bottom: 1px solid #e2e8f0; }
        table.info td.label { color: #64748b; width: 40%; }
        .amount { font-size: 20px; font-weight: bold; color: #02a85c; }
        .footer { margin-top: 28px; font-size: 10px; color: #94a3b8; text-align: center; }
        .badge { padding: 2px 8px; border-radius: 8px; background: #ecfdf5; color: #047857; font-weight: bold; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ __('Payment Receipt') }}</div>

    <table class="info">
        <tr><td class="label">{{ __('Receipt No.') }}</td><td>#{{ $receiptNumber }}</td></tr>
        <tr><td class="label">{{ __('Date') }}</td><td>{{ optional($transaction->transaction_date)->format('Y-m-d') }}</td></tr>
        @if ($relatedName)
            <tr><td class="label">{{ __('Member') }}</td><td>{{ $relatedName }}</td></tr>
        @endif
        <tr><td class="label">{{ __('Type') }}</td><td>{{ $transaction->transaction_type === 'income' ? __('Income') : __('Expense') }}</td></tr>
        <tr><td class="label">{{ __('Category') }}</td><td>{{ $transaction->category }}</td></tr>
        <tr><td class="label">{{ __('Payment Method') }}</td><td>{{ $transaction->payment_method ?: '—' }}</td></tr>
        <tr><td class="label">{{ __('Status') }}</td><td><span class="badge">{{ $transaction->status }}</span></td></tr>
        @if ($transaction->description)
            <tr><td class="label">{{ __('Description') }}</td><td>{{ $transaction->description }}</td></tr>
        @endif
        @if ($transaction->recordedBy)
            <tr><td class="label">{{ __('Recorded by') }}</td><td>{{ $transaction->recordedBy->name }}</td></tr>
        @endif
        <tr>
            <td class="label">{{ __('Amount') }}</td>
            <td><span class="amount">{{ number_format((float) $transaction->amount, 2) }} {{ $club['currency'] }}</span></td>
        </tr>
    </table>

    <div class="footer">{{ $club['name'] }} — {{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
