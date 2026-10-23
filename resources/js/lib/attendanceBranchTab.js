/**
 * The branch tab a profile URL asks the attendance card to open: report
 * pages and the dashboard link to the profile with ?attendance_branch={id}.
 * Null when absent or unusable, and the server then picks the tab.
 */
export function branchFromUrl(url) {
    if (!url) return null;
    const id = Number(new URL(url, 'http://profile.local').searchParams.get('attendance_branch'));

    return Number.isInteger(id) && id > 0 ? id : null;
}
