@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $kind = function (?string $key) use ($L) {
        $label = $L('comp.sanction.'.$key);
        return $label === 'comp.sanction.'.$key ? (string) $key : $label;
    };
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin-bottom: 4px; }
        .sub { font-size: 12px; color: #334155; margin-bottom: 2px; }
        .score { font-size: 22px; font-weight: bold; text-align: center; margin: 6px 0; }
        h3 { font-size: 12px; margin: 10px 0 2px; color: #0f172a; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { background: #f1f5f9; padding: 3px; border: 1px solid #94a3b8; font-size: 10px; }
        table.list td { padding: 3px 5px; border: 1px solid #94a3b8; }
        td.num { text-align: center; width: 12mm; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('comp.sheet.report_title') }}</div>
    <div class="sub"><b>{{ $game['team'] }}</b> — {{ $game['opponent'] }} ({{ $L('comp.side_'.$game['side']) }})</div>
    <div class="sub">{{ $game['dateLabel'] }}@if ($game['competition']) · {{ $game['competition'] }}@endif @if ($game['round']) · {{ $game['round'] }}@endif</div>

    <div class="score"><bdi dir="ltr">{{ $score['us'] }} – {{ $score['them'] }}</bdi></div>
    <div class="sub" style="text-align: center;">
        @foreach ($score['periods'] as $i => $p)
            @if (isset($p[0], $p[1])){{ $L('comp.period_'.$score['period_label']) }} {{ $i + 1 }}: <bdi dir="ltr">{{ $p[0] }}–{{ $p[1] }}</bdi>@if (! $loop->last) · @endif @endif
        @endforeach
        @if ($score['extra_time'] && isset($score['extra_time'][0])) · {{ $L('comp.extra_time') }}: <bdi dir="ltr">{{ $score['extra_time'][0] }}–{{ $score['extra_time'][1] }}</bdi>@endif
        @if ($score['shootout']) · {{ $L('comp.shootout') }}: <bdi dir="ltr">{{ $score['shootout'][0] }}–{{ $score['shootout'][1] }}</bdi>@endif
    </div>

    @if ($goals)
        <h3>{{ $L('comp.sheet.goals') }}</h3>
        @foreach ($goals as $g)
            <div>@if ($g['minute'] !== null)<bdi dir="ltr">{{ $g['minute'] }}'</bdi> @endif
                {{ $g['kind'] === 'opponent_own_goal' ? $L('comp.goal_opponent_own_goal') : $g['scorer'] }}
                @if ($g['kind'] === 'penalty' || $g['kind'] === 'free_kick') <span class="muted">({{ $L('comp.goal_'.$g['kind']) }})</span>@endif
                @if ($g['assist']) <span class="muted">— {{ $L('comp.sheet.assist') }}: {{ $g['assist'] }}</span>@endif
            </div>
        @endforeach
    @endif

    @if ($sanctions)
        <h3>{{ $L('comp.sanctions') }}</h3>
        @foreach ($sanctions as $s)
            <div>@if ($s['minute'] !== null)<bdi dir="ltr">{{ $s['minute'] }}'</bdi> @endif{{ $s['player'] }} — {{ $kind($s['kind']) }}</div>
        @endforeach
    @endif

    @if ($subs)
        <h3>{{ $L('comp.sheet.substitutions') }}</h3>
        @foreach ($subs as $s)
            <div>@if ($s['minute'] !== null)<bdi dir="ltr">{{ $s['minute'] }}'</bdi> @endif↑ {{ $s['in'] }} ↓ {{ $s['out'] }}</div>
        @endforeach
    @endif

    <h3>{{ $L('comp.player_grid') }}</h3>
    <table class="list">
        <thead><tr>
            <th>{{ $L('comp.shirt') }}</th><th>{{ $L('comp.player') }}</th><th></th>
            <th>{{ $sets ? $L('comp.sets_played') : $L('comp.minutes') }}</th>
            <th>{{ $L('comp.sheet.goals') }}</th><th>{{ $L('comp.sanctions') }}</th>
        </tr></thead>
        <tbody>
            @foreach ($players as $p)
                <tr>
                    <td class="num">{{ $p['shirt'] }}</td>
                    <td>{{ $p['name'] }}</td>
                    <td>{{ $p['starter'] ? $L('comp.starter') : $L('comp.substitute') }}</td>
                    <td class="num">{{ $p['sets'] ?? $p['minutes'] }}</td>
                    <td class="num">{{ $p['goals'] ?: '' }}</td>
                    <td>{{ implode(', ', array_map($kind, $p['cards'])) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($mvp)
        <div class="sub" style="margin-top: 8px;"><b>{{ $L('comp.sheet.mvp') }}:</b> {{ $mvp }}</div>
    @endif
    @if ($coaches)
        <div class="sub">{{ $L('comp.tab_coaches') }}: {{ implode(' · ', $coaches) }}</div>
    @endif
</body>
</html>
