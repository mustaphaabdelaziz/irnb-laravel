@php
    use App\Services\Attendance\AttendanceCode;

    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $statuses = array_keys($labels);
    $code = fn (string $status) => AttendanceCode::normalise($codes[$status]['code']);
    $footer = implode(' · ', $session['categories']).' · '.$session['date'].' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 10px; color: #1e293b; }
        .title { font-size: 16px; font-weight: bold; color: #02a85c; }
        .sub { font-size: 11px; color: #334155; margin-bottom: 4px; }
        .muted { color: #64748b; }
        .banner { padding: 4px 6px; background: #f1f5f9; color: #334155; margin-bottom: 6px; }
        table.sheet { width: 100%; border-collapse: collapse; }
        table.sheet th { background: #f1f5f9; color: #334155; font-size: 7px; padding: 2px; border: 1px solid #94a3b8; text-align: center; }
        table.sheet td { height: 7mm; padding: 1px 3px; border: 1px solid #94a3b8; }
        td.num { text-align: center; font-family: monospace; }
        td.tick { text-align: center; font-weight: bold; font-size: 11px; }
        .code { font-size: 10px; font-weight: bold; }
        .guest { font-size: 7px; color: #64748b; }
        .help { font-size: 8px; color: #64748b; margin-top: 4px; }
        table.log { width: 100%; margin-top: 8px; border-collapse: collapse; page-break-inside: avoid; }
        table.log td { border: 1px solid #94a3b8; padding: 4px; vertical-align: top; }
        table.log td.label { width: 28%; color: #64748b; font-size: 9px; }
    </style>
</head>
<body>
    <htmlpagefooter name="sheet"><div style="font-size:7px; color:#94a3b8; text-align:center;">{{ $footer }}</div></htmlpagefooter>
    <sethtmlpagefooter name="sheet" value="on" />

    @include('pdf.partials.header')

    <div class="title">{{ $L('att.sheet.session_title') }}</div>
    <div class="sub"><b>{{ implode(' · ', $session['categories']) }}</b> &middot; {{ $L('att.kind.'.$session['kind']) }} &middot; {{ $session['dateLabel'] }} &middot; <bdi dir="ltr">{{ $session['time'] }}</bdi></div>
    @if ($session['title'])
        <div class="sub">{{ $L('att.title_goal') }}: <b>{{ $session['title'] }}</b></div>
    @endif
    @if ($session['cancelled'])
        <div class="banner">{{ strtr($L('att.cancelled_because'), ['{reason}' => (string) $session['cancel_reason']]) }}</div>
    @endif
    @if ($filled)
        <div class="muted">{{ $L('att.sheet.filled_note') }}</div>
    @endif

    <table class="sheet">
        <thead>
            <tr>
                <th style="width:6mm;">#</th>
                <th style="width:13mm;">{{ $L('att.sheet.file_no') }}</th>
                <th>{{ $L('att.player') }}</th>
                @foreach ($statuses as $status)
                    <th style="width:10mm;"><span class="code" style="color: {{ $codes[$status]['color'] }};"><bdi dir="ltr">{{ $code($status) }}</bdi></span><br>{{ $labels[$status] }}</th>
                @endforeach
                <th style="width:11mm;">{{ $L('att.minutes') }}</th>
                <th style="width:18mm;">{{ $L('att.reason') }}</th>
                <th style="width:28mm;">{{ $L('att.note') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="num">{{ $loop->iteration }}</td>
                    <td class="num">{{ $row['file_number'] }}</td>
                    <td>{{ $row['name'] }}@if ($row['category']) <span class="guest">({{ $row['category'] }})</span>@endif</td>
                    @foreach ($statuses as $status)
                        @if ($row['status'] === $status)
                            <td class="tick">X</td>
                        @else
                            <td></td>
                        @endif
                    @endforeach
                    <td class="num">{{ $row['minutes'] }}</td>
                    <td>{{ $row['reason'] ? $L('att.reason.'.$row['reason']) : '' }}</td>
                    <td>{{ $row['note'] }}</td>
                </tr>
            @endforeach
            @for ($i = 0; $i < $blankRows; $i++)
                <tr class="blank-row">
                    <td class="num">{{ count($rows) + $i + 1 }}</td>
                    <td></td>
                    <td></td>
                    @foreach ($statuses as $status)
                        <td></td>
                    @endforeach
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endfor
        </tbody>
    </table>

    <div class="help">{{ $L('att.sheet.tick_help') }} {{ $L('att.sheet.blank_rows') }}</div>
    <div class="help"><b>{{ $L('att.reason') }}:</b>
        @foreach ($reasons as $reason)
            {{ $L('att.reason.'.$reason) }}@if (! $loop->last) &middot; @endif
        @endforeach
    </div>

    <table class="log">
        <tr><td class="label">{{ $L('att.coach') }}</td><td>{{ $session['coach'] }}</td></tr>
        <tr><td class="label">{{ $L('att.title_goal') }}</td><td>{{ $session['title'] }}</td></tr>
        <tr><td class="label">{{ $L('att.notes') }}</td><td style="height:22mm;">{{ $session['notes'] }}</td></tr>
        <tr><td class="label">{{ $L('att.sheet.signature') }}</td><td style="height:14mm;"></td></tr>
        <tr><td class="label">{{ $L('att.sheet.entered_on') }}</td><td>____________ &nbsp; {{ $L('att.sheet.entered_by') }} ____________</td></tr>
    </table>
</body>
</html>
