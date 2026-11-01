<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 9.5px; color: #1e293b; }
        .title { font-size: 16px; font-weight: bold; color: #02a85c; margin-bottom: 4px; }
        .meta { font-size: 9px; color: #64748b; margin-bottom: 2px; }
        .kpis { width: 100%; border-collapse: collapse; margin: 8px 0 10px; }
        .kpis td { border: 1px solid #cbd5e1; padding: 5px; text-align: center; }
        .kpis .k { font-size: 8.5px; color: #64748b; }
        .kpis .v { font-size: 12px; font-weight: bold; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #f1f5f9; padding: 3px; border: 1px solid #94a3b8; font-size: 8.5px; }
        table.data td { padding: 3px 4px; border: 1px solid #94a3b8; }
        td.num { text-align: center; }
        tr.totals td { font-weight: bold; background: #f8fafc; }
        .note { margin-top: 6px; font-size: 9px; color: #b45309; }
    </style>
</head>
<body>
    <htmlpagefooter name="footer">
        <div style="text-align: center; font-size: 8px; color: #94a3b8;">{{ $title }} — {{ $labels['page'] }} {PAGENO}/{nbpg}</div>
    </htmlpagefooter>
    <sethtmlpagefooter name="footer" value="on" />

    @include('pdf.partials.header')
    <div class="title">{{ $title }}</div>
    @foreach ($applied as $line)
        <div class="meta">{{ $line['label'] }}: {{ $line['value'] }}</div>
    @endforeach
    <div class="meta">{{ $labels['generated'] }}: {{ $generatedAt }}@if ($generatedBy) · {{ $generatedBy }}@endif</div>

    @if (count($summary))
        <table class="kpis">
            <tr>
                @foreach ($summary as $tile)
                    <td><div class="k">{{ $tile['label'] }}</div><div class="v">{{ $tile['value'] }}</div></td>
                @endforeach
            </tr>
        </table>
    @endif

    <table class="data">
        {{-- mPDF repeats <thead> at the top of every page. --}}
        <thead><tr>@foreach ($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>@foreach ($row as $i => $cell)<td class="{{ $columns[$i]->numeric() ? 'num' : '' }}">{{ $cell }}</td>@endforeach</tr>
            @endforeach
            @if ($totals)
                <tr class="totals">@foreach ($totals as $i => $cell)<td class="{{ $columns[$i]->numeric() ? 'num' : '' }}">{{ $i === 0 && $cell === '' ? $labels['total'] : $cell }}</td>@endforeach</tr>
            @endif
        </tbody>
    </table>

    @if ($truncated)
        <div class="note">{{ str_replace('{n}', (string) \App\Reports\ReportExporter::PDF_MAX_ROWS, $labels['truncated']) }}</div>
    @endif
</body>
</html>
