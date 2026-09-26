@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $periods = \App\Enums\AcademicPeriod::cases();
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #1e293b; }
        .title { font-size: 17px; font-weight: bold; color: #02a85c; margin-bottom: 2px; }
        .sub { font-size: 12px; color: #334155; margin-bottom: 8px; }
        .count { font-size: 10px; color: #64748b; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #f1f5f9; color: #475569; font-size: 10px; padding: 5px; border: 1px solid #e2e8f0; }
        table.rows td { padding: 5px; border: 1px solid #e2e8f0; vertical-align: top; }
        .num { text-align: center; }
        .grade { font-family: monospace; font-size: 12px; }
        .pass { color: #047857; font-weight: bold; }
        .fail { color: #be123c; font-weight: bold; }
        .muted { color: #94a3b8; }
        .small { font-size: 9px; color: #64748b; margin-top: 2px; }
        .empty { padding: 20px; text-align: center; color: #94a3b8; }
    </style>
</head>
<body>
    @forelse ($sections as $section)
        @if (! $loop->first)
            <pagebreak />
        @endif

        @include('pdf.partials.header')

        <div class="title">{{ $L('academic_results') }} <bdi dir="ltr">{{ $schoolYear }}/{{ $schoolYear + 1 }}</bdi></div>
        <div class="sub">
            {{ $section['category'] ? ($section['category']->localized_name ?: $section['category']->name) : $L('uncategorized') }}
            <span class="count">&middot; {{ $section['rows']->count() }} {{ $L('academic_results_students') }}</span>
        </div>

        <table class="rows">
            <thead>
                <tr>
                    <th style="width:4%;">#</th>
                    <th>{{ $L('name') }}</th>
                    <th style="width:10%;">{{ $L('education_level') }}</th>
                    <th style="width:17%;">{{ $L('institution') }} / {{ $L('field_of_study') }}</th>
                    @foreach ($periods as $period)
                        <th style="width:10%;">{{ $L('period_'.$period->value) }}</th>
                    @endforeach
                    <th style="width:11%;">{{ $L('year_average') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($section['rows'] as $row)
                    @php
                        $year = $row['year'];
                        $scale = $year?->scale();
                        $passMark = $scale ? $scale / 2 : null;
                        $average = $year?->average();
                    @endphp
                    <tr>
                        <td class="num">{{ $loop->iteration }}</td>
                        <td>
                            {{ $row['player']->fullname }}
                            <div class="small"><bdi dir="ltr">{{ $row['player']->membership_id }}</bdi></div>
                        </td>
                        <td>{{ $year ? $L('education_level_'.$year->education_level) : '—' }}</td>
                        <td>
                            {{ $year?->institution ?: '—' }}
                            @if ($year?->field_of_study)
                                <div class="small">{{ $year->field_of_study }}</div>
                            @endif
                        </td>
                        @foreach ($periods as $period)
                            @php $record = $year?->records->firstWhere('period', $period->value); @endphp
                            <td class="num">
                                @if ($record)
                                    <span class="grade {{ (float) $record->gpa >= $passMark ? 'pass' : 'fail' }}"><bdi dir="ltr">{{ number_format((float) $record->gpa, 2) }} / {{ $scale }}</bdi></span>
                                    @if ($record->certificate)
                                        <div class="small">{{ $L('certificate_short_'.$record->certificate) }}</div>
                                    @endif
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="num">
                            @if ($average !== null)
                                <span class="grade {{ $average >= $passMark ? 'pass' : 'fail' }}"><bdi dir="ltr">{{ number_format($average, 2) }} / {{ $scale }}</bdi></span>
                                @if ($year->isProvisional())
                                    <div class="small">{{ $L('provisional') }}</div>
                                @endif
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        @include('pdf.partials.header')
        <div class="title">{{ $L('academic_results') }} <bdi dir="ltr">{{ $schoolYear }}/{{ $schoolYear + 1 }}</bdi></div>
        <div class="empty">{{ $L('academic_results_empty') }}</div>
    @endforelse
</body>
</html>
