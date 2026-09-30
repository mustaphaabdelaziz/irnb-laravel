@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $day = fn (string $date) => substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4);
    $shown = ['absent_unexcused', 'absent_excused', 'late', 'left_early'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; line-height: 1.5; }
        table.meta { margin: 2px 0 12px; }
        table.meta td { padding: 2px 6px 2px 0; vertical-align: top; }
        .label { color: #64748b; }
        .subject { font-weight: bold; }
        .body { margin: 10px 0 14px; }
        h2 { font-size: 12px; color: #0f172a; margin: 14px 0 4px; }
        table.rows { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.rows th { background: #f1f5f9; color: #334155; padding: 4px; border: 1px solid #e2e8f0; }
        table.rows td { padding: 4px; border: 1px solid #e2e8f0; }
        .num { text-align: center; }
        .empty { color: #64748b; padding: 6px 0; }
        table.sign { width: 100%; margin-top: 16mm; }
        table.sign td { width: 50%; vertical-align: top; }
        .line { border-top: 1px solid #94a3b8; width: 60mm; margin-top: 16mm; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <table class="meta">
        <tr><td class="label">{{ $L('att.letter.date_label') }}</td><td><bdi dir="ltr">{{ $date }}</bdi></td></tr>
        <tr><td class="label">{{ $L('att.letter.to') }}</td><td>{{ $recipient }}</td></tr>
        <tr><td class="label">{{ $L('att.letter.subject_label') }}</td><td class="subject">{{ $subject }}</td></tr>
    </table>

    <div class="body">
        @foreach (preg_split('/\R/u', $body) as $line)
            {{ $line }}<br>
        @endforeach
    </div>

    <h2>{{ $L('att.letter.details') }} — <bdi dir="ltr">{{ $periodText }}</bdi></h2>
    @if (empty($rows))
        <div class="empty">{{ $L('att.letter.no_details') }}</div>
    @else
        <table class="rows">
            <tr>
                <th>{{ $L('att.date') }}</th>
                <th>{{ $L('att.col.kind') }}</th>
                <th>{{ $L('att.col.status') }}</th>
                <th>{{ $L('att.minutes') }}</th>
                <th>{{ $L('att.reason') }}</th>
            </tr>
            @foreach ($rows as $row)
                <tr>
                    <td class="num"><bdi dir="ltr">{{ $day($row['date']) }}</bdi></td>
                    <td>{{ $L('att.kind.'.$row['kind']) }}</td>
                    <td>{{ $labels[$row['status']] ?? $row['status'] }}</td>
                    <td class="num">{{ $row['minutes'] }}</td>
                    <td>{{ $row['reason'] ? $L('att.reason.'.$row['reason']) : '' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>{{ $L('att.letter.totals') }}</h2>
    <table class="rows">
        <tr>
            @foreach ($shown as $status)
                <th>{{ $labels[$status] }}</th>
            @endforeach
            <th>{{ $L('att.col.late_minutes') }}</th>
        </tr>
        <tr>
            @foreach ($shown as $status)
                <td class="num">{{ $summary['counts'][$status] }}</td>
            @endforeach
            <td class="num">{{ $summary['late_minutes'] }}</td>
        </tr>
    </table>

    <table class="sign">
        <tr>
            <td>{{ $L('att.letter.coach') }}<div class="line"></div></td>
            <td>{{ $L('att.letter.president') }}<div class="line"></div></td>
        </tr>
    </table>
</body>
</html>
