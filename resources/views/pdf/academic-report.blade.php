@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $currentYear = $academicYears->last();
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
        table.grades td { padding: 6px; border: 1px solid #e2e8f0; vertical-align: top; }
        .num { text-align: center; }
        .grade { font-family: monospace; font-size: 13px; }
        .pass { color: #047857; font-weight: bold; }
        .fail { color: #be123c; font-weight: bold; }
        .certificate { font-size: 9px; color: #64748b; margin-top: 2px; }
        .provisional { font-size: 9px; color: #64748b; margin-top: 2px; }
        .summary { margin-top: 12px; font-size: 12px; color: #475569; }
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
        <tr>
            <td class="label">{{ $L('current_school') }}</td>
            <td>
                @if ($currentYear)
                    {{ $L('education_level_'.$currentYear->education_level) }}
                    @if ($currentYear->institution)
                        &middot; {{ $currentYear->institution }}
                    @endif
                    @if ($currentYear->field_of_study)
                        &middot; {{ $currentYear->field_of_study }}
                    @endif
                @else
                    &mdash;
                @endif
            </td>
        </tr>
    </table>

    @if ($academicYears->isEmpty())
        <p>{{ $L('no_gpa_records') }}</p>
    @else
        <table class="grades">
            <thead>
                <tr>
                    <th>{{ $L('academic_year') }}</th>
                    <th>{{ $L('education_level') }}</th>
                    <th>{{ $L('institution') }}</th>
                    <th>{{ $L('field_of_study') }}</th>
                    <th>{{ $L('period_T1') }}</th>
                    <th>{{ $L('period_T2') }}</th>
                    <th>{{ $L('period_T3') }}</th>
                    <th>{{ $L('year_average') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($academicYears as $year)
                    <tr>
                        <td class="num"><bdi dir="ltr">{{ $year->academic_year }}/{{ $year->academic_year + 1 }}</bdi></td>
                        <td>{{ $L('education_level_'.$year->education_level) }}</td>
                        <td>{{ $year->institution ?: '—' }}</td>
                        <td>{{ $year->field_of_study ?: '—' }}</td>
                        @foreach (\App\Enums\AcademicPeriod::cases() as $period)
                            @php($record = $year->records->firstWhere('period', $period->value))
                            <td class="num">
                                @if ($record)
                                    @php($passed = (float) $record->gpa >= $year->scale() / 2)
                                    <div class="grade {{ $passed ? 'pass' : 'fail' }}">
                                        <bdi dir="ltr">{{ number_format((float) $record->gpa, 2) }} / {{ $year->scale() }}</bdi>
                                    </div>
                                    @if ($record->certificate)
                                        <div class="certificate">{{ $L('certificate_'.$record->certificate) }}</div>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                        @endforeach
                        <td class="num">
                            @if ($year->average() !== null)
                                <div class="grade">
                                    <bdi dir="ltr">{{ number_format($year->average(), 2) }} / {{ $year->scale() }}</bdi>
                                </div>
                                @if ($year->isProvisional())
                                    <div class="provisional">{{ $L('provisional') }}</div>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="summary">
        <strong>{{ $L('pass_mark') }}:</strong>
        <bdi dir="ltr">
            @foreach ($scalePassMarks as $scale => $passMark)
                /{{ $scale }}: {{ rtrim(rtrim(number_format($passMark, 2), '0'), '.') }}@if (!$loop->last) &middot; @endif
            @endforeach
        </bdi>
    </div>
</body>
</html>
