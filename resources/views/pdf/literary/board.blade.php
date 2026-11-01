<h2>{{ $l('literary.section.board') }}</h2>
@if ($data['term'])
    <p class="muted">{{ $l('literary.pdf.term') }}: {{ $data['term'] }}</p>
@endif
@if ($data['members'])
    <table class="data">
        <tr><th>{{ $l('literary.pdf.name') }}</th><th>{{ $l('literary.pdf.role') }}</th></tr>
        @foreach ($data['members'] as $member)
            <tr><td class="start">{{ $member['name'] }}</td><td class="start">{{ $member['role'] }}</td></tr>
        @endforeach
    </table>
@else
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@endif

@if ($data['committees'])
    <h3>{{ $l('literary.pdf.committees') }}</h3>
    <table class="data">
        <tr><th>{{ $l('literary.pdf.committee') }}</th><th>{{ $l('literary.pdf.head') }}</th><th>{{ $l('literary.pdf.deputies') }}</th><th>{{ $l('literary.pdf.members_count') }}</th></tr>
        @foreach ($data['committees'] as $committee)
            <tr><td class="start">{{ $committee['name'] }}</td><td class="start">{{ $committee['head'] ?? '—' }}</td><td class="start">{{ implode('، ', $committee['deputies']) ?: '—' }}</td><td>{{ $committee['members_count'] }}</td></tr>
        @endforeach
    </table>
@endif

<h3>{{ $l('literary.pdf.meetings') }} ({{ count($data['meetings']) }})</h3>
@forelse ($data['meetings'] as $meeting)
    <div style="page-break-inside: avoid; margin-bottom: 6px;">
        <strong><bdi dir="ltr">{{ $meeting['date'] }}</bdi> — {{ $meeting['title'] }}</strong>
        <span class="muted">({{ $l($meeting['type']) }})</span>
        @if ($meeting['decisions'])
            <ul style="margin: 2px 0;">
                @foreach ($meeting['decisions'] as $decision)
                    <li>{{ $decision }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@empty
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@endforelse
