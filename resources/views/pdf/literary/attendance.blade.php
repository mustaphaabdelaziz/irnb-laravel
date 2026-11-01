<h2>{{ $l('literary.section.attendance') }}</h2>
@if ($data['held'] === 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    <table class="kpis"><tr><td><div class="value">{{ $data['held'] }}</div><div class="label">{{ $l('literary.pdf.sessions_held') }}</div></td></tr></table>

    @if ($data['branches'])
        <h3>{{ $l('literary.pdf.by_branch') }}</h3>
        <table class="data">
            <tr><th>{{ $l('literary.pdf.branch') }}</th><th>{{ $l('literary.pdf.sessions_held') }}</th><th>{{ $l('literary.pdf.attendance_rate') }}</th></tr>
            @foreach ($data['branches'] as $row)
                <tr><td class="start">{{ $row['name'] ?? $l('literary.pdf.club_wide') }}</td><td>{{ $row['held'] }}</td><td>{{ $row['rate'] !== null ? $row['rate'].'%' : '—' }}</td></tr>
            @endforeach
        </table>
    @endif

    <h3>{{ $l('literary.pdf.by_category') }}</h3>
    <table class="data">
        <tr><th>{{ $l('literary.pdf.category') }}</th><th>{{ $l('literary.pdf.sessions_held') }}</th><th>{{ $l('literary.pdf.expected') }}</th><th>{{ $l('literary.pdf.attendance_rate') }}</th></tr>
        @foreach ($data['categories'] as $row)
            <tr><td class="start">{{ $row['name'] }}</td><td>{{ $row['held'] }}</td><td>{{ $row['expected'] }}</td><td>{{ $row['rate'] !== null ? $row['rate'].'%' : '—' }}</td></tr>
        @endforeach
    </table>
@endif
