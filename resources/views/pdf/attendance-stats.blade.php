@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $pct = fn ($value) => $value === null ? '—' : number_format((float) $value, 1).'%';
    $hours = fn ($value) => number_format((float) $value, 1);
    $statuses = array_keys($labels);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 9px; color: #1e293b; }
        .title { font-size: 16px; font-weight: bold; color: #02a85c; margin-bottom: 2px; }
        .sub { font-size: 11px; color: #64748b; margin-bottom: 8px; }
        h2 { font-size: 12px; color: #0f172a; margin: 12px 0 4px; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #f1f5f9; color: #334155; font-size: 8px; padding: 4px 3px; border: 1px solid #e2e8f0; }
        table.rows td { padding: 3px; border: 1px solid #e2e8f0; }
        .num { text-align: center; }
        .pct { color: #64748b; font-size: 7px; }
        .empty { padding: 16px; text-align: center; color: #94a3b8; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('att.stats_title') }}</div>
    <div class="sub">{{ $L('activity.period_label') }}: <bdi dir="ltr">{{ $period['label'] }}</bdi> &middot; {{ $categoryName }}</div>

    <table class="rows">
        <tr>
            <th>{{ $L('att.stats.held_sessions') }}</th>
            <th>{{ $L('att.stats.cancelled_sessions') }}</th>
            <th>{{ $L('att.stats.marks') }}</th>
            @foreach ($statuses as $status)
                <th><span style="color: {{ $codes[$status]['color'] }};">&#9632;</span> {{ $labels[$status] }}</th>
            @endforeach
            <th>{{ $L('att.col.late_minutes') }}</th>
            <th>{{ $L('att.col.missed_hours') }}</th>
            <th>{{ $L('att.col.score_pct') }}</th>
        </tr>
        <tr>
            <td class="num">{{ $totals['held'] }}</td>
            <td class="num">{{ $totals['cancelled'] }}</td>
            <td class="num">{{ $totals['expected'] }}</td>
            @foreach ($statuses as $status)
                <td class="num">{{ $totals['counts'][$status] }} <span class="pct"><bdi dir="ltr">{{ $pct($totals['pct'][$status]) }}</bdi></span></td>
            @endforeach
            <td class="num">{{ $totals['late_minutes'] }}</td>
            <td class="num">{{ $hours($totals['missed_hours']) }}</td>
            <td class="num"><bdi dir="ltr">{{ $pct($totals['score_pct']) }}</bdi></td>
        </tr>
    </table>

    <h2>{{ $L('att.stats.by_category') }}</h2>
    <table class="rows">
        <tr>
            <th>{{ $L('att.category') }}</th>
            <th>{{ $L('att.state.held') }}</th>
            <th>{{ $L('att.state.cancelled') }}</th>
            <th>{{ $L('att.kind.preseason') }}</th>
            <th>{{ $L('att.stats.marks') }}</th>
            @foreach ($statuses as $status)
                <th>{{ $labels[$status] }}</th>
            @endforeach
            <th>{{ $L('att.col.late_minutes') }}</th>
            <th>{{ $L('att.col.missed_hours') }}</th>
        </tr>
        @foreach ($categoryRows as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $row['held'] }}</td>
                <td class="num">{{ $row['cancelled'] }}</td>
                <td class="num"><bdi dir="ltr">{{ $row['preseason'] ? ($row['preseason']['target'] ? $row['preseason']['done'].'/'.$row['preseason']['target'] : $row['preseason']['done']) : '—' }}</bdi></td>
                <td class="num">{{ $row['expected'] }}</td>
                @foreach ($statuses as $status)
                    <td class="num">{{ $row['counts'][$status] }} <span class="pct"><bdi dir="ltr">{{ $pct($row['pct'][$status]) }}</bdi></span></td>
                @endforeach
                <td class="num">{{ $row['late_minutes'] }}</td>
                <td class="num">{{ $hours($row['missed_hours']) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ $L('att.stats.top') }} &middot; {{ $L('att.stats.bottom') }}</h2>
    <div class="sub">{{ str_replace('{n}', (string) $ranking['min_expected'], $L('att.stats.ranking_help')) }}</div>
    @if (empty($ranking['top']) && empty($ranking['bottom']))
        <div class="empty">{{ $L('att.stats.no_ranking') }}</div>
    @else
        <table class="rows">
            <tr>
                <th style="width:50%;">{{ $L('att.stats.top') }}</th>
                <th style="width:50%;">{{ $L('att.stats.bottom') }}</th>
            </tr>
            <tr>
                <td>
                    @forelse ($ranking['top'] as $i => $row)
                        <div>{{ $i + 1 }}. {{ $row['name'] }} <span class="pct">{{ $row['category'] ?? '' }}</span> — <bdi dir="ltr">{{ $pct($row['score_pct']) }}</bdi></div>
                    @empty
                        <span class="pct">—</span>
                    @endforelse
                </td>
                <td>
                    @forelse ($ranking['bottom'] as $i => $row)
                        <div>{{ $i + 1 }}. {{ $row['name'] }} <span class="pct">{{ $row['category'] ?? '' }}</span> — <bdi dir="ltr">{{ $pct($row['score_pct']) }}</bdi></div>
                    @empty
                        <span class="pct">—</span>
                    @endforelse
                </td>
            </tr>
        </table>
    @endif

    <h2>{{ $L('att.stats.players') }}</h2>
    @if (empty($players))
        <div class="empty">{{ $L('att.stats.no_data') }}</div>
    @else
        <table class="rows">
            <tr>
                <th>#</th>
                <th>{{ $L('col.member') }}</th>
                <th>{{ $L('col.category') }}</th>
                <th>{{ $L('att.col.expected') }}</th>
                @foreach ($statuses as $status)
                    <th>{{ $labels[$status] }}</th>
                @endforeach
                <th>{{ $L('att.col.late_minutes') }}</th>
                <th>{{ $L('att.col.missed_hours') }}</th>
                <th>{{ $L('att.col.score_pct') }}</th>
            </tr>
            @foreach ($players as $row)
                <tr>
                    <td class="num">{{ $loop->iteration }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['category'] ?? '—' }}</td>
                    <td class="num">{{ $row['expected'] }}</td>
                    @foreach ($statuses as $status)
                        <td class="num">{{ $row['counts'][$status] }} <span class="pct"><bdi dir="ltr">{{ $pct($row['pct'][$status]) }}</bdi></span></td>
                    @endforeach
                    <td class="num">{{ $row['late_minutes'] }}</td>
                    <td class="num">{{ $hours($row['missed_hours']) }}</td>
                    <td class="num"><bdi dir="ltr">{{ $pct($row['score_pct']) }}</bdi></td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
