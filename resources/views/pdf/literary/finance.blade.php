@php
    $money = fn ($v) => number_format((float) $v, 2).' '.$data['currency'];
@endphp
<h2>{{ $l('literary.section.finance') }}</h2>
@if ($data['income'] == 0 && $data['expense'] == 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    <table class="kpis">
        <tr>
            <td><div class="value">{{ $money($data['income']) }}</div><div class="label">{{ $l('literary.pdf.income') }}</div></td>
            <td><div class="value">{{ $money($data['expense']) }}</div><div class="label">{{ $l('literary.pdf.expense') }}</div></td>
            <td><div class="value">{{ $money($data['balance']) }}</div><div class="label">{{ $l('literary.pdf.balance') }}</div></td>
        </tr>
    </table>
    @foreach (['top_income', 'top_expense'] as $list)
        @if ($data[$list])
            <h3>{{ $l('literary.pdf.'.$list) }}</h3>
            <table class="data">
                <tr><th>{{ $l('literary.pdf.category') }}</th><th>{{ $l('literary.pdf.amount') }}</th></tr>
                @foreach ($data[$list] as $row)
                    <tr><td class="start">{{ $row['name'] ?? $l('literary.pdf.uncategorized') }}</td><td><bdi dir="ltr">{{ $money($row['total']) }}</bdi></td></tr>
                @endforeach
            </table>
        @endif
    @endforeach
    <p class="muted">{{ $l('literary.pdf.finance_note') }}</p>
@endif
