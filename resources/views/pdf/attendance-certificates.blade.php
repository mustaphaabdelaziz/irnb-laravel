@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $pct = fn ($value) => $value === null ? '—' : number_format((float) $value, 1).'%';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { color: #1e293b; }
        /* The decorative border: two plain CSS frames, green and gold (offline, no image). */
        table.frame { width: 100%; border: 2.5mm solid #02a85c; border-collapse: collapse; page-break-inside: avoid; }
        td.inner { border: 0.8mm solid #d4a017; height: 150mm; padding: 6mm 14mm; text-align: center; vertical-align: middle; }
        .club { font-size: 16px; font-weight: bold; color: #0f172a; margin-top: 2mm; }
        .title { font-size: 32px; font-weight: bold; color: #02a85c; margin: 6mm 0 3mm; }
        .awarded { font-size: 14px; color: #64748b; }
        .name { font-size: 28px; font-weight: bold; color: #0f172a; margin: 3mm 0; }
        .line { font-size: 14px; margin: 2mm 0; }
        .rank { font-size: 20px; font-weight: bold; color: #b45309; margin: 4mm 0 1mm; }
        .score { font-size: 14px; margin-top: 2mm; }
        table.sign { width: 100%; margin-top: 10mm; }
        table.sign td { width: 33%; text-align: center; font-size: 12px; vertical-align: top; }
        .sigline { border-top: 1px solid #94a3b8; width: 55mm; margin: 14mm auto 0; }
    </style>
</head>
<body>
    @foreach ($certificates as $i => $certificate)
        @if ($i > 0)
            <pagebreak />
        @endif
        <table class="frame">
            <tr>
                <td class="inner">
                    @if (!empty($club['logo']))
                        <img src="{{ $club['logo'] }}" style="width:22mm; height:22mm;">
                    @endif
                    <div class="club">{{ $club['name'] }}</div>
                    <div class="title">{{ $L('att.cert.title') }}</div>
                    <div class="awarded">{{ $L('att.cert.awarded_to') }}</div>
                    <div class="name">{{ $certificate['name'] }}</div>
                    <div class="line">{{ strtr($L('att.cert.line'), ['{category}' => $category, '{period}' => $periodLabel]) }}</div>
                    @if ($certificate['rank'] !== null)
                        <div class="rank">{{ $L('att.cert.rank_'.$certificate['rank']) }}</div>
                    @endif
                    <div class="score">{{ strtr($L('att.cert.score'), ['{score}' => $pct($certificate['score_pct'])]) }}</div>
                    <table class="sign">
                        <tr>
                            <td>{{ $L('att.letter.coach') }}<div class="sigline"></div></td>
                            <td>{{ strtr($L('att.cert.date'), ['{date}' => $date]) }}</td>
                            <td>{{ $L('att.letter.president') }}<div class="sigline"></div></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    @endforeach
</body>
</html>
