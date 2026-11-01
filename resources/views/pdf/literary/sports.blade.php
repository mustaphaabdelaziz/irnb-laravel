<h2>{{ $l('literary.section.sports') }}</h2>
@if ($data['played'] === 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    @php($cols = ['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against'])
    <table class="data">
        <tr>
            <th>{{ $l('literary.pdf.competition') }}</th><th>{{ $l('literary.pdf.team') }}</th>
            @foreach ($cols as $col)<th>{{ $l('literary.pdf.'.$col) }}</th>@endforeach
        </tr>
        @foreach ($data['competitions'] as $row)
            <tr>
                <td class="start">{{ $row['name'] ?? $l('literary.pdf.friendlies') }}</td><td class="start">{{ $row['team'] }}</td>
                @foreach ($cols as $col)<td>{{ $row[$col] }}</td>@endforeach
            </tr>
        @endforeach
        <tr class="total">
            <td class="start" colspan="2">{{ $l('literary.pdf.total') }}</td>
            @foreach ($cols as $col)<td>{{ $data['record'][$col] }}</td>@endforeach
        </tr>
    </table>

    @if ($data['scorers'])
        <h3>{{ $l('literary.pdf.top_scorers') }}</h3>
        <table class="data">
            <tr><th>{{ $l('literary.pdf.player') }}</th><th>{{ $l('literary.pdf.goals') }}</th></tr>
            @foreach ($data['scorers'] as $row)
                <tr><td class="start">{{ $row['name'] }}</td><td>{{ $row['goals'] }}</td></tr>
            @endforeach
        </table>
    @endif
@endif

@if ($data['achievements'])
    <h3>{{ $l('literary.pdf.achievements') }}</h3>
    <table class="data">
        <tr><th>{{ $l('literary.pdf.date') }}</th><th>{{ $l('literary.pdf.player') }}</th><th>{{ $l('literary.pdf.achievement') }}</th></tr>
        @foreach ($data['achievements'] as $row)
            <tr><td><bdi dir="ltr">{{ $row['date'] }}</bdi></td><td class="start">{{ $row['player'] }}</td><td class="start">{{ $row['title'] }}</td></tr>
        @endforeach
    </table>
@endif
