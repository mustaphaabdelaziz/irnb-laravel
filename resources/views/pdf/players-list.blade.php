@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    // The Arabic club name, then the short name at its end.
    $title = trim($clubName.' '.$shortName);
    $scope = array_filter([$branch, implode(' · ', $categories)]);
    $footer = $title.' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #1e293b; }
        table.head { width: 100%; border-bottom: 2px solid #0070c0; margin-bottom: 10px; }
        table.head td { vertical-align: middle; padding-bottom: 6px; }
        .club { font-size: 26px; font-weight: bold; color: #0070c0; text-align: center; }
        .title { font-size: 15px; font-weight: bold; color: #0f172a; }
        .sub { font-size: 10px; color: #64748b; margin-bottom: 8px; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #e8f1fa; color: #0f3d66; font-size: 10px; padding: 5px; border: 1px solid #b6cde3; text-align: center; }
        table.rows td { padding: 5px; border: 1px solid #cbd5e1; }
        td.c { text-align: center; }
        .num { font-family: monospace; }
        .empty { padding: 20px; text-align: center; color: #94a3b8; }
    </style>
</head>
<body>
    <htmlpagefooter name="list"><div style="font-size:7px; color:#94a3b8; text-align:center;">{{ $footer }}</div></htmlpagefooter>
    <sethtmlpagefooter name="list" value="on" />

    {{-- Full page width: logo | Arabic club name + short name | logo. The equal side cells keep the name centred between the logos; always right-to-left, whatever the PDF's language. --}}
    <table class="head" dir="rtl">
        <tr>
            <td style="width:15%; text-align:center;">
                @if (!empty($logo))
                    <img src="{{ $logo }}" style="width:22mm; height:22mm;">
                @endif
            </td>
            <td style="width:70%; text-align:center;">
                <div class="club" dir="rtl">{{ $title }}</div>
            </td>
            <td style="width:15%; text-align:center;">
                @if (!empty($logo))
                    <img src="{{ $logo }}" style="width:22mm; height:22mm;">
                @endif
            </td>
        </tr>
    </table>

    <div class="title">{{ $L('players_list_title') }}</div>
    <div class="sub">@if ($scope){{ implode(' · ', $scope) }} &middot; @endif{{ $players->count() }} {{ $L('players') }} &middot; <bdi dir="ltr">{{ $printedOn }}</bdi></div>

    @if ($players->isEmpty())
        <div class="empty">{{ $L('no_players_in_list') }}</div>
    @else
        <table class="rows">
            <thead>
                <tr>
                    <th style="width:6%;">#</th>
                    <th style="width:13%;">{{ $L('col.membership_id') }}</th>
                    <th>{{ $L('col.full_name') }}</th>
                    <th style="width:13%;">{{ $L('col.birthdate') }}</th>
                    <th style="width:10%;">{{ $L('col.blood_group') }}</th>
                    <th style="width:19%;">{{ $L('col.phone_number') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($players as $player)
                    <tr>
                        <td class="c num">{{ $loop->iteration }}</td>
                        <td class="c num">{{ $player->membership_id }}</td>
                        <td>{{ $player->fullname }}</td>
                        <td class="c"><bdi dir="ltr">{{ $player->birthdate?->format('d/m/Y') }}</bdi></td>
                        <td class="c"><bdi dir="ltr">{{ $player->health_blood_group_rhesus }}</bdi></td>
                        <td class="c"><bdi dir="ltr">{{ collect($player->phones ?? [])->filter()->implode(' / ') }}</bdi></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
