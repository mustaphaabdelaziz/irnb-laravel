@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    // Free text: Latin lines keep their own left-to-right order inside the RTL page; line breaks become <br />.
    $T = fn (?string $v) => \App\Support\PdfText::ltrIfLatin($v);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; line-height: 1.5; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.meta td { padding: 2px 0; vertical-align: top; }
        table.to { border-collapse: collapse; margin: 4px 0 14px; }
        table.to td { padding: 2px 8px 2px 0; vertical-align: top; }
        .label { color: #64748b; }
        .subject { font-weight: bold; margin: 8px 0 14px; }
        .body { margin: 0 0 18px; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @include('pdf.partials.header')

    <table class="meta">
        <tr>
            <td><span class="label">{{ $L('mreg.pdf.ref') }}</span> <bdo dir="ltr">{{ $entry->reference }}</bdo></td>
            <td style="text-align: {{ $rtl ? 'left' : 'right' }};"><span class="label">{{ $L('mreg.pdf.date') }}</span> <bdo dir="ltr">{{ $entry->entry_date->format('d/m/Y') }}</bdo></td>
        </tr>
    </table>

    <table class="to">
        <tr><td class="label">{{ $L('mreg.pdf.to') }}</td><td>{{ $T($entry->correspondent) }}</td></tr>
        @if ($entry->address)
            <tr><td class="label">{{ $L('mreg.pdf.address') }}</td><td>{{ $T($entry->address) }}</td></tr>
        @endif
    </table>

    <div class="subject">{{ $L('mreg.pdf.subject') }} {{ $T($entry->subject) }}</div>

    <div class="body">{{ $T($entry->body) }}</div>

    {{-- President + Secretary General (blank line when nobody holds the role). --}}
    <table style="width:100%; margin-top:30px; text-align:center; font-size:12px;">
        <tr>
            <td style="width:50%; vertical-align:top;">
                <div style="font-weight:bold;">{{ $L('mreg.pdf.secretary') }}</div>
                <div style="margin-top:4px;">{{ $signatories['secretary'] ?? '' }}</div>
                @include('pdf.partials.sign-line')
            </td>
            <td style="width:50%; vertical-align:top;">
                <div style="font-weight:bold;">{{ $L('po.pdf.president') }}</div>
                <div style="margin-top:4px;">{{ $signatories['president'] ?? '' }}</div>
                @include('pdf.partials.sign-line')
            </td>
        </tr>
    </table>
</body>
</html>
