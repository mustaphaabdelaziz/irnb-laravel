<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 10px; color: #1e293b; }
        .title { font-size: 16px; font-weight: bold; color: #02a85c; margin-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f5f9; padding: 3px; border: 1px solid #94a3b8; font-size: 9px; }
        td { padding: 3px 5px; border: 1px solid #94a3b8; }
        td.num { text-align: center; }
    </style>
</head>
<body>
    @include('pdf.partials.header')
    <div class="title">{{ $title }}</div>
    <table>
        <thead><tr>@foreach ($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>@foreach ($row as $i => $cell)<td class="{{ $i > 0 ? 'num' : '' }}">{{ $cell }}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
