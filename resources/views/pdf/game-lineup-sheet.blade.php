@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin-bottom: 4px; }
        .sub { font-size: 12px; color: #334155; margin-bottom: 2px; }
        table.list { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.list th { background: #f1f5f9; padding: 4px; border: 1px solid #94a3b8; font-size: 10px; }
        table.list td { padding: 4px 6px; border: 1px solid #94a3b8; }
        td.num { text-align: center; width: 12mm; font-weight: bold; }
        .pitch { text-align: center; margin: 8px 0; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('comp.sheet.lineup_title') }}</div>
    <div class="sub"><b>{{ $game['team'] }}</b> — {{ $game['opponent'] }} ({{ $L('comp.side_'.$game['side']) }})</div>
    <div class="sub">{{ $game['dateLabel'] }}@if ($game['kickoff']) · <bdi dir="ltr">{{ $game['kickoff'] }}</bdi>@endif @if ($game['venue']) · {{ $game['venue'] }}@endif</div>
    @if ($game['competition'])
        <div class="sub">{{ $game['competition'] }}@if ($game['round']) · {{ $game['round'] }}@endif</div>
    @endif

    <div class="pitch"><img src="data:image/svg+xml;base64,{{ base64_encode($pitch) }}" style="width: 68mm; height: 105mm;" />
        @if ($formation)<div class="muted">{{ $L('comp.formation') }}: {{ $formation }}</div>@endif
    </div>

    <table class="list">
        <thead><tr><th>{{ $L('comp.shirt') }}</th><th>{{ $L('comp.player') }}</th><th>{{ $L('comp.spot') }}</th><th></th></tr></thead>
        <tbody>
            @foreach ($starters as $s)
                <tr>
                    <td class="num">{{ $s['shirt'] }}</td>
                    <td>{{ $s['name'] }}</td>
                    <td>{{ $s['position'] }}</td>
                    <td>@if ($s['captain']) C @endif @if ($s['goalkeeper']) GK @endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($bench)
        <div class="sub" style="margin-top: 8px;"><b>{{ $L('comp.substitutes') }}:</b>
            @foreach ($bench as $b){{ $b['shirt'] }} {{ $b['name'] }}@if (! $loop->last) · @endif @endforeach
        </div>
    @endif
    @if ($coaches)
        <div class="sub">{{ $L('comp.tab_coaches') }}: {{ implode(' · ', $coaches) }}</div>
    @endif
</body>
</html>
