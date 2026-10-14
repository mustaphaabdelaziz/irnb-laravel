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
        table.info { width: 100%; border-collapse: collapse; margin: 8px 0; }
        table.info td { padding: 4px 6px; border: 1px solid #cbd5e1; }
        table.info td.label { width: 30%; color: #64748b; background: #f8fafc; }
        table.list { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.list th { background: #f1f5f9; padding: 4px; border: 1px solid #94a3b8; font-size: 10px; }
        table.list td { padding: 5px 6px; border: 1px solid #94a3b8; }
        td.num { text-align: center; width: 14mm; font-weight: bold; }
        .coaches { margin-top: 16px; color: #475569; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('comp.convocation.title') }}</div>
    <div class="sub"><b>{{ $game['team'] }}</b> — {{ $game['opponent'] }} ({{ $L('comp.side_'.$game['side']) }})</div>
    @if ($game['competition'])
        <div class="sub">{{ $game['competition'] }}@if ($game['round']) · {{ $game['round'] }}@endif</div>
    @endif

    <table class="info">
        <tr><td class="label">{{ $L('date') }}</td><td>{{ $game['dateLabel'] }}</td></tr>
        @if ($game['kickoff'])
            <tr><td class="label">{{ $L('comp.kickoff') }}</td><td><bdi dir="ltr">{{ $game['kickoff'] }}</bdi></td></tr>
        @endif
        @if ($game['meeting_time'] || $game['meeting_place'])
            <tr><td class="label">{{ $L('comp.convocation.meeting') }}</td><td><bdi dir="ltr">{{ $game['meeting_time'] }}</bdi> {{ $game['meeting_place'] }}</td></tr>
        @endif
        @if ($game['venue'])
            <tr><td class="label">{{ $L('comp.venue') }}</td><td>{{ $game['venue'] }}@if ($game['address']) — {{ $game['address'] }}@endif</td></tr>
        @endif
    </table>

    <table class="list">
        <thead>
            <tr>
                <th>{{ $L('comp.shirt') }}</th>
                <th>{{ $L('comp.player') }}</th>
                <th>{{ $L('category') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($players as $player)
                <tr>
                    <td class="num">{{ $player['shirt'] }}</td>
                    <td>{{ $player['name'] }}</td>
                    <td>{{ $player['category'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3">{{ $L('comp.convocation.nobody') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($coaches)
        <div class="coaches">{{ $L('comp.tab_coaches') }}: {{ implode(' · ', $coaches) }}</div>
    @endif
</body>
</html>
