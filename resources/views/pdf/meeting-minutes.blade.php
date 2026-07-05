<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin: 4px 0 2px; }
        .sub { font-size: 11px; color: #64748b; margin-bottom: 12px; }
        .section { font-size: 13px; font-weight: bold; color: #0f172a; margin: 16px 0 6px; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; }
        table.tbl { width: 100%; border-collapse: collapse; }
        table.tbl th { background: #f1f5f9; padding: 6px; text-align: start; font-size: 11px; color: #475569; }
        table.tbl td { padding: 6px; border-bottom: 1px solid #eef2f6; font-size: 11px; }
        ol, ul { margin: 4px 0; padding-inline-start: 18px; }
        li { margin-bottom: 3px; }
        .minutes { white-space: pre-wrap; font-size: 11px; line-height: 1.5; }
        .pill { font-size: 10px; padding: 1px 6px; border-radius: 8px; }
        .footer { margin-top: 24px; font-size: 10px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $meeting->title }}</div>
    <div class="sub">
        {{ __(ucwords(str_replace('_', ' ', $meeting->type))) }}
        &middot; {{ $meeting->meeting_date?->format('Y-m-d H:i') }}
        @if ($meeting->location) &middot; {{ $meeting->location }} @endif
        &middot; {{ __(ucfirst($meeting->status)) }}
    </div>

    @if (!empty($meeting->agenda))
        <div class="section">{{ __('Agenda') }}</div>
        <ol>
            @foreach ($meeting->agenda as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ol>
    @endif

    @if ($meeting->attendances->count())
        <div class="section">{{ __('Attendance') }}</div>
        <table class="tbl">
            <thead><tr><th>{{ __('Member') }}</th><th>{{ __('Role') }}</th><th>{{ __('Attendance') }}</th></tr></thead>
            <tbody>
                @foreach ($meeting->attendances as $a)
                    <tr>
                        <td>{{ $a->member?->name }}</td>
                        <td>{{ __(ucwords(str_replace('_', ' ', $a->member?->role ?? ''))) }}</td>
                        <td>{{ __(ucfirst($a->status)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($meeting->minutes)
        <div class="section">{{ __('Minutes') }}</div>
        <div class="minutes">{{ $meeting->minutes }}</div>
    @endif

    @if (!empty($meeting->decisions))
        <div class="section">{{ __('Decisions') }}</div>
        <ul>
            @foreach ($meeting->decisions as $d)
                <li>{{ $d }}</li>
            @endforeach
        </ul>
    @endif

    @if ($meeting->tasks->count())
        <div class="section">{{ __('Action Items') }}</div>
        <table class="tbl">
            <thead><tr><th>{{ __('Task') }}</th><th>{{ __('Assignee') }}</th><th>{{ __('Status') }}</th><th style="text-align:end;">{{ __('Progress') }}</th></tr></thead>
            <tbody>
                @foreach ($meeting->tasks as $t)
                    <tr>
                        <td>{{ $t->title }}</td>
                        <td>{{ $t->member?->name ?? '—' }}</td>
                        <td>{{ __(ucwords(str_replace('_', ' ', $t->status))) }}</td>
                        <td style="text-align:end;">{{ $t->progress }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">{{ $club['name'] }} — {{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
