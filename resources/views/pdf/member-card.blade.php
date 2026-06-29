<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .card { border: 2px solid #02a85c; border-radius: 10px; padding: 14px; width: 460px; }
        .title { font-size: 16px; font-weight: bold; color: #02a85c; margin-bottom: 10px; }
        table.info { width: 100%; border-collapse: collapse; }
        table.info td { padding: 6px; border-bottom: 1px solid #eef2f6; }
        table.info td.label { color: #64748b; width: 40%; }
        .photo { width: 90px; height: 90px; border-radius: 8px; }
        .mid { font-family: monospace; font-size: 14px; color: #0f172a; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="card">
        <div class="title">{{ __('Member Card') }}</div>
        <table style="width:100%;">
            <tr>
                <td style="width:100px; vertical-align:top;">
                    @if ($player->picture_url)
                        <img class="photo" src="{{ $player->picture_url }}">
                    @endif
                </td>
                <td style="vertical-align:top;">
                    <div style="font-size:15px; font-weight:bold;">{{ $player->fullname }}</div>
                    <div class="mid">{{ $player->membership_id }}</div>
                </td>
            </tr>
        </table>

        <table class="info">
            <tr><td class="label">{{ __('Category') }}</td><td>{{ optional($player->category)->name ?: '—' }}</td></tr>
            <tr><td class="label">{{ __('Position') }}</td><td>{{ optional($player->position)->name ?: '—' }}</td></tr>
            <tr><td class="label">{{ __('Date of Birth') }}</td><td>{{ optional($player->birthdate)->format('Y-m-d') ?: '—' }}</td></tr>
            <tr><td class="label">{{ __('Phone') }}</td><td>{{ $player->phones[0] ?? '—' }}</td></tr>
            <tr><td class="label">{{ __('Blood Group') }}</td><td>{{ $player->health_blood_group_rhesus ?: '—' }}</td></tr>
            <tr><td class="label">{{ __('Join Year') }}</td><td>{{ $player->join_year ?: '—' }}</td></tr>
        </table>
    </div>
</body>
</html>
