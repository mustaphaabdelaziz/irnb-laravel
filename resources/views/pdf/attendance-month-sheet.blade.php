@php
    use App\Enums\AttendanceStatus;
    use App\Services\Attendance\AttendanceCode;

    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $statuses = array_keys($labels);
    $code = fn (string $status) => AttendanceCode::normalise($codes[$status]['code']);
    $legendCode = fn (string $status) => $code($status).((AttendanceStatus::tryFrom($status)?->takesMinutes() ?? false) ? '15' : '');
    $marks = ['preseason' => $L('att.sheet.kind_mark.preseason'), 'extra' => $L('att.sheet.kind_mark.extra')];
    $footer = $category['name'].' · '.$monthLabel.' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 9px; color: #1e293b; }
        .title { font-size: 15px; font-weight: bold; color: #02a85c; }
        .sub { font-size: 11px; color: #334155; margin-bottom: 6px; }
        .muted { color: #64748b; }
        table.sheet { width: 100%; border-collapse: collapse; }
        table.sheet th { background: #f1f5f9; color: #334155; font-size: 8px; padding: 2px; border: 1px solid #94a3b8; text-align: center; }
        table.sheet td { height: 6.5mm; padding: 1px 3px; border: 1px solid #94a3b8; }
        td.num { text-align: center; font-family: monospace; }
        td.cell, td.blank { text-align: center; font-weight: bold; font-size: 10px; }
        td.off { background: #d1d5db; }
        .kind, .kind-legend { font-weight: bold; color: #b45309; }
        .goal { font-size: 6px; font-weight: normal; color: #64748b; }
        .guest { font-size: 7px; color: #64748b; }
        .legend { margin-top: 5px; font-size: 8px; line-height: 1.5; }
        table.sign { width: 100%; margin-top: 6px; border-collapse: collapse; page-break-inside: avoid; }
        table.sign td { width: 33%; height: 13mm; border: 1px solid #94a3b8; vertical-align: top; padding: 3px; font-size: 8px; color: #64748b; }
    </style>
</head>
<body>
    <htmlpagefooter name="sheet"><div style="font-size:7px; color:#94a3b8; text-align:center;">{{ $footer }}</div></htmlpagefooter>
    <sethtmlpagefooter name="sheet" value="on" />

    @if ($groups === [])
        @include('pdf.partials.header')
        <div class="title">{{ $L('att.sheet.month_title') }}</div>
        <div class="sub">{{ $L('att.category') }}: <b>{{ $category['name'] }}</b> &middot; {{ $L('att.sheet.month') }}: <b>{{ $monthLabel }}</b> &middot; {{ $L('att.season') }}: <b><bdi dir="ltr">{{ $season }}</bdi></b></div>
        <div class="muted">{{ $L('att.no_sessions') }}</div>
    @endif

    @foreach ($groups as $group)
        @if (! $loop->first)
            <pagebreak />
        @endif
        @include('pdf.partials.header')
        <div class="title">{{ $L('att.sheet.month_title') }}</div>
        <div class="sub">
            {{ $L('att.category') }}: <b>{{ $category['name'] }}</b> &middot; {{ $L('att.sheet.month') }}: <b>{{ $monthLabel }}</b> &middot; {{ $L('att.season') }}: <b><bdi dir="ltr">{{ $season }}</bdi></b>
            @if (count($groups) > 1)
                &middot; {{ strtr($L('att.sheet.part'), ['{from}' => $group['from'], '{to}' => $group['to'], '{total}' => $total]) }}
            @endif
            @if ($filled)
                &middot; {{ $L('att.sheet.filled_note') }}
            @endif
        </div>

        <table class="sheet">
            <thead>
                <tr>
                    <th style="width:6mm;">#</th>
                    <th style="width:14mm;">{{ $L('att.sheet.file_no') }}</th>
                    <th style="width:52mm;">{{ $L('att.player') }}</th>
                    @foreach ($group['columns'] as $column)
                        <th>{{ $column['day'] }}<br><bdi dir="ltr">{{ $column['date'] }}</bdi><br><bdi dir="ltr">{{ $column['time'] }}</bdi>@if (isset($marks[$column['kind']]))<br><span class="kind">{{ $marks[$column['kind']] }}</span>@endif @if ($column['title'])<br><span class="goal">{{ $column['title'] }}</span>@endif</th>
                    @endforeach
                    @for ($i = 0; $i < $group['blank']; $i++)
                        <th class="blank-head">&nbsp;<br>__/__<br>&nbsp;</th>
                    @endfor
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="num">{{ $loop->iteration }}</td>
                        <td class="num">{{ $row['file_number'] }}</td>
                        <td>{{ $row['name'] }}@if ($row['category']) <span class="guest">({{ $row['category'] }})</span>@endif</td>
                        @foreach ($group['columns'] as $column)
                            @if (array_key_exists($column['id'], $cells[$row['id']] ?? []))
                                <td class="cell">{{ $filled ? $cells[$row['id']][$column['id']] : '' }}</td>
                            @else
                                <td class="off"></td>
                            @endif
                        @endforeach
                        @for ($i = 0; $i < $group['blank']; $i++)
                            <td class="blank"></td>
                        @endfor
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="legend">
            <b>{{ $L('att.sheet.legend') }}:</b>
            @foreach ($statuses as $status)
                <span style="color: {{ $codes[$status]['color'] }};">&#9632;</span> <b><bdi dir="ltr">{{ $legendCode($status) }}</bdi></b> {{ $labels[$status] }}@if (! $loop->last) &nbsp;&middot;&nbsp; @endif
            @endforeach
            <br>{{ strtr($L('att.sheet.legend_help'), ['{late}' => $code('late')]) }}
            <br><span class="kind-legend">{{ $marks['preseason'] }}</span> = {{ $L('att.kind.preseason') }} &middot; <span class="kind-legend">{{ $marks['extra'] }}</span> = {{ $L('att.kind.extra') }} &middot; {{ $L('att.sheet.off_roster') }}
            @if ($group['blank'])
                &middot; {{ $L('att.sheet.blank_columns') }}
            @endif
        </div>

        <table class="sign">
            <tr>
                <td>{{ $L('att.sheet.coach_name') }}</td>
                <td>{{ $L('att.sheet.signature') }}</td>
                <td>{{ $L('att.sheet.entered_on') }} ____________ &nbsp; {{ $L('att.sheet.entered_by') }} ____________</td>
            </tr>
        </table>
    @endforeach
</body>
</html>
