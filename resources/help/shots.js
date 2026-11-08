// Every screenshot the help articles use. `targets` are data-help names whose
// boxes are saved for rings and magnifiers; `before` runs after the page loads
// (open a menu, type a search). Player 1 is أحمد بن علي (HelpDemoSeeder).

// Attendance data is dated relative to the seed day (HelpDemoAttendanceSeeder): shots
// run right after seeding, so "today" here is the seed day. Session 1 is the next U17
// session (Monday or Wednesday, today included), so the calendar opens on its month:
// near a month's end it can be next month's, and "this month" would miss its chip.
const ym = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${ym(d)}-${pad(d.getDate())}`;
const nextDay = (weekdays, from = new Date(), strict = false) => {
    const d = new Date(from);
    if (strict) d.setDate(d.getDate() + 1);
    while (!weekdays.includes(d.getDay())) d.setDate(d.getDate() + 1);
    return d;
};
const sessionMonth = ym(nextDay([1, 3]));
const nextSaturday = ymd(nextDay([6], new Date(), true));
const U17 = 'branch_id=2&category_id=7';
// The month grid opens on the month of 7 days ago, so its columns hold the held
// sessions (and the demo's saved codes) even at the start of a month.
const gridMonth = ym(new Date(Date.now() - 7 * 86400000));
// Swimming «أصاغر» (branch 1, category 11): its next sessions after the cancelled session 3.
const cadetsGrid = (month) => `/attendance/grid?branch_id=1&category_id=11&month=${month}`;
export default [
    { name: 'login', url: '/login', guest: true, targets: ['login-username', 'login-password', 'login-submit', 'login-help'] },
    { name: 'help-center', url: '/help', targets: ['help-search', 'help-button'] },
    { name: 'app-dashboard', url: '/dashboard', targets: ['sidebar', 'nav-members', 'help-button', 'theme-toggle', 'language-switch', 'sidebar-profile'] },
    {
        name: 'app-palette',
        url: '/dashboard',
        targets: ['palette-input'],
        before: async (page) => {
            // Once, in a full run on a loaded machine, the palette was closed again before
            // the typing (fill waited 30 s on a detached input). Ctrl+K toggles, so it is
            // pressed only while the palette is shut, until it stays open.
            const input = page.locator('[data-help="palette-input"]');
            for (let i = 0; i < 3 && !(await input.isVisible()); i++) {
                await page.keyboard.press('Control+k');
                await input.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
                await page.waitForTimeout(300);
            }
            await input.fill('لاعب');
        },
    },
    {
        name: 'profile',
        url: '/profile',
        targets: ['password-form', 'password-current', 'password-new', 'password-confirm', 'password-save'],
        // The password card is the second one: bring it to the middle of the screen.
        before: async (page) => {
            await page.locator('[data-help="password-form"]').evaluate((el) => el.scrollIntoView({ block: 'center' }));
        },
    },

    // --- Players ---
    { name: 'players-index', url: '/players', targets: ['nav-members', 'nav-players', 'add-player', 'join-dates-link'] },
    {
        // The statistics push the search box below the fold: bring it up (the header stays).
        name: 'players-search',
        url: '/players',
        targets: ['players-search'],
        before: async (page) => {
            await searchAhmed(page);
            await centre(page, 'players-search');
        },
    },
    {
        // The find-player example to its end: «أحمد» in «أقل من 14 سنة» leaves one row.
        name: 'players-filtered',
        url: '/players',
        targets: ['players-category', 'player-row-first'],
        before: async (page) => {
            await searchAhmed(page);
            await page.locator('[data-help="players-category"] button').first().click();
            await page.locator('[data-help="players-category"] label', { hasText: 'أقل من 14 سنة' }).click();
            await page.waitForURL(/category_id/);
            await page.waitForLoadState('networkidle');
            await page.keyboard.press('Escape');
            await centre(page, 'players-search');
        },
    },
    {
        name: 'players-create',
        url: '/players/create',
        targets: ['player-lastname', 'player-firstname', 'player-father', 'player-grandfather', 'player-birthdate', 'player-gender', 'player-add-branch'],
        before: fillAhmed,
    },
    {
        name: 'players-create-card',
        url: '/players/create',
        targets: ['player-branch-card'],
        before: async (page) => {
            await fillAhmed(page);
            await addBranch(page, 'كرة القدم', 'player-branch-card', 'أقل من 14 سنة', '2026-09-01');
        },
    },
    {
        name: 'players-create-bottom',
        url: '/players/create',
        targets: ['player-save'],
        before: async (page) => { await centre(page, 'player-save'); },
    },
    { name: 'player-show', url: '/players/1', targets: ['player-header', 'player-registrations', 'player-actions', 'player-edit'] },
    // Player 2 (ياسين بوزيد) plays football and swims: two cards, and the branch tabs.
    { name: 'player-show-2', url: '/players/2', targets: ['player-tabs', 'player-registrations'] },
    {
        name: 'player-documents',
        url: '/players/1',
        targets: ['player-documents', 'document-toggle', 'document-photo'],
        before: async (page) => { await centre(page, 'document-photo'); },
    },
    {
        // The receive window of the first document (شهادة الميلاد).
        name: 'player-documents-receive',
        url: '/players/1',
        targets: ['document-save'],
        before: async (page) => {
            await centre(page, 'document-toggle');
            await page.locator('[data-help="document-toggle"]').click();
            await page.locator('[data-help="document-save"]').waitFor();
        },
    },
    {
        // The Photo asks four copies: its window has «النسخ المستلمة»; أحمد brought two.
        name: 'player-documents-photo',
        url: '/players/1',
        targets: ['document-copies', 'document-save'],
        before: async (page) => {
            await centre(page, 'document-photo');
            await page.locator('[data-help="document-photo"]').click();
            await page.locator('[data-help="document-copies"]').fill('2');
        },
    },
    { name: 'player-edit', url: '/players/1/edit', targets: ['player-lastname', 'player-firstname', 'player-father', 'player-grandfather'] },
    {
        name: 'player-edit-bottom',
        url: '/players/1/edit',
        targets: ['player-save'],
        before: async (page) => { await centre(page, 'player-save'); },
    },
    {
        // Player 2 already has both branches (no picker left): أحمد, football only, shows it.
        name: 'player-edit-branches',
        url: '/players/1/edit',
        targets: ['player-add-branch', 'player-branch-card'],
        before: async (page) => { await centre(page, 'player-add-branch'); },
    },
    {
        name: 'player-edit-new-branch',
        url: '/players/1/edit',
        targets: ['player-branch-card-2'],
        before: async (page) => {
            await addBranch(page, 'السباحة', 'player-branch-card-2', 'أصاغر', '2026-11-01');
        },
    },
    // The list is longer than the screen: the first row carries the ring.
    { name: 'join-dates', url: '/players/join-dates', targets: ['join-dates-row'] },
    // --- Subscriptions --- (1 = «اشتراك سنوي», 2 = «رحلة صيفية»: HelpDemoSubscriptionsSeeder)
    // Two subscriptions only: the list's first row («اشتراك سنوي») fits under the statistics.
    { name: 'subscriptions-index', url: '/subscriptions', targets: ['nav-members', 'nav-subscriptions', 'subscriptions-new', 'sub-open'] },
    { name: 'subscription-create', url: '/subscriptions/create', targets: ['sub-kind', 'sub-kind-annual', 'sub-name', 'sub-season', 'sub-prices'], before: fillAnnual },
    {
        // Both branches first (changing them later drops categories), then every
        // category ticked, «أشبال» (first A→Z) cheaper: 2500 / 4000.
        name: 'subscription-create-categories',
        url: '/subscriptions/create',
        targets: ['sub-categories', 'sub-category-price'],
        before: async (page) => {
            await fillAnnualScope(page);
            await centre(page, 'sub-categories');
        },
    },
    {
        name: 'subscription-create-branches',
        url: '/subscriptions/create',
        targets: ['sub-branches'],
        before: async (page) => {
            await fillAnnual(page);
            await tickAll(page, 'sub-branches');
            await blur(page);
            await centre(page, 'sub-branches');
        },
    },
    {
        // The annual example filled in full, as the reader has it before saving.
        name: 'subscription-create-save',
        url: '/subscriptions/create',
        targets: ['sub-save'],
        before: async (page) => {
            await fillAnnualScope(page);
            await centre(page, 'sub-save');
        },
    },
    {
        // The exceptional example: «رحلة صيفية», 1500, no season.
        name: 'subscription-create-exceptional',
        url: '/subscriptions/create',
        targets: ['sub-kind-exceptional', 'sub-name', 'sub-prices', 'sub-debt-note'],
        before: fillTrip,
    },
    {
        // Football ticked first: the categories list keeps only its own.
        name: 'subscription-create-exceptional-scope',
        url: '/subscriptions/create',
        targets: ['sub-categories', 'sub-branches'],
        before: async (page) => {
            await fillTrip(page);
            await tripScope(page);
            await centre(page, 'sub-categories');
        },
    },
    {
        // The trip filled in full, as the reader has it before saving.
        name: 'subscription-create-exceptional-save',
        url: '/subscriptions/create',
        targets: ['sub-save'],
        before: async (page) => {
            await fillTrip(page);
            await tripScope(page);
            await centre(page, 'sub-save');
        },
    },
    { name: 'subscription-show', url: '/subscriptions/1', targets: ['sub-stats', 'sub-assign', 'sub-add-player'] },
    {
        name: 'subscription-assign',
        url: '/subscriptions/1',
        targets: ['sub-assign-category', 'sub-assign-submit'],
        before: async (page) => {
            await page.locator('[data-help="sub-assign"]').click();
            await page.locator('[data-help="sub-assign-category"] select').selectOption({ label: 'أقل من 14 سنة' });
        },
    },
    {
        // نور شعبان swims in «أشبال»: the window shows that category's 2500.
        name: 'subscription-add-player',
        url: '/subscriptions/1',
        targets: ['sub-player-search', 'sub-picked-player', 'sub-amount-will-be', 'sub-add-confirm'],
        before: async (page) => {
            await page.locator('[data-help="sub-add-player"]').click();
            await page.locator('[data-help="sub-player-search"]').fill('نور');
            await page.locator('label', { hasText: 'شعبان' }).first().click();
            await page.locator('[data-help="sub-amount-will-be"]').waitFor();
        },
    },
    {
        // «رحلة صيفية» is for «أقل من 14 سنة» only: the box adds the other football players.
        name: 'subscription-outside',
        url: '/subscriptions/2',
        targets: ['sub-outside-category'],
        before: async (page) => {
            await page.locator('[data-help="sub-add-player"]').click();
            await page.locator('[data-help="sub-outside-category"] input').check();
            await page.waitForLoadState('networkidle');
            await page.locator('text=فئة أخرى').first().waitFor();
        },
    },
    // --- Payments from the player page --- (HelpDemoSubscriptionsSeeder: 3 أمين paid 1000 of 3000,
    // 5 إسلام owes 3000, 6 أيوب owes «دين موسم 2023/2024» 2000, 8 زكرياء owes the trip 1500)
    { name: 'payment-button', url: '/players/8', targets: ['player-debt', 'player-add-payment'] },
    {
        // The take-payment example as the reader has it: «اشتراك سنوي» ticked first (#1), then the trip (#2).
        name: 'payment-modal',
        url: '/players/8',
        targets: ['payment-category', 'payment-subscriptions', 'payment-exempt', 'payment-amount', 'payment-method', 'payment-register'],
        before: payBoth,
    },
    {
        // The window is taller than the screen: «حفظ» comes after scrolling inside it.
        name: 'payment-modal-bottom',
        url: '/players/8',
        targets: ['payment-register', 'payment-save'],
        before: async (page) => {
            await payBoth(page);
            await page.locator('[data-help="payment-save"]').evaluate((el) => el.scrollIntoView({ block: 'end', behavior: 'instant' }));
        },
    },
    {
        name: 'fix-history',
        url: '/players/3',
        targets: ['player-history', 'player-history-edit'],
        before: async (page) => { await centre(page, 'player-history'); },
    },
    {
        // أمين's 1000 corrected to 1500.
        name: 'fix-edit-modal',
        url: '/players/3',
        targets: ['payment-edit-amount', 'payment-edit-save'],
        before: async (page) => {
            await centre(page, 'player-history');
            await page.locator('[data-help="player-history-edit"]').click();
            await page.locator('[data-help="payment-edit-amount"] input').fill('1500');
            await blur(page);
        },
    },
    {
        name: 'fix-sub-card',
        url: '/players/5',
        targets: ['player-subscriptions-card', 'player-sub-edit'],
        before: async (page) => { await centre(page, 'player-subscriptions-card'); },
    },
    {
        // إسلام's «اشتراك سنوي» with 10 %: 3000 − 300 = 2700.
        name: 'fix-sub-modal',
        url: '/players/5',
        targets: ['sub-discount', 'sub-net-owed', 'sub-edit-save'],
        before: async (page) => {
            await centre(page, 'player-subscriptions-card');
            await page.locator('[data-help="player-sub-edit"]').click();
            await page.locator('[data-help="sub-discount"] select').selectOption('percent');
            await page.locator('[data-help="sub-discount"] input').fill('10');
            await blur(page);
        },
    },
    {
        // أيوب's debt is already seeded: the card's header sits at the bottom edge, its rows
        // below it, as the reader sees the card before adding the debt.
        name: 'debt-button',
        url: '/players/6',
        targets: ['player-add-previous-debt'],
        before: debtCardHeader,
    },
    {
        name: 'debt-modal',
        url: '/players/6',
        targets: ['debt-label', 'debt-amount', 'debt-year', 'debt-save'],
        before: async (page) => {
            await debtCardHeader(page);
            await page.locator('[data-help="player-add-previous-debt"]').click();
            await page.locator('[data-help="debt-label"] input').fill('دين موسم 2023/2024');
            await page.locator('[data-help="debt-amount"] input').fill('2000');
            await page.locator('[data-help="debt-year"] input').fill('2024');
            await blur(page);
        },
    },
    {
        name: 'debt-card',
        url: '/players/6',
        targets: ['player-subscriptions-card'],
        before: async (page) => { await centre(page, 'player-subscriptions-card'); },
    },
    { name: 'debt-pay-button', url: '/players/6', targets: ['player-debt', 'player-add-payment'] },
    {
        name: 'debt-pay-modal',
        url: '/players/6',
        // One line ticked: no exemption box, no total; the whole window fits, «حفظ» included.
        targets: ['payment-category', 'payment-subscriptions', 'payment-amount', 'payment-register', 'payment-save'],
        before: payDebt,
    },
    // --- Receipts --- أحمد's «اشتراك سنوي» payment; the seeder printed its 80 mm ticket once (the original).
    {
        name: 'receipt-ticket-button',
        url: '/players/1',
        targets: ['player-history', 'player-history-ticket'],
        before: async (page) => { await centre(page, 'player-history'); },
    },
    {
        // «أحمد» typed in the transactions search leaves his one payment.
        name: 'receipt-tx-index',
        url: '/transactions',
        targets: ['nav-transactions', 'tx-search', 'tx-row-first'],
        before: async (page) => {
            await page.locator('[data-help="tx-search"] input').fill('أحمد');
            await page.waitForURL(/search=/);
            await page.waitForLoadState('networkidle');
            await blur(page);
        },
    },
    {
        name: 'receipt-tx-show',
        url: '/players/1',
        targets: ['tx-print-ticket', 'tx-print-a4', 'tx-prints'],
        before: openAhmedTransaction,
    },
    // The page as it opens: the code field already has the focus.
    { name: 'receipt-verify', url: '/receipts/verify', targets: ['nav-receipts-verify', 'receipt-code'] },
    {
        // The ticket's code, typed as the reader reads it from the paper (see ahmedReceiptCode).
        name: 'receipt-verify-code',
        url: '/players/1',
        targets: ['receipt-code', 'receipt-check'],
        before: async (page) => { await typeAhmedCode(page); },
    },
    {
        // After «تحقق»: the code stays in the field and the result shows under it.
        name: 'receipt-verify-result',
        url: '/players/1',
        targets: ['receipt-result'],
        before: async (page) => {
            await typeAhmedCode(page);
            await page.locator('[data-help="receipt-check"]').click();
            await page.locator('[data-help="receipt-result"]').waitFor();
            await page.waitForLoadState('networkidle');
            await blur(page);
        },
    },

    // --- Attendance --- (sessions 1–3 and players: HelpDemoAttendanceSeeder)
    // Football U17 opens on its month; the next session's chip is ringed (att-session-next).
    {
        name: 'att-calendar',
        url: `/attendance?view=month&${U17}&month=${sessionMonth}`,
        targets: ['nav-members', 'nav-attendance', 'att-branch', 'att-category', 'att-session-next', 'att-add-extra', 'att-add-preseason', 'att-preseason-chip', 'att-grid-link'],
    },
    {
        name: 'att-session',
        url: '/attendance/sessions/1',
        targets: ['att-state', 'att-not-saved', 'att-roster', 'att-row-status', 'att-guest', 'att-coach', 'att-save'],
    },
    // The take-attendance example, one step per shot, each in the reader's state at that
    // step. The roster is sorted by last name, so أمين قاسمي is the third row, not the
    // first: rows are found by name and the ringed names moved onto them (tagIn).
    { name: 'att-session-late', url: '/attendance/sessions/1', targets: ['att-row-late', 'att-minutes'], before: markLate },
    {
        name: 'att-session-excused',
        url: '/attendance/sessions/1',
        targets: ['att-row-excused', 'att-reason'],
        before: async (page) => {
            await markLate(page);
            await markExcused(page);
        },
    },
    {
        name: 'att-session-guest',
        url: '/attendance/sessions/1',
        targets: ['att-guest', 'att-guest-result'],
        before: async (page) => {
            await markLate(page);
            await markExcused(page);
            await searchGuest(page);
            // The result line opens under the search, at the screen's bottom edge.
            await centre(page, 'att-guest-result');
        },
    },
    {
        name: 'att-session-save',
        url: '/attendance/sessions/1',
        targets: ['att-coach', 'att-save'],
        before: async (page) => {
            await markLate(page);
            await markExcused(page);
            await searchGuest(page);
            await page.locator('[data-help="att-guest-result"] button').click();
            await blur(page);
        },
    },
    {
        // The add-session example filled in: a pre-season run next Saturday, 09:00–10:30.
        name: 'att-add-preseason-modal',
        url: `/attendance?view=month&${U17}&month=${sessionMonth}`,
        targets: ['add-kind', 'add-category', 'add-other-categories', 'add-date', 'add-times', 'add-title', 'add-save'],
        before: async (page) => {
            await page.locator('[data-help="att-add-preseason"]').click();
            await page.locator('[data-help="add-title"]').waitFor();
            await page.locator('[data-help="add-date"] input').fill(nextSaturday);
            await page.locator('[data-help="add-times"] input').nth(0).fill('09:00');
            await page.locator('[data-help="add-times"] input').nth(1).fill('10:30');
            await page.locator('[data-help="add-title"] input').fill('جري 7.2 كم في 40 دقيقة');
            await blur(page);
        },
    },
    // The month-grid example: the coach's sheet has أمين «R15» in one column. The Vue page
    // tags the first cell (row 1 = بلعيد, rows sort by last name), so grid-cell-first is
    // moved onto أمين's cell in the column where the demo saved his «R15» (the
    // 2nd-newest held U17 session; عبد الرحمن is «AN» in the same column).
    {
        name: 'att-grid',
        url: `/attendance/grid?${U17}&month=${gridMonth}`,
        targets: ['grid-legend', 'grid-help', 'grid-cell-first', 'grid-column-first', 'grid-save', 'grid-print'],
        before: async (page) => { await tagIn(page, 'grid-cell-first', await amineR15Cell(page)); },
    },
    {
        // Typing «R15» in that cell marks its column changed: the date turns blue and «حفظ»
        // wakes up. Nothing is saved.
        name: 'att-grid-typed',
        url: `/attendance/grid?${U17}&month=${gridMonth}`,
        targets: ['grid-cell-first', 'grid-save'],
        before: async (page) => {
            const cell = await amineR15Cell(page);
            await tagIn(page, 'grid-cell-first', cell);
            await cell.fill('R15');
        },
    },
    {
        // Then two ↑ presses climb from أمين (row 3) to عبد الرحمن (row 1) in the same
        // column, where «AN» is typed: the ring follows the focused cell.
        name: 'att-grid-moved',
        url: `/attendance/grid?${U17}&month=${gridMonth}`,
        targets: ['grid-cell-first', 'grid-save'],
        before: async (page) => {
            const cell = await amineR15Cell(page);
            await cell.fill('R15');
            await page.keyboard.press('ArrowUp');
            await page.keyboard.press('ArrowUp');
            const focused = page.locator(':focus');
            await tagIn(page, 'grid-cell-first', focused);
            await focused.fill('AN');
        },
    },
    // The cancel example is a «أصاغر» session (the pool is closed). Session 3 is already
    // cancelled, so the button and dialog shots open the next planned «أصاغر» session
    // (found in the grid, see openPlannedCadets) instead of the brief's U17 session 1.
    // The dialog is only filled in, never confirmed.
    { name: 'att-session-actions', url: cadetsGrid(gridMonth), targets: ['att-state', 'att-cancel', 'att-delete'], before: openPlannedCadets },
    {
        name: 'att-cancel-modal',
        url: cadetsGrid(gridMonth),
        targets: ['att-cancel-reason', 'att-cancel-confirm'],
        before: async (page) => {
            await openPlannedCadets(page);
            await page.click('[data-help="att-cancel"]');
            await page.fill('[data-help="att-cancel-reason"]', 'المسبح مغلق للصيانة');
        },
    },
    { name: 'att-cancelled', url: '/attendance/sessions/3', targets: ['att-cancelled-banner'] },
    // The erase example: the held pre-season session 2, as opened (its header has the
    // extra «طباعة مع الحضور المسجَّل» button, so the trash moves).
    { name: 'att-session-held', url: '/attendance/sessions/2', targets: ['att-state', 'att-delete'] },
    {
        // A held session (the pre-season one) so both delete choices show. Only the
        // «الإبقاء على الحصة ومسح الحضور فقط» choice is picked, never confirmed.
        name: 'att-delete-modal',
        url: '/attendance/sessions/2',
        targets: ['att-delete-choices', 'att-delete-reset', 'att-delete-confirm'],
        before: async (page) => {
            await page.click('[data-help="att-delete"]');
            await page.click('[data-help="att-delete-reset"]');
        },
    },
];

/** أمين قاسمي's grid cell holding the demo's «R15» (rows are found by last name). */
async function amineR15Cell(page) {
    const inputs = page.locator('tbody tr', { hasText: 'قاسمي' }).locator('input');
    const i = (await inputs.evaluateAll((els) => els.map((el) => el.value))).indexOf('R15');
    if (i === -1) throw new Error('Month grid: no «R15» cell for قاسمي — is the grid month the one of the held sessions?');
    return inputs.nth(i);
}

/**
 * Opens the next planned «أصاغر» session: the first grid column marked · (planned; held
 * columns show ✓ and cancelled sessions are left out of the grid). Looks in the grid
 * month, then in the two months after it, since session 3 may close its month.
 */
async function openPlannedCadets(page) {
    const start = new Date(Date.now() - 7 * 86400000);
    for (let k = 0; k < 3; k++) {
        const month = ym(new Date(start.getFullYear(), start.getMonth() + k, 1));
        if (k > 0) await page.goto(new URL(cadetsGrid(month), page.url()).href, { waitUntil: 'networkidle' });
        const planned = page.locator('thead th', { has: page.locator('div', { hasText: /^·$/ }) }).first();
        if (await planned.count()) {
            await page.goto(new URL(await planned.locator('a').getAttribute('href'), page.url()).href, { waitUntil: 'networkidle' });
            return;
        }
    }
    throw new Error('No planned «أصاغر» session in the three months from the grid month');
}

/** One row of the session page, found by the player's last name. */
function attRow(page, lastname) {
    return page.locator('[data-help="att-roster"] > li', { hasText: lastname });
}

/**
 * Puts a data-help name on one element, taking it off any other: the capture rings
 * the first element that has it, and the Vue page tags the first row only.
 */
async function tagIn(page, name, locator) {
    await page.evaluate((n) => document.querySelectorAll(`[data-help="${n}"]`).forEach((el) => el.removeAttribute('data-help')), name);
    await locator.evaluate((el, n) => el.setAttribute('data-help', n), name);
}

/** أمين قاسمي came 10 minutes late: «متأخر», then 10 in «الدقائق». */
async function markLate(page) {
    const row = attRow(page, 'قاسمي');
    const late = row.getByRole('button', { name: 'متأخر', exact: true });
    await tagIn(page, 'att-row-late', late);
    await late.click();
    await row.locator('[data-help="att-minutes"]').fill('10');
    await blur(page);
}

/** عبد الرحمن بلعيد is ill: «غائب بعذر» (the app presets «أخرى»), then «مرض» in «السبب». */
async function markExcused(page) {
    const row = attRow(page, 'بلعيد');
    const excused = row.getByRole('button', { name: 'غائب بعذر', exact: true });
    await tagIn(page, 'att-row-excused', excused);
    await excused.click();
    await row.locator('[data-help="att-reason"]').selectOption({ label: 'مرض' });
    await blur(page);
}

/** Types «وليد» in the guest search and waits for his line in the results. */
async function searchGuest(page) {
    await page.locator('[data-help="att-guest"]').fill('وليد');
    await page.locator('[data-help="att-guest-result"]').waitFor();
}

/**
 * Opens أحمد's payment page from his player page. The receipt code is random
 * (ReceiptPrinter::newCode) and the transaction id depends on the seed order, so
 * neither is hard-coded: the id comes from the history row's ticket link
 * (/transactions/<id>/ticket). Only links are read: opening the ticket itself
 * would print a new copy.
 */
async function openAhmedTransaction(page) {
    const href = await page.locator('[data-help="player-history-ticket"]').getAttribute('href');
    const id = href.match(/transactions\/(\d+)\/ticket/)[1];
    await page.goto(new URL(`/transactions/${id}`, page.url()).href, { waitUntil: 'networkidle' });
}

/** The demo ticket's code, read in clear in «الوصولات المطبوعة» of أحمد's payment page ("1234 5678 9012"). */
async function ahmedReceiptCode(page) {
    await openAhmedTransaction(page);
    return (await page.locator('[data-help="tx-prints"] li .font-mono').first().textContent()).trim();
}

/** Opens «التحقق من وصل» and types the demo ticket's code, as printed on the paper. */
async function typeAhmedCode(page) {
    const code = await ahmedReceiptCode(page);
    await page.goto(new URL('/receipts/verify', page.url()).href, { waitUntil: 'networkidle' });
    await page.locator('[data-help="receipt-code"]').fill(code);
}

/** The previous-debt example paid: أيوب ticks «دين موسم 2023/2024» only. */
async function payDebt(page) {
    await openPayment(page);
    await tickPayable(page, 'دين موسم 2023/2024');
    await blur(page);
}

/** Opens the «إضافة دفعة» window of the player page. */
async function openPayment(page) {
    await page.locator('[data-help="player-add-payment"]').click();
    await page.locator('[data-help="payment-subscriptions"]').waitFor();
}

/** The take-payment example: زكرياء ticks «اشتراك سنوي» first (#1), then «رحلة صيفية» (#2). */
async function payBoth(page) {
    await openPayment(page);
    await tickPayable(page, 'اشتراك سنوي');
    await tickPayable(page, 'رحلة صيفية');
    await blur(page);
}

/** Ticks one line of «الاشتراكات المراد دفعها»: the order of the ticks is the order of payment. */
async function tickPayable(page, text) {
    await page.locator('[data-help="payment-subscriptions"] label', { hasText: text }).locator('input').check();
}

/** Brings the subscriptions card's header (with «إضافة دين سابق») to the bottom edge of the screen. */
async function debtCardHeader(page) {
    await page.locator('[data-help="player-add-previous-debt"]').evaluate((el) => {
        el.scrollIntoView({ block: 'end', behavior: 'instant' });
        // A little room under the button for its ring; the first row stays below the edge.
        window.scrollBy({ top: 24, behavior: 'instant' });
    });
}

/**
 * Scrolls a data-help element to the middle of the screen, at once. The app scrolls
 * smoothly (html { scroll-behavior: smooth }), and the scroll a Playwright fill/select
 * starts can land after an instant scroll and move the page again: on a busy machine
 * player-edit-new-branch then ended with its card cut at the edge. So the page is let
 * come to rest first, and the element is centred again if a late scroll moved it.
 */
async function centre(page, name) {
    const el = page.locator(`[data-help="${name}"]`).first();
    await settle(page);
    for (let i = 0; i < 3; i++) {
        const y = await el.evaluate((node) => {
            node.scrollIntoView({ block: 'center', behavior: 'instant' });
            return window.scrollY;
        });
        await settle(page);
        if ((await page.evaluate(() => window.scrollY)) === y) return;
    }
}

/** Waits until the page has not scrolled for 20 frames (about a third of a second), at most 300 frames. */
async function settle(page) {
    await page.evaluate(() => new Promise((resolve) => {
        let last = window.scrollY;
        let still = 0;
        let frames = 0;
        const tick = () => {
            if (window.scrollY === last) still += 1;
            else { still = 0; last = window.scrollY; }
            frames += 1;
            if (still >= 20 || frames >= 300) resolve();
            else requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    }));
}

/** Types «أحمد» in the players search and waits for the list to reload. */
async function searchAhmed(page) {
    await page.locator('[data-help="players-search"] input').fill('أحمد');
    await page.waitForURL(/search=/);
    await page.waitForLoadState('networkidle');
}

/** The example of the add-player article: أحمد بن علي, born 14/03/2012. */
async function fillAhmed(page) {
    await page.locator('[data-help="player-lastname"] input').fill('بن علي');
    await page.locator('[data-help="player-firstname"] input').fill('أحمد');
    await page.locator('[data-help="player-father"] input').fill('محمد');
    await page.locator('[data-help="player-grandfather"] input').fill('الطاهر');
    await page.locator('[data-help="player-birthdate"] input').fill('2012-03-14');
    await page.locator('[data-help="player-birthdate"] input').blur();
}

/** Picks a branch in «أضف فرعاً…», then fills the card it adds: join date and category. */
async function addBranch(page, branch, card, category, date) {
    await page.locator('[data-help="player-add-branch"] button').first().click();
    await page.locator('[data-help="player-add-branch"] li', { hasText: branch }).click();
    const added = page.locator(`[data-help="${card}"]`);
    await added.locator('input[type="date"]').fill(date);
    await added.locator('select').first().selectOption({ label: category });
    await added.locator('select').first().blur();
    await added.locator('input[type="date"]').blur();
    await centre(page, card);
}

/** The create-annual example: «اشتراك سنوي», this season (already chosen), 3000 / 5000. */
async function fillAnnual(page) {
    await page.locator('[data-help="sub-name"] input').fill('اشتراك سنوي');
    await page.locator('[data-help="sub-prices"] input').nth(0).fill('3000');
    await page.locator('[data-help="sub-prices"] input').nth(1).fill('5000');
    await blur(page);
}

/** The create-annual example to its end: both branches, every category, «أشبال» at 2500 / 4000. */
async function fillAnnualScope(page) {
    await fillAnnual(page);
    await tickAll(page, 'sub-branches');
    await tickAll(page, 'sub-categories');
    const row = page.locator('[data-help="sub-category-price"]');
    await row.locator('input').nth(0).fill('2500');
    await row.locator('input').nth(1).fill('4000');
    await blur(page);
}

/** The create-exceptional example: «رحلة صيفية», 1500 for everyone. */
async function fillTrip(page) {
    await page.locator('[data-help="sub-kind-exceptional"]').click();
    await page.locator('[data-help="sub-name"] input').fill('رحلة صيفية');
    await page.locator('[data-help="sub-prices"] input').nth(0).fill('1500');
    await page.locator('[data-help="sub-prices"] input').nth(1).fill('1500');
    await blur(page);
}

/** The trip's scope: Football (which narrows the categories), then «أقل من 14 سنة». */
async function tripScope(page) {
    await page.locator('[data-help="sub-branches"] label', { hasText: 'كرة القدم' }).locator('input').check();
    await page.locator('[data-help="sub-categories"] label', { hasText: 'أقل من 14 سنة' }).locator('input').check();
    await blur(page);
}

/** Drops the focus ring of the last field typed in or ticked. */
async function blur(page) {
    await page.evaluate(() => document.activeElement?.blur());
}

/** Ticks every checkbox inside a data-help block. */
async function tickAll(page, name) {
    for (const box of await page.locator(`[data-help="${name}"] input[type="checkbox"]`).all()) await box.check();
}
