@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin: 6px 0 12px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.info td { padding: 5px 6px; border-bottom: 1px solid #eef2f6; }
        table.info td.label { color: #64748b; width: 30%; }
        table.grades { width: 100%; border-collapse: collapse; }
        table.grades th { background: #f1f5f9; color: #475569; font-size: 11px; padding: 6px; border: 1px solid #e2e8f0; }
        table.grades td { padding: 6px; border: 1px solid #e2e8f0; }
        .num { text-align: center; font-family: monospace; font-size: 13px; }
        .pass { color: #047857; font-weight: bold; }
        .fail { color: #be123c; font-weight: bold; }
        .summary { margin-top: 12px; font-size: 13px; }
        .photo { width: 80px; height: 80px; border-radius: 8px; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('academic_report') }}</div>

    <table style="width:100%; margin-bottom:10px;">
        <tr>
            @if (!empty($photo))
                <td style="width:90px; vertical-align:top;"><img class="photo" src="{{ $photo }}"></td>
            @endif
            <td style="vertical-align:top;">
                <div style="font-size:15px; font-weight:bold;">{{ $player->fullname }}</div>
                <div style="font-family:monospace;">{{ $player->membership_id }}</div>
                <div style="color:#64748b;">{{ optional($player->category)->name }}</div>
            </td>
        </tr>
    </table>

    <table class="info">
        <tr><td class="label">{{ $L('education_level') }}</td><td>{{ $player->education_level ? $L('education_level_'.$player->education_level) : '—' }}</td></tr>
        <tr><td class="label">{{ $L('institution') }}</td><td>{{ $player->institution ?: '—' }}</td></tr>
        <tr><td class="label">{{ $L('field_of_study') }}</td><td>{{ $player->field_of_study ?: '—' }}</td></tr>
    </table>

    @if ($records->isEmpty())
        <p>{{ $L('no_gpa_records') }}</p>
    @else
        <table class="grades">
            <thead>
                <tr>
                    <th>{{ $L('academic_year') }}</th>
                    <th>{{ $L('period') }}</th>
                    <th>{{ $L('gpa') }} <span dir="ltr">/ 20</span></th>
                    <th>{{ $L('status') }}</th>
                    <th>{{ $L('remark') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($records as $record)
                    @php($passed = (float) $record->gpa >= $passMark)
                    <tr>
                        <td class="num"><span dir="ltr">{{ $record->academic_year }}/{{ $record->academic_year + 1 }}</span></td>
                        <td>{{ $L('period_'.$record->period) }}</td>
                        <td class="num {{ $passed ? 'pass' : 'fail' }}">{{ number_format((float) $record->gpa, 2) }}</td>
                        <td class="{{ $passed ? 'pass' : 'fail' }}">{{ $L($passed ? 'gpa_pass' : 'gpa_fail') }}</td>
                        <td>{{ $record->remark }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="summary">
            <strong>{{ $L('latest_gpa') }}:</strong> <span dir="ltr">{{ number_format((float) $latest->gpa, 2) }} / 20</span>
            &nbsp;&middot;&nbsp;
            <strong>{{ $L('average_gpa') }}:</strong> <span dir="ltr">{{ number_format($average, 2) }} / 20</span>
            &nbsp;&middot;&nbsp;
            {{ $L('pass_mark') }}: {{ $passMark }}
        </div>
    @endif
</body>
</html>
