@php
    use App\Enums\AttendanceStatus;
    use App\Services\Attendance\AttendanceCode;
    use App\Services\Attendance\SheetTotals;

    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $statuses = array_keys($labels);
    $code = fn (string $status) => AttendanceCode::normalise($codes[$status]['code']);
    $legendCode = fn (string $status) => $code($status).((AttendanceStatus::tryFrom($status)?->takesMinutes() ?? false) ? '15' : '');
    $kindMarks = ['preseason' => $L('att.sheet.kind_mark.preseason'), 'extra' => $L('att.sheet.kind_mark.extra')];
    $footer = ($branch ? e($branch).' · ' : '').'<bdi dir="ltr">'.e($periodLabel).'</bdi> — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
    $first = true;
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
        td.cell { text-align: center; font-weight: bold; font-size: 10px; }
        td.off { background: #d1d5db; }
        th.total, td.total { text-align: center; font-weight: bold; background: #f8fafc; }
        .kind, .kind-legend { font-weight: bold; color: #b45309; }
        .goal { font-size: 6px; font-weight: normal; color: #64748b; }
        .guest { font-size: 7px; color: #64748b; }
        .legend { margin-top: 5px; font-size: 8px; line-height: 1.5; }
    </style>
</head>
<body>
    <htmlpagefooter name="sheet"><div style="font-size:7px; color:#94a3b8; text-align:center;">{!! $footer !!}</div></htmlpagefooter>
    <sethtmlpagefooter name="sheet" value="on" />

    @if ($sections === [])
        @include('pdf.partials.header')
        <div class="title">{{ $L('att.sheet.period_title') }}</div>
        <div class="sub">@if ($branch){{ $L('branch') }}: <b>{{ $branch }}</b> &middot; @endif{{ $L('att.sheet.period') }}: <b><bdi dir="ltr">{{ $periodLabel }}</bdi></b> &middot; {{ $L('att.season') }}: <b><bdi dir="ltr">{{ $season }}</bdi></b></div>
        <div class="muted">{{ $L('att.sheet.no_sessions_period') }}</div>
    @endif

    @foreach ($sections as $section)
        @foreach ($section['months'] as $month)
            @php $monthTotal = $month['parts'][count($month['parts']) - 1]['to']; @endphp
            @foreach ($month['parts'] as $part)
                @if (! $first)
                    <pagebreak />
                @endif
                @php $first = false; @endphp
                @include('pdf.partials.header')
                <div class="title">{{ $L('att.sheet.period_title') }}</div>
                <div class="sub">
                    @if ($branch){{ $L('branch') }}: <b>{{ $branch }}</b> &middot; @endif{{ $L('att.category') }}: <b>{{ $section['category'] }}</b>@if ($section['group']) &middot; {{ $L('att.group') }}: <b>{{ $section['group'] }}</b>@endif &middot; {{ $L('att.sheet.month') }}: <b>{{ $month['label'] }}</b> &middot; {{ $L('att.sheet.period') }}: <b><bdi dir="ltr">{{ $periodLabel }}</bdi></b> &middot; {{ $L('att.season') }}: <b><bdi dir="ltr">{{ $season }}</bdi></b>
                    @if (count($month['parts']) > 1)
                        &middot; {{ strtr($L('att.sheet.part'), ['{from}' => $part['from'], '{to}' => $part['to'], '{total}' => $monthTotal]) }}
                    @endif
                </div>

                @include('pdf.partials.attendance-sheet-table', [
                    'part' => $part,
                    'rows' => $month['rows'],
                    'cells' => $month['cells'],
                    'filled' => true,
                    'totals' => $loop->last ? $month['totals'] : null,
                ])
                @include('pdf.partials.attendance-sheet-legend', ['blank' => 0, 'withTotals' => $loop->last])
            @endforeach
        @endforeach

        @if ($section['summary'] !== null)
            <pagebreak />
            @include('pdf.partials.header')
            <div class="title">{{ $L('att.sheet.summary_title') }}</div>
            <div class="sub">@if ($branch){{ $L('branch') }}: <b>{{ $branch }}</b> &middot; @endif{{ $L('att.category') }}: <b>{{ $section['category'] }}</b>@if ($section['group']) &middot; {{ $L('att.group') }}: <b>{{ $section['group'] }}</b>@endif &middot; {{ $L('att.sheet.period') }}: <b><bdi dir="ltr">{{ $periodLabel }}</bdi></b> &middot; {{ $L('att.season') }}: <b><bdi dir="ltr">{{ $season }}</bdi></b></div>
            <table class="sheet">
                <thead>
                    <tr>
                        <th style="width:6mm;">#</th>
                        <th>{{ $L('att.player') }}</th>
                        <th class="total" style="width:18mm;">{{ $L('att.sheet.total_present') }}</th>
                        <th class="total" style="width:18mm;">{{ $L('att.sheet.total_absent') }}</th>
                        <th class="total" style="width:18mm;">{{ $L('att.sheet.total_expected') }}</th>
                        <th class="total" style="width:18mm;">{{ $L('att.sheet.total_rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['summary'] as $row)
                        <tr>
                            <td class="num">{{ $loop->iteration }}</td>
                            <td>{{ $row['name'] }}@if ($row['category']) <span class="guest">({{ $row['category'] }})</span>@endif</td>
                            <td class="total">{{ $row['totals']['present'] }}</td>
                            <td class="total">{{ $row['totals']['absent'] }}</td>
                            <td class="total">{{ $row['totals']['expected'] }}</td>
                            <td class="total"><bdi dir="ltr">{{ SheetTotals::rateLabel($row['totals']['rate']) }}</bdi></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="legend">{{ $L('att.sheet.totals_help') }}</div>
        @endif
    @endforeach
</body>
</html>
