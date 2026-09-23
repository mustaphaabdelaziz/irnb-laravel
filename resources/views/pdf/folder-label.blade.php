@php
    use App\Services\Player\FileNumber;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #1e293b; }
        .label { border: 2px solid #02a85c; border-radius: 8px; padding: 10px; margin-bottom: 10px; width: 100%; }
        .file { font-size: 30px; font-weight: bold; color: #0f172a; line-height: 1; }
        .drawer { font-size: 10px; color: #64748b; }
        .name { font-size: 14px; font-weight: bold; }
        .mid { font-family: monospace; font-size: 12px; color: #334155; }
        .meta { font-size: 10px; color: #64748b; }
        .club { font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>
    @foreach ($players as $player)
        <table class="label">
            <tr>
                <td style="width:32%; vertical-align:top;">
                    <div class="file">{{ FileNumber::format($player->file_number) }}</div>
                    <div class="drawer">
                        {{ __('Drawer') }} {{ $player->file_number ? FileNumber::drawer($player->file_number) : '—' }}
                    </div>
                </td>
                <td style="vertical-align:top;">
                    <div class="name">{{ $player->fullname }}</div>
                    <div class="mid">{{ $player->membership_id }}</div>
                    <div class="meta">
                        {{ optional($player->category)->localized_name ?: (optional($player->category)->name ?: '—') }}
                        @if ($player->join_year) &middot; {{ $player->join_year }} @endif
                    </div>
                    <div class="club">{{ $club['name'] }}</div>
                </td>
                <td style="width:22%; text-align:center; vertical-align:top;">
                    {{-- The QR carries the membership id, not a link: the desktop app
                         lives on a local address whose port changes every launch. --}}
                    <barcode code="{{ $player->membership_id }}" type="QR" size="0.9" error="M" disableborder="1" />
                </td>
            </tr>
        </table>
        @if (! $loop->last && $loop->iteration % 6 === 0)
            <pagebreak />
        @endif
    @endforeach
</body>
</html>
