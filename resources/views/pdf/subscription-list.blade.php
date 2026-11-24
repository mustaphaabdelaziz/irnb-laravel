@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    // The Arabic club name, then the short name at its end.
    $title = trim($clubName.' '.$shortName);
    $scope = array_filter([implode(' · ', $categories), $tab ? $L($tab) : null]);
    $footer = $title.' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
    $money = fn (float $value) => number_format($value, 2);
    // The currency once, in the amount headers.
    $unit = $currency ? ' ('.$currency.')' : '';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #1e293b; }
        table.head { width: 100%; border-bottom: 2px solid #0070c0; margin-bottom: 10px; }
        table.head td { vertical-align: middle; padding-bottom: 6px; }
        .club { font-size: 26px; font-weight: bold; color: #0070c0; text-align: center; }
        .title { font-size: 15px; font-weight: bold; color: #0f172a; }
        .sub { font-size: 10px; color: #64748b; margin-bottom: 6px; }
        table.stats { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.stats td { width: 20%; text-align: center; padding: 5px; border: 1px solid #b6cde3; background: #f5f9fd; }
        table.stats .n { font-size: 14px; font-weight: bold; color: #0f3d66; }
        table.stats .l { font-size: 9px; color: #64748b; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #e8f1fa; color: #0f3d66; font-size: 10px; padding: 5px; border: 1px solid #b6cde3; text-align: center; }
        table.rows td { padding: 5px; border: 1px solid #cbd5e1; }
        td.c { text-align: center; }
        .num { font-family: monospace; }
        .empty { padding: 20px; text-align: center; color: #94a3b8; }
    </style>
</head>
<body>
    <htmlpagefooter name="list"><div style="font-size:7px; color:#94a3b8; text-align:center;">{{ $footer }}</div></htmlpagefooter>
    <sethtmlpagefooter name="list" value="on" />

    {{-- The players list's header: logo | Arabic club name + short name | logo, always right-to-left. --}}
    <table class="head" dir="rtl">
        <tr>
            <td style="width:15%; text-align:center;">
                @if (!empty($logo))
                    <img src="{{ $logo }}" style="width:22mm; height:22mm;">
                @endif
            </td>
            <td style="width:70%; text-align:center;">
                <div class="club" dir="rtl">{{ $title }}</div>
            </td>
            <td style="width:15%; text-align:center;">
                @if (!empty($logo))
                    <img src="{{ $logo }}" style="width:22mm; height:22mm;">
                @endif
            </td>
        </tr>
    </table>

    <div class="title">{{ $subscription }}</div>
    <div class="sub">@if ($scope){{ implode(' · ', $scope) }} &middot; @endif<bdi dir="ltr">{{ $printedOn }}</bdi></div>

    <table class="stats">
        <tr>
            <td><div class="n">{{ $stats['total'] }}</div><div class="l">{{ $L('total_players') }}</div></td>
            <td><div class="n">{{ $stats['paid'] }}</div><div class="l">{{ $L('paid') }}</div></td>
            <td><div class="n">{{ $stats['partial'] }}</div><div class="l">{{ $L('partial') }}</div></td>
            <td><div class="n">{{ $stats['unpaid'] }}</div><div class="l">{{ $L('unpaid') }}</div></td>
            <td><div class="n"><bdi dir="ltr">{{ $stats['percent'] }}%</bdi></div><div class="l">{{ $L('subscription_paid_rate') }}</div></td>
        </tr>
    </table>

    @if ($rows->isEmpty())
        <div class="empty">{{ $L('no_players_in_list') }}</div>
    @else
        <table class="rows">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:18%;">{{ $L('col.membership_id') }}</th>
                    <th>{{ $L('col.full_name') }}</th>
                    <th style="width:13%;">{{ $L('col.amount_owed') }}{{ $unit }}</th>
                    <th style="width:13%;">{{ $L('col.amount_paid') }}{{ $unit }}</th>
                    <th style="width:13%;">{{ $L('remaining') }}{{ $unit }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="c num">{{ $loop->iteration }}</td>
                        <td class="c num" style="white-space:nowrap;">{{ $row['membership_id'] ?? '-' }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td class="c num"><bdi dir="ltr">{{ $money($row['owed']) }}</bdi></td>
                        <td class="c num"><bdi dir="ltr">{{ $money($row['paid']) }}</bdi></td>
                        <td class="c num"><bdi dir="ltr">{{ $money($row['remaining']) }}</bdi></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
