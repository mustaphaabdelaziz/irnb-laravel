/**
 * The toasts one page visit's flash should show. An import flashes both a
 * success (the count) and an error (the skipped rows): both are shown, success
 * first. 'status' is what Breeze's auth flows flash (password reset).
 *
 * @returns {{ type: 'success'|'error', payload: unknown }[]}
 */
export function flashToasts(flash) {
    const toasts = [];
    const success = flash?.success ?? flash?.status;
    if (success) toasts.push({ type: 'success', payload: success });
    if (flash?.error) toasts.push({ type: 'error', payload: flash.error });
    return toasts;
}

/** How long the toasts stay: row warnings beside a count need time to be read. */
export function flashDuration(toasts) {
    return toasts.length > 1 ? 8000 : 2000;
}
