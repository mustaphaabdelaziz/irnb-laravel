@php
    use App\Services\Player\FileNumber;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .title { font-size: 16px; font-weight: bold; color: #02a85c; margin-bottom: 2px; }
        .sub { font-size: 11px; color: #64748b; margin-bottom: 10px; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #f1f5f9; text-align: start; padding: 6px; font-size: 11px; color: #334155; }
        table.rows td { padding: 6px; border-bottom: 1px solid #e2e8f0; }
        .num { font-family: monospace; }
        .empty { padding: 20px; text-align: center; color: #94a3b8; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $category->localized_name ?: $category->name }}</div>
    <div class="sub">{{ __('Season') }} {{ $season->label() }} &middot; {{ $players->count() }} {{ __('members') }}</div>

    @if ($players->isEmpty())
        <div class="empty">{{ __('No members in this category.') }}</div>
    @else
        <table class="rows">
            <tr>
                <th style="width:8%;">#</th>
                <th>{{ __('Member') }}</th>
                <th style="width:22%;">{{ __('Membership ID') }}</th>
                <th style="width:15%;">{{ __('File number') }}</th>
                <th style="width:12%;">{{ __('Drawer') }}</th>
            </tr>
            @foreach ($players as $player)
                <tr>
                    <td class="num">{{ $loop->iteration }}</td>
                    <td>{{ $player->fullname }}</td>
                    <td class="num">{{ $player->membership_id }}</td>
                    <td class="num">{{ FileNumber::format($player->file_number) ?: '—' }}</td>
                    <td class="num">{{ $player->file_number ? FileNumber::drawer($player->file_number, $drawerSize ?? null) : '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
