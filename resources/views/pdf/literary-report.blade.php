@php
    $l = fn (string $key) => \App\Support\UiLang::get($key);
    // Editorial text keeps its line breaks (mPDF ignores white-space: pre-wrap).
    $text = fn (?string $value) => nl2br(e((string) $value), false);
    // mPDF's directionality setting alone leaves tables and alignment LTR; the dir attribute flips them.
    $dir = ($rtl ?? true) ? 'rtl' : 'ltr';
@endphp
<!DOCTYPE html>
<html dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<style>
    body { font-size: 11px; color: #1e293b; line-height: 1.5; }
    h2 { font-size: 16px; color: #02a85c; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; margin: 0 0 10px; }
    h3 { font-size: 12.5px; color: #0f172a; margin: 12px 0 4px; }
    table.data { width: 100%; border-collapse: collapse; margin: 4px 0 10px; }
    table.data th { background: #f1f5f9; border: 1px solid #94a3b8; padding: 3px; font-size: 10px; }
    table.data td { border: 1px solid #cbd5e1; padding: 3px; text-align: center; }
    table.data td.start { text-align: start; }
    table.data tr.total td { font-weight: bold; background: #f8fafc; }
    .kpis { width: 100%; border-collapse: collapse; margin: 4px 0 10px; }
    .kpis td { border: 1px solid #cbd5e1; padding: 6px; text-align: center; }
    .kpis .value { font-size: 15px; font-weight: bold; color: #0f172a; }
    .kpis .label { font-size: 9.5px; color: #64748b; }
    .text { text-align: justify; }
    .muted { color: #64748b; font-size: 10px; }
    .none { color: #64748b; font-style: italic; }
</style>
</head>
<body dir="{{ $dir }}">
<htmlpagefooter name="footer"><div style="text-align: center; font-size: 8px; color: #94a3b8;">{{ $report->title }} — {{ $l('literary.pdf.page') }} {PAGENO}/{nbpg}</div></htmlpagefooter>
<sethtmlpagefooter name="footer" value="on" />

@if (($sections[0]['key'] ?? null) !== 'cover')
    @include('pdf.partials.header')
@endif

@foreach ($sections as $section)
    @if (! $loop->first)
        <pagebreak />
    @endif
    @include('pdf.literary.'.$section['key'], ['data' => $section['data']])
@endforeach
</body>
</html>
