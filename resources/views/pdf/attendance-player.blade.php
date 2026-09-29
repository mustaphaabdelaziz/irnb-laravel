@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $pct = fn ($value) => $value === null ? '—' : number_format((float) $value, 1).'%';
    $statuses = array_keys($labels);
    $preseasonLine = null;
    if ($preseason) {
        $preseasonLine = $preseason['target']
            ? strtr($L('att.preseason_progress'), ['{season}' => $preseason['season'], '{done}' => $preseason['done'], '{target}' => $preseason['target']])
            : strtr($L('att.preseason_no_target'), ['{season}' => $preseason['season'], '{done}' => $preseason['done']]);
    }
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin: 6px 0 10px; }
        .photo { width: 70px; height: 70px; border-radius: 8px; }
        .muted { color: #64748b; }
        h2 { font-size: 12px; color: #0f172a; margin: 12px 0 4px; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #f1f5f9; color: #334155; font-size: 9px; padding: 4px; border: 1px solid #e2e8f0; }
        table.rows td { padding: 4px; border: 1px solid #e2e8f0; vertical-align: top; }
        .num { text-align: center; }
        .pct { color: #64748b; font-size: 8px; }
        .empty { padding: 16px; text-align: center; color: #94a3b8; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('att.report_title') }}</div>

    <table style="width:100%; margin-bottom:8px;">
        <tr>
            @if (!empty($photo))
                <td style="width:80px; vertical-align:top;"><img class="photo" src="{{ $photo }}"></td>
            @endif
            <td style="vertical-align:top;">
                <div style="font-size:14px; font-weight:bold;">{{ $player->fullname }}</div>
                <div style="font-family:monospace;">{{ $player->membership_id }}</div>
                <div class="muted">{{ $player->category?->localized_name }}</div>
                <div class="muted">{{ $L('activity.period_label') }}: <bdi dir="ltr">{{ $period['label'] }}</bdi></div>
                @if ($preseasonLine)
                    <div class="muted">{{ $preseasonLine }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="rows">
        <tr>
            <th>{{ $L('att.col.expected') }}</th>
            @foreach ($statuses as $status)
                <th><span style="color: {{ $codes[$status]['color'] }};">&#9632;</span> {{ $labels[$status] }}</th>
            @endforeach
            <th>{{ $L('att.col.late_minutes') }}</th>
            <th>{{ $L('att.col.missed_hours') }}</th>
            <th>{{ $L('att.col.score') }}</th>
            <th>{{ $L('att.col.score_pct') }}</th>
        </tr>
        <tr>
            <td class="num">{{ $summary['expected'] }}</td>
            @foreach ($statuses as $status)
                <td class="num">{{ $summary['counts'][$status] }} <span class="pct"><bdi dir="ltr">{{ $pct($summary['pct'][$status]) }}</bdi></span></td>
            @endforeach
            <td class="num">{{ $summary['late_minutes'] }}</td>
            <td class="num">{{ number_format((float) $summary['missed_hours'], 1) }}</td>
            <td class="num"><bdi dir="ltr">{{ number_format((float) $summary['score'], 2) }}</bdi></td>
            <td class="num"><bdi dir="ltr">{{ $pct($summary['score_pct']) }}</bdi></td>
        </tr>
    </table>

    <h2>{{ $L('att.profile.sessions') }}</h2>
    @if (empty($sessions))
        <div class="empty">{{ $L('att.stats.no_data') }}</div>
    @else
        <table class="rows">
            <tr>
                <th>{{ $L('att.date') }}</th>
                <th>{{ $L('att.col.time') }}</th>
                <th>{{ $L('att.col.kind') }}</th>
                <th>{{ $L('att.title_goal') }}</th>
                <th>{{ $L('att.categories') }}</th>
                <th>{{ $L('att.col.status') }}</th>
                <th>{{ $L('att.minutes') }}</th>
                <th>{{ $L('att.reason') }}</th>
                <th>{{ $L('att.note') }}</th>
            </tr>
            @foreach ($sessions as $row)
                <tr>
                    <td class="num"><bdi dir="ltr">{{ $row['date'] }}</bdi></td>
                    <td class="num"><bdi dir="ltr">{{ $row['start_time'] }}–{{ $row['end_time'] }}</bdi></td>
                    <td>{{ $L('att.kind.'.$row['kind']) }}</td>
                    <td>{{ $row['title'] }}</td>
                    <td>{{ implode(' · ', $row['categories']) }}</td>
                    <td><span style="color: {{ $codes[$row['status']]['color'] ?? '#64748b' }};">&#9632;</span> {{ $labels[$row['status']] ?? $row['status'] }}</td>
                    <td class="num">{{ $row['minutes'] }}</td>
                    <td>{{ $row['reason'] ? $L('att.reason.'.$row['reason']) : '' }}</td>
                    <td>{{ $row['note'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
