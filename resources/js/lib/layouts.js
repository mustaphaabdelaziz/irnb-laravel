// Which pages sit inside AppShell, the persistent frame (sidebar, top bar)
// that stays mounted across Inertia visits. Every signed-in page does; the
// sign-in screens and the public pages do not.
export function usesAppShell(pageName) {
    return !/^(Auth|Public)\//.test(pageName);
}
