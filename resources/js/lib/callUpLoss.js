/**
 * The ticked-off players whose un-calling would delete report data. `rows` is the
 * call-up table being edited; the "called up" and "has report data" flags come from
 * `saved` (the current candidates prop), never from the rows: the rows are rebuilt
 * only by the call-up save, so after a line-up or report save they are stale.
 */
export function losingCallUpRows(rows, saved) {
    return rows.filter((r) => {
        const c = saved.find((c) => c.player_id === r.player_id);
        return !r.called_up && !!c?.called_up && !!c.has_report_data;
    });
}
