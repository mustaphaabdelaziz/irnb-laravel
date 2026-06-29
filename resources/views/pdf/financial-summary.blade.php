<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin: 4px 0 12px; }
        .cards { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .cards td { width: 25%; padding: 10px; border: 1px solid #e2e8f0; text-align: center; }
        .cards .k { font-size: 10px; color: #64748b; text-transform: uppercase; }
        .cards .v { font-size: 15px; font-weight: bold; }
        table.brk { width: 100%; border-collapse: collapse; }
        table.brk th { background: #f1f5f9; padding: 7px; text-align: start; font-size: 11px; color: #475569; }
        table.brk td { padding: 7px; border-bottom: 1px solid #eef2f6; }
        .pos { color: #047857; } .neg { color: #be123c; }
        .footer { margin-top: 24px; font-size: 10px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ __('Financial Summary') }} — {{ $year }}</div>

    <table class="cards">
        <tr>
            <td><div class="k">{{ __('Total Income') }}</div><div class="v pos">{{ number_format($totalIncome, 2) }}</div></td>
            <td><div class="k">{{ __('Total Expense') }}</div><div class="v neg">{{ number_format($totalExpense, 2) }}</div></td>
            <td><div class="k">{{ __('Donations') }}</div><div class="v">{{ number_format($totalDonations, 2) }}</div></td>
            <td><div class="k">{{ __('Net Balance') }}</div><div class="v {{ $netBalance >= 0 ? 'pos' : 'neg' }}">{{ number_format($netBalance, 2) }}</div></td>
        </tr>
    </table>

    <table class="brk">
        <thead>
            <tr>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Category') }}</th>
                <th style="text-align:end;">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($byCategory as $row)
                <tr>
                    <td>{{ $row->transaction_type === 'income' ? __('Income') : __('Expense') }}</td>
                    <td>{{ $row->category }}</td>
                    <td style="text-align:end;">{{ number_format((float) $row->total, 2) }} {{ $club['currency'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3" style="text-align:center; color:#94a3b8;">—</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">{{ $club['name'] }} — {{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
