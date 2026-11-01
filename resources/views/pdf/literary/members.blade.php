<h2>{{ $l('literary.section.members') }}</h2>
<table class="kpis">
    <tr>
        <td><div class="value">{{ $data['total'] }}</div><div class="label">{{ $l('literary.pdf.active_members') }}</div></td>
        <td><div class="value">{{ $data['male'] }}</div><div class="label">{{ $l('literary.pdf.male') }}</div></td>
        <td><div class="value">{{ $data['female'] }}</div><div class="label">{{ $l('literary.pdf.female') }}</div></td>
        <td><div class="value">{{ $data['joined'] }}</div><div class="label">{{ $l('literary.pdf.joined') }}</div></td>
        <td><div class="value">{{ $data['left'] }}</div><div class="label">{{ $l('literary.pdf.left') }}</div></td>
    </tr>
</table>
@if ($data['total'] === 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    <table class="data">
        <tr><th>{{ $l('literary.pdf.branch') }}</th><th>{{ $l('literary.pdf.category') }}</th><th>{{ $l('literary.pdf.male') }}</th><th>{{ $l('literary.pdf.female') }}</th><th>{{ $l('literary.pdf.total') }}</th></tr>
        @foreach ($data['rows'] as $row)
            <tr>
                <td class="start">{{ $row['branch'] ?? $l('literary.pdf.no_branch') }}</td>
                <td class="start">{{ $row['category'] ?? $l('literary.pdf.no_category') }}</td>
                <td>{{ $row['male'] }}</td><td>{{ $row['female'] }}</td><td>{{ $row['total'] }}</td>
            </tr>
        @endforeach
        <tr class="total"><td class="start" colspan="2">{{ $l('literary.pdf.club_total') }}</td><td>{{ $data['male'] }}</td><td>{{ $data['female'] }}</td><td>{{ $data['total'] }}</td></tr>
    </table>
    @if ($data['multi_branch'])
        <p class="muted">{{ $l('literary.pdf.multi_branch_note') }}</p>
    @endif
@endif

@if ($data['coaches'])
    <h3>{{ $l('literary.pdf.coaches') }}</h3>
    <table class="data">
        <tr><th>{{ $l('literary.pdf.name') }}</th><th>{{ $l('literary.pdf.team') }}</th><th>{{ $l('literary.pdf.role') }}</th></tr>
        @foreach ($data['coaches'] as $coach)
            <tr><td class="start">{{ $coach['name'] }}</td><td class="start">{{ $coach['team'] }}</td><td>{{ $l('comp.coach_'.$coach['role']) }}</td></tr>
        @endforeach
    </table>
@endif
