<h2>{{ $l('literary.section.activities') }}</h2>
@if ($data['count'] === 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    <h3>{{ $l('literary.pdf.by_type') }}</h3>
    <table class="data">
        <tr><th>{{ $l('literary.pdf.type') }}</th><th>{{ $l('literary.pdf.count') }}</th><th>{{ $l('literary.pdf.beneficiaries') }}</th><th>{{ $l('literary.pdf.participants') }}</th></tr>
        @foreach ($data['by_type'] as $row)
            <tr><td class="start">{{ $row['type'] }}</td><td>{{ $row['count'] }}</td><td>{{ $row['beneficiaries'] }}</td><td>{{ $row['participants'] }}</td></tr>
        @endforeach
        <tr class="total"><td class="start">{{ $l('literary.pdf.total') }}</td><td>{{ $data['totals']['count'] }}</td><td>{{ $data['totals']['beneficiaries'] }}</td><td>{{ $data['totals']['participants'] }}</td></tr>
    </table>

    @foreach ($data['activities'] as $activity)
        <div style="page-break-inside: avoid; margin-top: 12px;">
            <h3>{{ $activity['title'] }}</h3>
            <div class="muted">
                {{ $activity['type'] }} ·
                <bdi dir="ltr">{{ $activity['starts_on'] }}@if ($activity['ends_on'] && $activity['ends_on'] !== $activity['starts_on']) → {{ $activity['ends_on'] }}@endif</bdi>
                @if ($activity['location']) · {{ $l('literary.pdf.location') }}: {{ $activity['location'] }}@endif
            </div>
            @if ($activity['partners'])
                <div class="muted">{{ $l('literary.pdf.partners') }}: {{ $activity['partners'] }}</div>
            @endif
            <div class="muted">{{ $l('literary.pdf.beneficiaries') }}: {{ $activity['beneficiaries'] }} · {{ $l('literary.pdf.participants') }}: {{ $activity['participants'] }}</div>
            @if ($activity['report'])
                <div class="text" style="margin-top: 4px;">{!! $text($activity['report']) !!}</div>
            @endif
            @if ($activity['photos'])
                <table style="width: 100%; margin-top: 6px;">
                    @foreach (array_chunk($activity['photos'], 2) as $pair)
                        <tr>
                            @foreach ($pair as $photo)
                                <td style="width: 50%; text-align: center; vertical-align: top; padding: 3px;">
                                    <img src="{{ $photo['file'] }}" style="max-width: 100%; max-height: 220px;">
                                    @if ($photo['caption'])<div class="muted">{{ $photo['caption'] }}</div>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endforeach
@endif
