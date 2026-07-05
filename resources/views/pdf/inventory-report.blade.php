<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin: 4px 0 2px; }
        .sub { font-size: 11px; color: #64748b; margin-bottom: 12px; }
        .cards { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .cards td { width: 33%; padding: 10px; border: 1px solid #e2e8f0; text-align: center; }
        .cards .k { font-size: 10px; color: #64748b; text-transform: uppercase; }
        .cards .v { font-size: 15px; font-weight: bold; }
        .section { font-size: 13px; font-weight: bold; color: #0f172a; margin: 12px 0 6px; }
        table.tbl { width: 100%; border-collapse: collapse; }
        table.tbl th { background: #f1f5f9; padding: 6px; text-align: start; font-size: 11px; color: #475569; }
        table.tbl td { padding: 6px; border-bottom: 1px solid #eef2f6; font-size: 11px; }
        .found { color: #047857; } .missing { color: #be123c; font-weight: bold; }
        .footer { margin-top: 24px; font-size: 10px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    @php
        $discrepancies = $session->items->filter(function ($l) {
            return $l->counted && (! $l->found
                || ($l->actual_condition && $l->actual_condition !== $l->expected_condition)
                || ($l->actual_location && $l->actual_location !== $l->expected_location));
        });
    @endphp

    <div class="title">{{ __('Inventory') }} — {{ $session->reference }}</div>
    <div class="sub">
        {{ __(ucwords(str_replace('_', ' ', $session->type))) }}
        &middot; {{ $session->session_date?->format('Y-m-d') }}
        &middot; {{ __(ucwords(str_replace('_', ' ', $session->status))) }}
        @if ($session->conductedBy) &middot; {{ $session->conductedBy->name }} @endif
    </div>

    <table class="cards">
        <tr>
            <td><div class="k">{{ __('Expected') }}</div><div class="v">{{ $session->total_expected }}</div></td>
            <td><div class="k">{{ __('Found') }}</div><div class="v found">{{ $session->total_found }}</div></td>
            <td><div class="k">{{ __('Missing') }}</div><div class="v missing">{{ $session->total_missing }}</div></td>
        </tr>
    </table>

    <div class="section">{{ __('Discrepancy') }}</div>
    <table class="tbl">
        <thead>
            <tr>
                <th>{{ __('Item') }}</th><th>{{ __('Found') }}</th>
                <th>{{ __('Expected') }}</th><th>{{ __('Actual') }}</th><th>{{ __('Note') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($discrepancies as $l)
                <tr>
                    <td>{{ $l->item?->unique_identifier }} <span style="color:#94a3b8;">{{ $l->item?->catalog?->name }}</span></td>
                    <td class="{{ $l->found ? 'found' : 'missing' }}">{{ $l->found ? __('Found') : __('Missing') }}</td>
                    <td>{{ $l->expected_condition }}@if ($l->expected_location) · {{ $l->expected_location }}@endif</td>
                    <td>{{ $l->actual_condition }}@if ($l->actual_location) · {{ $l->actual_location }}@endif</td>
                    <td>{{ $l->note }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center; color:#047857;">{{ __('No discrepancies') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">{{ $club['name'] }} — {{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
