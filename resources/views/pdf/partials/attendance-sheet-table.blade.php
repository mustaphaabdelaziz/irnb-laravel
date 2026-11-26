{{--
    One part of a players × sessions sheet (month and period sheets).
    $part: ['columns', 'blank']; $rows: [{id, name, category}]; $cells: playerId => sessionId => code;
    $filled: print the codes; $totals: playerId => SheetTotals row, or null for no totals columns.
    Inherited from the page: $L, $kindMarks.
--}}
<table class="sheet">
    <thead>
        <tr>
            <th style="width:6mm;">#</th>
            <th style="width:52mm;">{{ $L('att.player') }}</th>
            @foreach ($part['columns'] as $column)
                <th>{{ $column['day'] }}<br><bdi dir="ltr">{{ $column['date'] }}</bdi><br><bdi dir="ltr">{{ $column['time'] }}</bdi>@if (isset($kindMarks[$column['kind']]))<br><span class="kind">{{ $kindMarks[$column['kind']] }}</span>@endif @if ($column['title'])<br><span class="goal">{{ $column['title'] }}</span>@endif</th>
            @endforeach
            @for ($i = 0; $i < $part['blank']; $i++)
                <th class="blank-head">&nbsp;<br>__/__<br>&nbsp;</th>
            @endfor
            @if ($totals !== null)
                <th class="total" style="width:10mm;">{{ $L('att.sheet.total_present') }}</th>
                <th class="total" style="width:10mm;">{{ $L('att.sheet.total_absent') }}</th>
                <th class="total" style="width:12mm;">{{ $L('att.sheet.total_rate') }}</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <td class="num">{{ $loop->iteration }}</td>
                <td>{{ $row['name'] }}@if ($row['category']) <span class="guest">({{ $row['category'] }})</span>@endif</td>
                @foreach ($part['columns'] as $column)
                    @if (array_key_exists($column['id'], $cells[$row['id']] ?? []))
                        <td class="cell">{{ $filled ? $cells[$row['id']][$column['id']] : '' }}</td>
                    @else
                        <td class="off"></td>
                    @endif
                @endforeach
                @for ($i = 0; $i < $part['blank']; $i++)
                    <td class="blank"></td>
                @endfor
                @if ($totals !== null)
                    @php $rowTotal = $totals[$row['id']] ?? ['present' => 0, 'absent' => 0, 'rate' => null]; @endphp
                    <td class="total">{{ $rowTotal['present'] }}</td>
                    <td class="total">{{ $rowTotal['absent'] }}</td>
                    <td class="total"><bdi dir="ltr">{{ \App\Services\Attendance\SheetTotals::rateLabel($rowTotal['rate']) }}</bdi></td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>
