@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    // The Arabic club name, then the short name at its end.
    $title = trim($clubName.' '.$shortName);
    $scope = array_filter([$branch, implode(' · ', $categories)]);
    $footer = $title.' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
    // The count per document type, four types to a line.
    $perType = array_chunk($figures['per_type'], 4);
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
        .sub { font-size: 10px; color: #64748b; margin-bottom: 6px; }
        table.stats { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.stats td { width: 25%; text-align: center; padding: 5px; border: 1px solid #b6cde3; background: #f5f9fd; }
        table.stats .n { font-size: 14px; font-weight: bold; color: #0f3d66; }
        table.stats .l { font-size: 9px; color: #64748b; }
        .caption { font-size: 9px; color: #64748b; margin: 4px 0 2px; }
        table.types { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.types td { width: 25%; padding: 3px 5px; border: 1px solid #e2e8f0; font-size: 9.5px; }
        table.types .k { font-weight: bold; color: #b91c1c; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #e8f1fa; color: #0f3d66; font-size: 10px; padding: 5px; border: 1px solid #b6cde3; text-align: center; }
        table.rows td { padding: 5px; border: 1px solid #cbd5e1; vertical-align: top; }
        td.c { text-align: center; }
        .num { font-family: monospace; }
        .ok { color: #047857; }
        .miss { color: #b91c1c; }
        .empty { padding: 20px; text-align: center; color: #94a3b8; }
    </style>
</head>
<body>
    <htmlpagefooter name="list"><div style="font-size:7px; color:#94a3b8; text-align:center;">{{ $footer }}</div></htmlpagefooter>
    <sethtmlpagefooter name="list" value="on" />

    {{-- The players list's header: logo | Arabic club name + short name | logo, always right-to-left. --}}
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

    <div class="title">{{ $L('missing_docs.title') }}</div>
    <div class="sub">@if ($scope){{ implode(' · ', $scope) }} &middot; @endif<bdi dir="ltr">{{ $printedOn }}</bdi></div>

    <table class="stats">
        <tr>
            <td><div class="n">{{ $figures['total'] }}</div><div class="l">{{ $L('total_players') }}</div></td>
            <td><div class="n">{{ $figures['complete'] }}</div><div class="l">{{ $L('missing_docs.complete_players') }}</div></td>
            <td><div class="n">{{ $figures['incomplete'] }}</div><div class="l">{{ $L('missing_docs.incomplete_players') }}</div></td>
            <td><div class="n"><bdi dir="ltr">{{ $figures['percent'] }}%</bdi></div><div class="l">{{ $L('missing_docs.complete_rate') }}</div></td>
        </tr>
    </table>

    @if ($perType)
        <div class="caption">{{ $L('missing_docs.per_type') }}</div>
        <table class="types">
            @foreach ($perType as $line)
                <tr>
                    @foreach ($line as $type)
                        <td>{{ $type['name'] }}: <span class="k">{{ $type['count'] }}</span></td>
                    @endforeach
                    @for ($i = count($line); $i < 4; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif

    @if ($rows === [])
        <div class="empty">{{ $L('no_players_in_list') }}</div>
    @else
        <table class="rows">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:16%;">{{ $L('col.membership_id') }}</th>
                    <th style="width:27%;">{{ $L('col.full_name') }}</th>
                    <th style="width:11%;">{{ $L('col.category') }}</th>
                    <th>{{ $L('missing_docs.title') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="c num">{{ $loop->iteration }}</td>
                        <td class="c num" style="white-space:nowrap;">{{ $row['membership_id'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td class="c">{{ $row['category'] }}</td>
                        @if ($row['lines'] === [])
                            <td class="ok">{{ $L('doc_complete') }}</td>
                        @else
                            <td class="miss">{!! implode('<br>', array_map('e', $row['lines'])) !!}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
