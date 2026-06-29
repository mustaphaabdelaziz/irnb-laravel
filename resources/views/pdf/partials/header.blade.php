<table style="width:100%; border-bottom:2px solid #02a85c; padding-bottom:6px; margin-bottom:14px;">
    <tr>
        @if (!empty($club['logo']))
            <td style="width:60px;"><img src="{{ $club['logo'] }}" style="width:54px; height:54px;"></td>
        @endif
        <td>
            <div style="font-size:16px; font-weight:bold; color:#0f172a;">{{ $club['name'] }}</div>
            @if (!empty($club['address']))
                <div style="font-size:10px; color:#64748b;">{{ $club['address'] }}</div>
            @endif
            <div style="font-size:10px; color:#64748b;">
                @if (!empty($club['phone'])){{ $club['phone'] }}@endif
                @if (!empty($club['email'])) &middot; {{ $club['email'] }}@endif
            </div>
        </td>
    </tr>
</table>
