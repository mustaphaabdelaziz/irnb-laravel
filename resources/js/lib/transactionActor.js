/**
 * Who did something on a transaction: "Karim", or "Admin (as Karim)" when an
 * admin acted logged in as Karim's account. `entry` is a history entry, the
 * trace's recorded/modified side or the cancellation: { user, as_user }.
 */
export function actorLabel(t, entry) {
    if (!entry?.user) return t('tx_trace.unknown_user');

    return entry.as_user
        ? `${entry.user} (${t('tx_history.acting_as', { user: entry.as_user })})`
        : entry.user;
}
