<div style="text-align: center; padding-top: 70px;">
    @if (!empty($club['logo']))
        <img src="{{ $club['logo'] }}" style="width: 120px; height: 120px;">
    @endif
    <div style="font-size: 20px; font-weight: bold; color: #0f172a; margin-top: 18px;">{{ $club['name'] }}</div>
    <div style="font-size: 28px; font-weight: bold; color: #02a85c; margin-top: 50px;">{{ $report->title }}</div>
    <div style="font-size: 14px; color: #475569; margin-top: 14px;">
        {{ $l('literary.period_from') }} <bdi dir="ltr">{{ $report->period_from->format('Y-m-d') }}</bdi>
        {{ $l('literary.period_to') }} <bdi dir="ltr">{{ $report->period_to->format('Y-m-d') }}</bdi>
    </div>
    @if (!empty($club['address']))
        <div style="font-size: 11px; color: #64748b; margin-top: 90px;">{{ $club['address'] }}</div>
    @endif
</div>
