{{--
    Legend under a players × sessions table. $blank: blank columns on this part; $withTotals: explain the totals.
    Inherited from the page: $L, $statuses, $codes, $labels, $code, $legendCode, $kindMarks.
--}}
<div class="legend">
    <b>{{ $L('att.sheet.legend') }}:</b>
    @foreach ($statuses as $status)
        <span style="color: {{ $codes[$status]['color'] }};">&#9632;</span> <b><bdi dir="ltr">{{ $legendCode($status) }}</bdi></b> {{ $labels[$status] }}@if (! $loop->last) &nbsp;&middot;&nbsp; @endif
    @endforeach
    <br>{{ strtr($L('att.sheet.legend_help'), ['{late}' => $code('late')]) }}
    <br><span class="kind-legend">{{ $kindMarks['preseason'] }}</span> = {{ $L('att.kind.preseason') }} &middot; <span class="kind-legend">{{ $kindMarks['extra'] }}</span> = {{ $L('att.kind.extra') }} &middot; {{ $L('att.sheet.off_roster') }}
    @if ($blank)
        &middot; {{ $L('att.sheet.blank_columns') }}
    @endif
    @if ($withTotals)
        <br>{{ $L('att.sheet.totals_help') }}
    @endif
</div>
