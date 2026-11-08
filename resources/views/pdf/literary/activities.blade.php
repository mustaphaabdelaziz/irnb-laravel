<h2>{{ $l('literary.section.activities') }}</h2>
@if ($data['count'] === 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    @if ($data['include_details'])
        <h3>{{ $l('literary.pdf.by_type') }}</h3>
        <table class="data">
            <tr><th>{{ $l('literary.pdf.type') }}</th><th>{{ $l('literary.pdf.count') }}</th><th>{{ $l('literary.pdf.beneficiaries') }}</th><th>{{ $l('literary.pdf.participants') }}</th></tr>
            @foreach ($data['summary'] as $row)
                <tr><td class="start">{{ $row['type'] }}</td><td>{{ $row['count'] }}</td><td>{{ $row['beneficiaries'] }}</td><td>{{ $row['participants'] }}</td></tr>
            @endforeach
            <tr class="total"><td class="start">{{ $l('literary.pdf.total') }}</td><td>{{ $data['totals']['count'] }}</td><td>{{ $data['totals']['beneficiaries'] }}</td><td>{{ $data['totals']['participants'] }}</td></tr>
        </table>
    @endif

    @foreach ($data['chapters'] as $chapter)
        <h3>{{ $chapter['type'] }}</h3>
        <table class="data">
            <tr>
                <th style="width: 8%;">{{ $l('literary.pdf.number') }}</th>
                <th>{{ $l('literary.pdf.activity_nature') }}</th>
                <th style="width: 18%;">{{ $l('literary.pdf.location') }}</th>
                <th style="width: 18%;">{{ $l('literary.pdf.date') }}</th>
            </tr>
            @foreach ($chapter['rows'] as $row)
                <tr>
                    <td><bdi dir="ltr">{{ $row['number'] }}</bdi></td>
                    <td class="start">{{ $row['title'] }}@if ($row['details'] !== '')<div class="muted">{{ $row['details'] }}</div>@endif</td>
                    <td>{{ $row['place'] }}</td>
                    <td>{{ $row['date'] }}</td>
                </tr>
            @endforeach
        </table>
        @if ($data['include_details'])
            @foreach ($chapter['rows'] as $row)
                @if ($row['report'])
                    <div style="page-break-inside: avoid; margin: 6px 0;">
                        <strong>{{ $row['title'] }}</strong>
                        <div class="text">{!! $text($row['report']) !!}</div>
                    </div>
                @endif
            @endforeach
        @endif
    @endforeach
@endif

@if ($data['note'] !== '')
    <div class="text" style="margin-top: 10px;">{!! $text($data['note']) !!}</div>
@endif
