/**
 * What a finished Inertia visit means for a stats panel. onFinish also runs
 * after a cancel or an interrupt (a newer visit took over): that is neither a
 * success nor a failure, and the newer visit owns the busy state.
 */
export function visitOutcome(visit, succeeded) {
    if (visit?.cancelled || visit?.interrupted) return 'superseded';
    return succeeded ? 'ok' : 'failed';
}
