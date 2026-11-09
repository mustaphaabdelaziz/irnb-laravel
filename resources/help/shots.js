// Every screenshot the help articles use. `targets` are data-help names whose
// boxes are saved for rings and magnifiers; `before` runs after the page loads
// (open a menu, type a search); the optional `after` runs once the screenshot and boxes
// are saved, to undo what the shot wrote. Each shot is taken light, then dark, before the
// next one, on one seeded database (so a shot's own writes show in its dark pass: undo
// them in `after`). `ownLogin: true` gives the shot its own fresh login as `demo` instead
// of the shared session (for «الدخول بحساب», which replaces the session's user).
// Player 1 is أحمد بن علي (HelpDemoSeeder).

// Attendance data is dated relative to the seed day (HelpDemoAttendanceSeeder): shots
// run right after seeding, so "today" here is the seed day. Session 1 is the next U17
// session (Monday or Wednesday, today included), so the calendar opens on its month:
// near a month's end it can be next month's, and "this month" would miss its chip.
// Caveats: if the seed day falls in the first ~3 weeks of the season (September), the
// season shots (att-alerts, att-ranking, att-injuries) and the 1/12 pre-season chip
// change: regenerate outside that window. The seeder dates things with the app timezone
// (CarbonImmutable::today()), this file with Node's local Date: if they differ, do not
// run the shots near midnight.
const ym = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${ym(d)}-${pad(d.getDate())}`;
const inDays = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return d; };
const nextDay = (weekdays, from = new Date(), strict = false) => {
    const d = new Date(from);
    if (strict) d.setDate(d.getDate() + 1);
    while (!weekdays.includes(d.getDay())) d.setDate(d.getDate() + 1);
    return d;
};
const sessionMonth = ym(nextDay([1, 3]));
const nextSaturday = ymd(nextDay([6], new Date(), true));
// The schedule-meeting example: «اجتماع تحضير الموسم» next Tuesday (after today) at 18:00.
const nextTuesday = nextDay([2], new Date(), true);
const U17 = 'branch_id=2&category_id=7';
// The month grid first opens on the month of 7 days ago. That is only a first guess:
// the held U17 sessions are 1-5 days before the seed day, and the one holding أمين's
// «R15» (the 2nd newest) can already be in the seed day's month (seed Sun 6 Dec: Sat 5 /
// Wed 2 Dec, guess 2026-11). amineR15Cell therefore also tries the seed day's month and
// the one before it, whichever holds the cell.
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
        targets: ['nav-members', 'nav-attendance', 'att-branch', 'att-category', 'att-session-next', 'att-add-extra', 'att-add-preseason', 'att-preseason-chip', 'att-grid-link', 'att-settings', 'att-stats-link'],
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
    // «إعدادات الحضور» (attendance/edit). The page takes no branch_id: the forms open on the
    // first branch (Swimming), so each form shot picks «كرة القدم» itself. Forms are only
    // filled, never sent.
    {
        // The timetable example: a Friday U17 row, 18:00–19:30 (the form's default times),
        // valid from today (the default «من»). Monday and Wednesday are already listed.
        name: 'set-schedule',
        url: '/attendance/settings',
        targets: ['set-schedules', 'set-schedule-form', 'set-schedule-add'],
        before: async (page) => {
            const form = page.locator('[data-help="set-schedule-form"]');
            await form.locator('select').nth(0).selectOption({ label: 'كرة القدم' });
            await form.locator('select').nth(1).selectOption({ label: 'أقل من 17 سنة' });
            await form.locator('select').nth(2).selectOption('5');
            await blur(page);
            await centre(page, 'set-schedules');
        },
    },
    {
        // The closure example typed in: «عطلة مدرسية», 20 → 22 days from today. The demo
        // already holds that very closure, which would show in the list as if added: it is
        // taken off the list (DOM only) so the shot is the reader's state before «إضافة».
        name: 'set-closure-filled',
        url: '/attendance/settings',
        targets: ['set-closures', 'set-closure-form', 'set-closure-add'],
        before: async (page) => {
            const form = page.locator('[data-help="set-closure-form"]');
            await form.locator('input[type="date"]').nth(0).fill(ymd(inDays(20)));
            await form.locator('input[type="date"]').nth(1).fill(ymd(inDays(22)));
            await form.locator('input[type="text"]').fill('عطلة مدرسية');
            await page.locator('[data-help="set-closure-list"] li', { hasText: 'عطلة مدرسية' }).evaluate((li) => li.remove());
            await blur(page);
            await centre(page, 'set-closures');
        },
    },
    // After «إضافة»: the form is reset and the closure is listed under it (the demo's own).
    { name: 'set-closure', url: '/attendance/settings', targets: ['set-closures', 'set-closure-list'], before: (page) => centre(page, 'set-closures') },
    // No set-targets shot: after review the timetable article describes the «أهداف التحضير
    // البدني» card in one sentence instead of a step (7-step cap), so nothing rings it.
    // The codes-rules example: «ت» instead of «R» for «متأخر», the unexcused-streak alert
    // at 3 (the default, left as it is), then a custom code «س» «مسافر» = «غائب بعذر».
    {
        name: 'set-codes',
        url: '/attendance/settings',
        targets: ['set-codes', 'set-code-late'],
        before: async (page) => {
            await page.locator('[data-help="set-code-late"]').fill('ت');
            await blur(page);
            await centre(page, 'set-codes');
        },
    },
    {
        name: 'set-rules',
        url: '/attendance/settings',
        targets: ['set-rules', 'set-alerts', 'set-save'],
        before: async (page) => {
            await page.locator('[data-help="set-code-late"]').fill('ت');
            await blur(page);
            await centre(page, 'set-alerts');
        },
    },
    {
        name: 'set-custom',
        url: '/attendance/settings',
        targets: ['set-custom', 'set-custom-form', 'set-custom-add'],
        before: async (page) => {
            const form = page.locator('[data-help="set-custom-form"]');
            await form.locator('input[type="text"]').nth(0).fill('س');
            await form.locator('input[type="text"]').nth(1).fill('مسافر');
            await form.locator('select').selectOption('absent_excused');
            await blur(page);
            await centre(page, 'set-custom');
        },
    },
    // The stats-ranking and alerts-injuries examples. The calendar's «الإحصائيات» button carries no filter: the
    // statistics open on the remembered branch (football), «هذا الشهر», all categories,
    // so att-stats is taken in that state (only branch_id, the shared choice). Near a
    // month's start this month can hold no held session yet: regenerate later in the month.
    { name: 'att-stats', url: '/attendance/stats?branch_id=2', targets: ['stats-tiles', 'stats-ranking-link', 'stats-alerts-link', 'stats-injuries-link'] },
    // The ranking opens on the branch's first category and on a month. After the reader
    // picks «أقل من 17 سنة» it still shows this month (often nobody has 5 sessions yet);
    // after «موسم» the season, where the 21-day window is complete.
    { name: 'att-ranking-month', url: `/attendance/ranking?${U17}`, targets: ['ranking-category', 'ranking-type'] },
    { name: 'att-ranking', url: `/attendance/ranking?${U17}&type=season`, targets: ['ranking-category', 'ranking-type', 'ranking-print-top3', 'ranking-certificate-first'] },
    // Alerts and injuries open from the statistics with no category and the current season.
    // عبد الرحمن is the only football player at risk, so his row is the first.
    { name: 'att-alerts', url: '/attendance/alerts?branch_id=2', targets: ['alerts-rules'] },
    {
        // The table (min 64rem) is wider than its card at 1280 px: the row's buttons start
        // hidden past its left edge. The reader slides it to the end, as done here.
        name: 'att-alerts-letter',
        url: '/attendance/alerts?branch_id=2',
        targets: ['alerts-letter-first', 'alerts-stats-link'],
        before: async (page) => {
            await page.locator('[data-help="alerts-letter-first"]').evaluate((el) => {
                const box = el.closest('section');
                box.scrollLeft = -(box.scrollWidth - box.clientWidth);
            });
        },
    },
    { name: 'att-injuries', url: '/attendance/injuries?branch_id=2', targets: ['injuries-current', 'injuries-current-name'] },
    {
        // رياض's page as his name on the injuries page opens it; the card loads after the page.
        name: 'att-injury-details',
        url: '/players/4?attendance_branch=2',
        targets: ['injury-add-details'],
        before: async (page) => { await centre(page, 'injury-add-details'); },
    },

    // --- Finance --- (HelpDemoFinanceSeeder). Accounts: 1 club cash, 3 bank, 4 Swimming coffre,
    // 5 Football coffre. fin-tx-saved WRITES (it saves a new entry): keep it the last finance
    // shot. Its `after` hook deletes the entry again, so later shots and the dark pass see the seeded data.
    { name: 'fin-tx-list', url: '/transactions', targets: ['nav-transactions', 'tx-add', 'tx-search', 'tx-filter-type', 'tx-filter-caisse', 'tx-filter-season', 'tx-more'] },
    {
        // The reader's state before «حفظ»: an expense typed in, caisse and method chosen.
        name: 'fin-tx-form',
        url: '/transactions/create',
        targets: ['txf-type', 'txf-title', 'txf-category', 'txf-amount', 'txf-caisse', 'txf-method'],
        before: async (page) => { await fillNets(page); },
    },
    {
        name: 'fin-tx-form-bottom',
        url: '/transactions/create',
        targets: ['txf-player', 'txf-save'],
        before: async (page) => {
            await fillNets(page);
            await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
            await settle(page);
        },
    },
    {
        name: 'fin-tx-search',
        url: '/transactions?search=' + encodeURIComponent('كهرباء'),
        targets: ['tx-search', 'tx-row-first'],
    },
    {
        name: 'fin-tx-menu',
        url: '/transactions',
        targets: ['tx-more', 'tx-export', 'tx-template', 'tx-import'],
        before: async (page) => {
            await page.locator('[data-help="tx-more"]').click();
            await page.locator('[data-help="tx-export"]').waitFor({ state: 'visible' });
            await page.waitForTimeout(400);
        },
    },
    {
        // Search «كهرباء»: the duplicate (entered second, so the newest) is the first row.
        name: 'fin-tx-duplicate',
        url: '/transactions?search=' + encodeURIComponent('كهرباء'),
        targets: ['tx-delete-first'],
    },
    {
        // The confirm dialog the reader sees after the row's «حذف»; never confirmed here.
        name: 'fin-tx-delete-confirm',
        url: '/transactions?search=' + encodeURIComponent('كهرباء'),
        targets: ['confirm-panel', 'confirm-ok'],
        before: async (page) => {
            await page.locator('[data-help="tx-delete-first"]').click();
            await page.locator('[data-help="confirm-ok"]').waitFor({ state: 'visible' });
            await page.waitForTimeout(400);
        },
    },
    {
        // One row ticked: the bar shows «1 محدد» with «إلغاء» and «حذف المحدد».
        name: 'fin-tx-bulk',
        url: '/transactions?search=' + encodeURIComponent('كهرباء'),
        targets: ['tx-select-first', 'tx-bulk-bar', 'tx-bulk-delete'],
        before: async (page) => {
            await page.locator('[data-help="tx-select-first"]').check();
            await page.locator('[data-help="tx-bulk-bar"]').waitFor({ state: 'visible' });
            await page.waitForTimeout(300);
        },
    },
    {
        // The confirm dialog after «حذف المحدد»; never confirmed here. Stays before fin-tx-saved.
        name: 'fin-tx-bulk-confirm',
        url: '/transactions?search=' + encodeURIComponent('كهرباء'),
        targets: ['confirm-panel', 'confirm-ok'],
        before: async (page) => {
            await page.locator('[data-help="tx-select-first"]').check();
            await page.locator('[data-help="tx-bulk-delete"]').click();
            await page.locator('[data-help="confirm-ok"]').waitFor({ state: 'visible' });
            await page.waitForTimeout(400);
        },
    },
    { name: 'fin-registers', url: '/finance/registers', targets: ['nav-registers', 'reg-tiles', 'reg-move', 'reg-branch-football'] },
    {
        name: 'fin-registers-history',
        url: '/finance/registers',
        targets: ['reg-history'],
        before: async (page) => {
            await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
            await settle(page);
        },
    },
    {
        // The reader's state before the last press: U14 caisse to the Football coffre, 1000, a note.
        name: 'fin-move-modal',
        url: '/finance/registers',
        targets: ['move-from', 'move-to', 'move-amount', 'move-notes', 'move-after', 'move-save'],
        before: async (page) => {
            await page.locator('[data-help="reg-move"]').click();
            await page.locator('[data-help="move-from"]').waitFor({ state: 'visible' });
            await page.locator('[data-help="move-from"]').selectOption('6');
            await page.locator('[data-help="move-to"]').selectOption('5');
            await page.locator('[data-help="move-amount"]').fill('1000');
            await page.locator('[data-help="move-notes"]').fill('تسليم مداخيل الاشتراكات');
            await blur(page);
            await page.waitForTimeout(400);
        },
    },
    { name: 'fin-caisse-add-button', url: '/finance/registers', targets: ['reg-add'] },
    {
        // The reader's state before «حفظ»: new caisse named, put in Football's coffre, linked to «أقل من 17 سنة».
        name: 'fin-caisse-add',
        url: '/finance/registers',
        targets: ['caisse-name', 'caisse-place', 'caisse-category', 'caisse-save'],
        before: async (page) => {
            await page.locator('[data-help="reg-add"]').click();
            await page.locator('[data-help="caisse-name"]').waitFor({ state: 'visible' });
            await page.locator('[data-help="caisse-name"] input').fill('صندوق أقل من 17 سنة');
            await pickOption(page, '[data-help="caisse-place"] select', /Football|كرة القدم/);
            await page.locator('[data-help="caisse-category"]').waitFor({ state: 'visible' });
            await pickOption(page, '[data-help="caisse-category"] select', /أقل من 17 سنة/);
            await blur(page);
            await page.waitForTimeout(400);
        },
    },
    { name: 'fin-caisse-edit-button', url: '/finance/registers', targets: ['reg-edit-caisse-first'] },
    {
        // Football's only caisse (U14, 1000 inside) opened; «نشط» unticked, the money warning shown. Not saved.
        name: 'fin-caisse-deactivate',
        url: '/finance/registers',
        targets: ['caisse-active', 'caisse-save'],
        before: async (page) => {
            await page.locator('[data-help="reg-edit-caisse-first"]').click();
            await page.locator('[data-help="caisse-active"]').waitFor({ state: 'visible' });
            await page.locator('[data-help="caisse-active"] input').uncheck();
            await blur(page);
            await page.waitForTimeout(400);
        },
    },
    { name: 'fin-dashboard-settings-link', url: '/finance', targets: ['nav-finance', 'fin-settings-link'] },
    { name: 'fin-seasons', url: '/finance/settings', targets: ['fy-table', 'fy-close-current', 'fy-reopen-previous'] },
    {
        name: 'fin-budget',
        url: '/finance/settings',
        targets: ['budget-season', 'budget-line-eqp', 'budget-save'],
        before: async (page) => { await centre(page, 'budget-card'); },
    },
    {
        // The confirm the reader sees after «إقفال السنة» on the current season; never confirmed here.
        name: 'fin-close-confirm',
        url: '/finance/settings',
        targets: ['confirm-panel', 'confirm-ok'],
        before: async (page) => {
            await page.locator('[data-help="fy-close-current"]').click();
            await page.locator('[data-help="confirm-ok"]').waitFor({ state: 'visible' });
            await page.waitForTimeout(400);
        },
    },
    { name: 'fin-dashboard', url: '/finance', targets: ['nav-finance', 'fin-years', 'fin-banner', 'fin-tiles', 'fin-export'] },
    {
        name: 'fin-dashboard-budget',
        url: '/finance',
        targets: ['fin-budget-vs-actual'],
        before: async (page) => { await centre(page, 'fin-budget-vs-actual'); },
    },
    {
        name: 'fin-dashboard-years',
        url: '/finance',
        targets: ['fin-all-years'],
        before: async (page) => { await centre(page, 'fin-all-years'); },
    },
    // --- Settings & users --- (HelpDemoUsersSeeder). Football = branch 2. Nothing here saves.
    {
        name: 'set-identity-basic',
        url: '/settings',
        targets: ['nav-settings', 'settings-tab-basic', 'settings-club-names', 'settings-short-name', 'settings-save'],
        // «الإعدادات» is the last item of «النظام»: it sits below the sidebar's visible part.
        before: async (page) => { await navIntoView(page, 'nav-settings'); },
    },
    {
        // Never click a swatch: it saves the theme for every later shot.
        name: 'set-identity-branding',
        url: '/settings',
        targets: ['settings-tab-branding', 'settings-colors', 'settings-color-blue'],
        before: async (page) => { await page.locator('[data-help="settings-tab-branding"]').click(); },
    },
    {
        // The logo field and «حفظ» sit below the colour card; the field stays empty (no demo logo).
        name: 'set-identity-logo',
        url: '/settings',
        targets: ['settings-logo', 'settings-branding-save'],
        before: async (page) => {
            await page.locator('[data-help="settings-tab-branding"]').click();
            await centre(page, 'settings-logo');
        },
    },
    {
        // Step 1 of add-branch: the list as the reader finds it, before typing.
        name: 'set-branch-list',
        url: '/branches',
        targets: ['nav-branches'],
        before: async (page) => { await navIntoView(page, 'nav-branches'); },
    },
    {
        // The reader's state before «إضافة»: name and code typed.
        name: 'set-branch-add',
        url: '/branches',
        targets: ['nav-branches', 'branch-name', 'branch-code', 'branch-add'],
        before: async (page) => {
            await navIntoView(page, 'nav-branches');
            await page.fill('input[data-help="branch-name"]', 'كرة اليد');
            await page.fill('input[data-help="branch-code"]', 'HB');
            await blur(page);
        },
    },
    { name: 'set-branch-rows', url: '/branches', targets: ['branch-row-football', 'branch-set-main', 'branch-members', 'branch-edit'] },
    { name: 'set-branch-detail', url: '/branches/2', targets: ['branch-tab-general', 'branch-tab-categories'] },
    // Football's categories tab (branch 2); «الفئات» in the sidebar opens the main branch (Swimming).
    {
        name: 'set-categories',
        url: '/branches/2/categories',
        targets: ['nav-categories', 'cat-name', 'cat-add', 'cat-toggle-first'],
        before: async (page) => { await navIntoView(page, 'nav-categories'); },
    },
    {
        // The reader's state before «إضافة»: the new name typed.
        name: 'set-category-typed',
        url: '/branches/2/categories',
        targets: ['cat-name', 'cat-add'],
        before: async (page) => {
            await page.fill('input[data-help="cat-name"]', 'أقل من 19 سنة');
            await blur(page);
        },
    },
    {
        name: 'set-doc-types',
        url: '/document-types',
        targets: ['nav-document-types', 'doc-add', 'doc-row-photo'],
        before: async (page) => { await navIntoView(page, 'nav-document-types'); },
    },
    {
        // Steps «الاسم» + «الصلاحية» and «الفروع»: name typed, one season chosen, both branches still ticked. Nothing saved.
        name: 'set-doc-type-name',
        url: '/document-types',
        targets: ['doc-name', 'doc-validity', 'doc-branch-1'],
        before: async (page) => {
            await page.locator('[data-help="doc-add"]').click();
            await page.locator('input[data-help="doc-name"]').waitFor({ state: 'visible' });
            await page.fill('input[data-help="doc-name"]', 'رخصة الاتحاد');
            await page.selectOption('select[data-help="doc-validity"]', 'season');
            await blur(page);
        },
    },
    {
        // Step «إلزامي»: Swimming unticked, only Football left ticked.
        name: 'set-doc-type-branches',
        url: '/document-types',
        targets: ['doc-required'],
        before: async (page) => {
            await page.locator('[data-help="doc-add"]').click();
            await page.locator('input[data-help="doc-name"]').waitFor({ state: 'visible' });
            await page.fill('input[data-help="doc-name"]', 'رخصة الاتحاد');
            await page.selectOption('select[data-help="doc-validity"]', 'season');
            await page.locator('[data-help="doc-branch-1"]').uncheck();
            await blur(page);
        },
    },
    {
        // The reader's state before «حفظ»: name, one season, Swimming unticked, required ticked.
        name: 'set-doc-type-form',
        url: '/document-types',
        targets: ['doc-required', 'doc-save'],
        before: async (page) => {
            await page.locator('[data-help="doc-add"]').click();
            await page.locator('input[data-help="doc-name"]').waitFor({ state: 'visible' });
            await page.fill('input[data-help="doc-name"]', 'رخصة الاتحاد');
            await page.selectOption('select[data-help="doc-validity"]', 'season');
            await page.locator('[data-help="doc-branch-1"]').uncheck();
            await page.locator('[data-help="doc-required"]').check();
            await blur(page);
        },
    },
    {
        // «المستخدمون» is the last-but-one item of «النظام», below the sidebar's visible part.
        name: 'set-users',
        url: '/users',
        targets: ['nav-users', 'users-new', 'users-search', 'users-filters', 'users-filter-activity'],
        before: async (page) => { await navIntoView(page, 'nav-users'); },
    },
    // The new-user form in four states (nothing is ever submitted): empty, login typed, names typed, role chosen.
    {
        name: 'set-user-create-login',
        url: '/users/create',
        targets: ['uc-username', 'uc-password', 'uc-password-confirm'],
    },
    {
        name: 'set-user-create-name',
        url: '/users/create',
        targets: ['uc-firstname', 'uc-lastname', 'uc-phone'],
        before: async (page) => {
            await fillUserLogin(page);
            await blur(page);
            await centre(page, 'uc-firstname');
        },
    },
    {
        name: 'set-user-create-role',
        url: '/users/create',
        targets: ['uc-role'],
        before: async (page) => {
            await fillUserLogin(page);
            await fillUserNames(page);
            await blur(page);
            await centre(page, 'uc-role');
        },
    },
    {
        // The reader's state before «إنشاء» (never pressed).
        name: 'set-user-create',
        url: '/users/create',
        targets: ['uc-create'],
        before: async (page) => {
            await fillUserLogin(page);
            await fillUserNames(page);
            await page.locator('[data-help="uc-role"] button').first().click();
            await page.locator('[data-help="uc-role"] li').filter({ hasText: /^\s*مدرب\s*$/ }).first().click();
            await blur(page);
            await centre(page, 'uc-create');
        },
    },
    { name: 'set-users-rows', url: '/users', targets: ['users-row-layla', 'users-toggle-samir', 'users-reset-nadia', 'users-delete-layla'] },
    {
        // The reset window as it opens: both fields empty.
        name: 'set-user-reset-open',
        url: '/users',
        targets: ['users-reset-new', 'users-reset-confirm'],
        before: async (page) => {
            await page.locator('[data-help="users-reset-nadia"]').click();
            await page.locator('[data-help="users-reset-new"]').waitFor({ state: 'visible' });
        },
    },
    {
        // The reader's state before «حفظ»: the new password typed twice. Never saved.
        name: 'set-user-reset',
        url: '/users',
        targets: ['users-reset-modal', 'users-reset-new', 'users-reset-confirm', 'users-reset-save'],
        before: async (page) => {
            await page.locator('[data-help="users-reset-nadia"]').click();
            await page.locator('[data-help="users-reset-new"]').waitFor({ state: 'visible' });
            await page.fill('input[data-help="users-reset-new"]', 'Nadia#2027');
            await page.fill('input[data-help="users-reset-confirm"]', 'Nadia#2027');
            await blur(page);
        },
    },
    // Roles and extra rights (RoleController, Users/Edit). The role form and the extra-rights grid are only filled, never saved.
    {
        name: 'set-roles',
        url: '/roles',
        targets: ['nav-roles', 'roles-add', 'roles-card-coach'],
        // «الأدوار» is the third item of «النظام», below the sidebar's visible part.
        before: async (page) => { await navIntoView(page, 'nav-roles'); },
    },
    {
        // The reader's state before the ticks: the form just opened, the Arabic name typed.
        name: 'set-role-name',
        url: '/roles',
        targets: ['role-name-ar'],
        before: async (page) => { await openRoleForm(page); },
    },
    {
        // Equipment and inventory: «عرض / إضافة / تعديل» ticked, «حذف» left empty.
        name: 'set-role-rows',
        url: '/roles',
        targets: ['perm-row-equipment', 'perm-row-inventory'],
        before: async (page) => {
            await openRoleForm(page);
            await tickRoleRows(page);
            await centre(page, 'perm-row-equipment');
        },
    },
    {
        // The reader's state before «حفظ التغييرات» (never pressed): the end of the form.
        name: 'set-role-save',
        url: '/roles',
        targets: ['role-own-teams', 'role-save'],
        before: async (page) => {
            await openRoleForm(page);
            await tickRoleRows(page);
            await centre(page, 'role-save');
        },
    },
    { name: 'set-user-edit-button', url: '/users', targets: ['users-edit-samir'] },
    {
        name: 'set-user-advanced',
        url: '/users/3/edit',
        targets: ['ue-role', 'ue-overrides-toggle'],
        before: async (page) => { await centre(page, 'ue-overrides-toggle'); },
    },
    {
        // «تجاوزات متقدمة» open, «عرض» ticked on «الاشتراكات». Never saved.
        name: 'set-user-overrides',
        url: '/users/3/edit',
        targets: ['perm-row-subscriptions'],
        before: async (page) => {
            await page.locator('[data-help="ue-overrides-toggle"]').click();
            await page.locator('[data-help="ue-overrides-grid"]').waitFor({ state: 'visible' });
            await page.locator('[data-help="perm-row-subscriptions"] input').first().check();
            await blur(page);
            await centre(page, 'perm-row-subscriptions');
        },
    },
    {
        name: 'set-user-overrides-save',
        url: '/users/3/edit',
        targets: ['ue-save'],
        before: async (page) => {
            await page.locator('[data-help="ue-overrides-toggle"]').click();
            await page.locator('[data-help="ue-overrides-grid"]').waitFor({ state: 'visible' });
            await page.locator('[data-help="perm-row-subscriptions"] input').first().check();
            await blur(page);
            await centre(page, 'ue-save');
        },
    },
    // Activity (before the log-in-as shots, which write their own entries). Users: samir 3, nadia 4.
    {
        name: 'set-activity-nav',
        url: '/users/activity',
        targets: ['nav-activity'],
        before: async (page) => { await navIntoView(page, 'nav-activity'); },
    },
    { name: 'set-activity', url: '/users/activity?period=season', targets: ['activity-period', 'activity-tiles'] },
    {
        // The comparison table sits below the charts; samir's name is the link to his page.
        name: 'set-activity-table',
        url: '/users/activity?period=season',
        targets: ['activity-table', 'activity-row-3'],
        before: async (page) => { await centre(page, 'activity-row-3'); },
    },
    {
        name: 'set-activity-user',
        url: '/users/3/activity?period=season',
        targets: ['activity-tiles'],
    },
    {
        // The summary cards sit below the charts; the first count opens that action's entries.
        name: 'set-activity-user-count',
        url: '/users/3/activity?period=season',
        targets: ['activity-count-first'],
        before: async (page) => { await centre(page, 'activity-count-first'); },
    },
    {
        name: 'set-activity-entries',
        url: '/users/4/activity?period=season&action=transaction_recorded',
        targets: ['activity-entries'],
        before: async (page) => { await centre(page, 'activity-entries'); },
    },
    { name: 'set-impersonate-button', url: '/users', targets: ['users-impersonate-samir'] },
    {
        // Own session: «الدخول بحسابه» replaces the logged-in user. Leaves through the banner afterwards.
        name: 'set-impersonating',
        url: '/users',
        ownLogin: true,
        targets: ['impersonate-banner', 'impersonate-leave'],
        before: async (page) => {
            await Promise.all([
                page.waitForURL(/\/dashboard/),
                page.locator('[data-help="users-impersonate-samir"]').click(),
            ]);
            await page.waitForLoadState('networkidle');
            // The «أنت الآن داخل…» toast covers the banner for two seconds.
            await page.getByText('أنت الآن داخل بحساب هذا المستخدم.').waitFor({ state: 'hidden', timeout: 10000 });
        },
        after: async (page) => {
            await Promise.all([
                page.waitForURL(/\/users/),
                page.locator('[data-help="impersonate-leave"]').click(),
            ]);
        },
    },
    // --- Equipment --- (HelpDemoEquipmentSeeder). Catalogs: 2 «قمع تدريب» (150), 4 «ساعة توقيت» (tracked,
    // units «ساعة توقيت 1..3»). Nothing here saves: forms are filled, «إضافة» / «حفظ» only ringed.
    {
        name: 'eq-equipment-lists-categories',
        url: '/equipment-categories',
        targets: ['nav-equipment-categories'],
        // «فئات المعدات» sits low in «تهيئة التطبيق», below the sidebar's visible part.
        before: async (page) => { await navIntoView(page, 'nav-equipment-categories'); },
    },
    {
        // The reader's state before «إضافة»: «حقائب», code BAG, a description.
        name: 'eq-equipment-lists-category-typed',
        url: '/equipment-categories',
        targets: ['eqcat-name', 'eqcat-code', 'eqcat-description', 'eqcat-add'],
        before: async (page) => {
            await page.fill('input[data-help="eqcat-name"]', 'حقائب');
            await page.fill('input[data-help="eqcat-code"]', 'BAG');
            await page.fill('input[data-help="eqcat-description"]', 'حقائب الفرق');
            await blur(page);
        },
    },
    {
        name: 'eq-equipment-lists-places',
        url: '/storage-locations',
        targets: ['nav-storage-locations'],
        before: async (page) => { await navIntoView(page, 'nav-storage-locations'); },
    },
    {
        // The reader's state before «إضافة»: the new place typed.
        name: 'eq-equipment-lists-place-typed',
        url: '/storage-locations',
        targets: ['loc-name', 'loc-add'],
        before: async (page) => {
            await page.fill('input[data-help="loc-name"]', 'مخزن القاعة');
            await blur(page);
        },
    },
    {
        name: 'eq-add-catalog-list',
        url: '/equipment/catalogs',
        targets: ['nav-equipment', 'eq-add-catalog'],
        before: async (page) => { await navIntoView(page, 'nav-equipment'); },
    },
    {
        // The add-catalog example before «حفظ»: «حبل قفز», «معدات التدريب», 400, not tracked.
        name: 'eq-add-catalog-form',
        url: '/equipment/catalogs/create',
        targets: ['eq-name', 'eq-category', 'eq-brand', 'eq-price', 'eq-track', 'eq-save'],
        before: async (page) => {
            await page.fill('input[data-help="eq-name"]', 'حبل قفز');
            await page.selectOption('select[data-help="eq-category"]', { label: 'معدات التدريب' });
            await page.fill('input[data-help="eq-price"]', '400');
            await blur(page);
        },
    },
    {
        // The similar-name hint: «كره قدم مقاس 5» is the demo's «كرة قدم مقاس 5» spelled with ه (nameKey). It never blocks.
        name: 'eq-add-catalog-similar',
        url: '/equipment/catalogs/create',
        targets: ['eq-name', 'eq-similar'],
        before: async (page) => {
            await page.fill('input[data-help="eq-name"]', 'كره قدم مقاس 5');
            await page.locator('[data-help="eq-similar"]').waitFor({ state: 'visible' });
            await blur(page);
        },
    },
    {
        name: 'eq-receive-stock-list',
        url: '/equipment/catalogs',
        targets: ['nav-equipment', 'eq-catalog-link-2'],
        before: async (page) => { await navIntoView(page, 'nav-equipment'); },
    },
    { name: 'eq-receive-stock-button', url: '/equipment/catalogs/2', targets: ['eq-receive'] },
    {
        // The form card filled: 10 typed, 150 from the catalog, today, «جديد», «المخزن الرئيسي», no branch.
        name: 'eq-receive-stock-fields',
        url: '/equipment/catalogs/2',
        targets: ['eq-rcv-quantity', 'eq-rcv-price', 'eq-rcv-date', 'eq-rcv-condition', 'eq-rcv-location', 'eq-rcv-branches'],
        before: async (page) => {
            await fillCones(page);
            await centre(page, 'eq-rcv-branches');
        },
    },
    {
        // «تسجيل كمصروف» ticked: the caisse list appears on its first caisse. The reader's state before «حفظ».
        name: 'eq-receive-stock-expense',
        url: '/equipment/catalogs/2',
        targets: ['eq-rcv-expense', 'eq-rcv-caisse', 'eq-rcv-save'],
        before: async (page) => {
            await fillCones(page);
            await page.locator('[data-help="eq-rcv-expense"] input').check();
            await blur(page);
            await centre(page, 'eq-rcv-save');
        },
    },
    {
        // The other path: box left empty, «مصدر الاستلام» set to «تبرع».
        name: 'eq-receive-stock-source',
        url: '/equipment/catalogs/2',
        targets: ['eq-rcv-expense', 'eq-rcv-source'],
        before: async (page) => {
            await fillCones(page);
            await page.locator('[data-help="eq-rcv-source"] select').selectOption('donation');
            await blur(page);
            await centre(page, 'eq-rcv-source');
        },
    },
    {
        // A tracked catalog: the next serial, 2 units named «ساعة توقيت» (they become «ساعة توقيت 4» and 5).
        name: 'eq-receive-stock-serial',
        url: '/equipment/catalogs/4',
        targets: ['eq-rcv-serial', 'eq-rcv-designation', 'eq-rcv-quantity'],
        before: async (page) => {
            await openReceive(page);
            await page.locator('[data-help="eq-rcv-quantity"] input').fill('2');
            await page.locator('[data-help="eq-rcv-designation"] input').fill('ساعة توقيت');
            await page.waitForFunction(() => /\d{5}/.test(document.querySelector('[data-help="eq-rcv-serial"]')?.textContent ?? ''));
            await blur(page);
        },
    },
    // Lend: catalog 1 «كرة قدم مقاس 5» (lot item 3, 20 balls; rental 3 = 2 balls with أحمد بن علي, overdue).
    // The example lends 2 more to أمين قاسمي for 7 days. «إيجار» is only ringed.
    {
        name: 'eq-lend-assign-list',
        url: '/equipment/catalogs',
        targets: ['nav-equipment', 'eq-catalog-link-1'],
        before: async (page) => { await navIntoView(page, 'nav-equipment'); },
    },
    { name: 'eq-lend-assign-catalog', url: '/equipment/catalogs/1', targets: ['eq-rent-3', 'eq-holder-return-3'] },
    {
        // The rent window as it opens: «إعارة» chosen, «لاعب» chosen, nothing picked.
        name: 'eq-lend-assign-type',
        url: '/equipment/catalogs/1',
        targets: ['eq-rent-rental', 'eq-rent-assignment'],
        before: async (page) => { await openRent(page, 3); },
    },
    {
        name: 'eq-lend-assign-player',
        url: '/equipment/catalogs/1',
        targets: ['eq-rent-player-btn', 'eq-rent-external-btn', 'eq-rent-player'],
        before: async (page) => {
            await openRent(page, 3);
            await pickAmine(page);
        },
    },
    {
        // The reader's state before «إيجار»: 2 balls, today, 7 days (the return date shows under it).
        name: 'eq-lend-assign-fields',
        url: '/equipment/catalogs/1',
        targets: ['eq-rent-quantity', 'eq-rent-date', 'eq-rent-days', 'eq-rent-save'],
        before: async (page) => {
            await openRent(page, 3);
            await pickAmine(page);
            await page.locator('[data-help="eq-rent-quantity"] input').fill('2');
            await page.locator('[data-help="eq-rent-days"] input').fill('7');
            await blur(page);
        },
    },
    {
        // The other recipient: «شخص خارجي», a name and a phone typed (the player choice is cleared).
        name: 'eq-lend-assign-external',
        url: '/equipment/catalogs/1',
        targets: ['eq-rent-external-btn', 'eq-rent-name', 'eq-rent-phone'],
        before: async (page) => {
            await openRent(page, 3);
            await page.locator('[data-help="eq-rent-external-btn"]').click();
            await page.locator('[data-help="eq-rent-name"] input').fill('مراد بلقاسم');
            await page.locator('[data-help="eq-rent-phone"] input').fill('0550123456');
            await blur(page);
        },
    },
    // Return: rental 3, أحمد بن علي's 2 balls, overdue. «إرجاع» is only ringed.
    {
        name: 'eq-return-out',
        url: '/equipment/out',
        targets: ['nav-equipment-out', 'eq-out-tab-rental', 'eq-out-tab-assignment'],
        before: async (page) => { await navIntoView(page, 'nav-equipment-out'); },
    },
    {
        name: 'eq-return-search',
        url: '/equipment/out',
        targets: ['eq-out-search', 'eq-out-overdue-3', 'eq-out-return-3'],
        before: async (page) => { await searchBenAli(page); },
    },
    {
        // The return window: 2 still out, today, «الحالة» on the lot's «جديد», a note typed.
        name: 'eq-return-form',
        url: '/equipment/out',
        targets: ['eq-ret-quantity', 'eq-ret-date', 'eq-ret-condition', 'eq-ret-notes', 'eq-ret-save'],
        before: async (page) => {
            await searchBenAli(page);
            await page.locator('[data-help="eq-out-return-3"]').click();
            await page.locator('[data-help="eq-ret-notes"] textarea').fill('رجعت الكرتان سليمتين');
            await blur(page);
        },
    },
    // Damage, repair, lost: catalog 2 «قمع تدريب» (lot item 4, 40 cones), catalog 4 «ساعة توقيت»
    // (items 7–9; item 8 «ساعة توقيت 2» is under repair). Nothing is saved or confirmed.
    { name: 'eq-damage-split-button', url: '/equipment/catalogs/2', targets: ['eq-split-4'] },
    {
        // 2 broken cones: «تالف» is the default condition; a note typed. The reader's state before «حفظ».
        name: 'eq-damage-split-form',
        url: '/equipment/catalogs/2',
        targets: ['eq-split-quantity', 'eq-split-condition', 'eq-split-notes', 'eq-split-save'],
        before: async (page) => {
            await page.locator('[data-help="eq-split-4"]').click();
            await page.locator('[data-help="eq-split-quantity"] input').fill('2');
            await page.locator('[data-help="eq-split-notes"]').fill('انكسرا في التدريب');
            await blur(page);
        },
    },
    {
        name: 'eq-damage-units',
        url: '/equipment/catalogs/4',
        targets: ['eq-repair-7', 'eq-fixed-8', 'eq-lost-9', 'eq-history-8'],
    },
    {
        // The confirm window of «إرسال للإصلاح» for «ساعة توقيت 1». Its button is only ringed.
        name: 'eq-damage-repair-confirm',
        url: '/equipment/catalogs/4',
        targets: ['confirm-panel', 'confirm-ok'],
        before: async (page) => {
            await page.locator('[data-help="eq-repair-7"]').click();
            await page.locator('[data-help="confirm-ok"]').waitFor({ state: 'visible' });
            await page.waitForTimeout(300);
        },
    },
    { name: 'eq-damage-history', url: '/equipment/items/8/history', targets: [] },
    // Import / export: the catalog list and catalog 4 «ساعة توقيت». Windows and menus are opened,
    // a file is put in the file field; «استيراد» is only ringed, nothing is uploaded.
    {
        name: 'eq-import-export-list',
        url: '/equipment/catalogs',
        targets: ['nav-equipment', 'eq-import', 'eq-export'],
        before: async (page) => { await navIntoView(page, 'nav-equipment'); },
    },
    {
        // The import window with «تحميل النموذج» open: its «Excel (.xlsx)» line is ringed.
        name: 'eq-import-export-template',
        url: '/equipment/catalogs',
        targets: ['eq-import-template', 'eq-import-template-xlsx'],
        before: async (page) => {
            await page.locator('[data-help="eq-import"]').click();
            await openMenu(page, 'eq-import-template', 'eq-import-template-xlsx');
        },
    },
    {
        // The reader's state before «استيراد»: the filled template chosen in the file field.
        name: 'eq-import-export-file',
        url: '/equipment/catalogs',
        targets: ['eq-import-file', 'eq-import-submit'],
        before: async (page) => {
            await page.locator('[data-help="eq-import"]').click();
            await chooseSheet(page, 'eq-import-file', 'معدات النادي.xlsx');
        },
    },
    { name: 'eq-import-export-units-button', url: '/equipment/catalogs/4', targets: ['eq-units-import', 'eq-units-export'] },
    {
        // The units import window with «تسجيل كمصروف» ticked: the caisse list shows on its first caisse.
        name: 'eq-import-export-units-expense',
        url: '/equipment/catalogs/4',
        targets: ['eq-units-import-file', 'eq-units-import-expense', 'eq-units-import-caisse'],
        before: async (page) => {
            await page.locator('[data-help="eq-units-import"]').click();
            await chooseSheet(page, 'eq-units-import-file', 'ساعات توقيت.xlsx');
            await page.locator('[data-help="eq-units-import-expense"] input').check();
            await blur(page);
        },
    },
    {
        // «تصدير» open on the list: both formats ringed.
        name: 'eq-import-export-export',
        url: '/equipment/catalogs',
        targets: ['eq-export', 'eq-export-xlsx', 'eq-export-csv'],
        before: async (page) => {
            await openMenu(page, 'eq-export', 'eq-export-xlsx');
            await tagIn(page, 'eq-export-csv', page.locator('[data-help="eq-export"] a', { hasText: 'CSV' }));
        },
    },
    // Stock-take: session 2 «INV-<year>-02» (monthly, in progress, nothing counted, participant أمين قاسمي).
    // Nothing is saved: «بدء الجرد», «حفظ العد», «إنهاء الجرد» and the participants' «حفظ» are only ringed;
    // a line set to «مفقود» or a participant picked stays in the page.
    {
        name: 'eq-stock-take-list',
        url: '/equipment/stocktake',
        targets: ['nav-stocktake', 'stk-start', 'stk-delete-2'],
        before: async (page) => { await navIntoView(page, 'nav-stocktake'); },
    },
    {
        // The start form open: «شهري», today's date (the default), the note «جرد شهري».
        name: 'eq-stock-take-form',
        url: '/equipment/stocktake',
        targets: ['stk-start', 'stk-type', 'stk-date', 'stk-notes', 'stk-submit'],
        before: async (page) => {
            await page.locator('[data-help="stk-start"]').click();
            await page.locator('[data-help="stk-type"]').selectOption('monthly');
            await page.locator('[data-help="stk-notes"]').fill('جرد شهري');
            await blur(page);
        },
    },
    { name: 'eq-stock-take-session', url: '/equipment/stocktake/2', targets: ['stk-mark-all', 'stk-save-counts', 'stk-complete', 'stk-report', 'stk-export'] },
    {
        // «ساعة توقيت 3» (item 9) not found: its line set to «مفقود» in the page only. Line 7 shows the two lists.
        name: 'eq-stock-take-lines',
        url: '/equipment/stocktake/2',
        targets: ['stk-line-9', 'stk-missing-9', 'stk-condition-7', 'stk-location-7', 'stk-found-1'],
        before: async (page) => {
            await page.locator('[data-help="stk-missing-9"]').click();
            await blur(page);
            await centre(page, 'stk-line-8');
        },
    },
    {
        // A second participant picked (ياسين بوزيد), before «إضافة» and «حفظ».
        name: 'eq-stock-take-participant',
        url: '/equipment/stocktake/2',
        targets: ['stk-participant-pick', 'stk-participant-add', 'stk-participant-save'],
        before: async (page) => {
            await page.locator('[data-help="stk-participant-pick"] button').first().click();
            await page.locator('[data-help="stk-participant-pick"] input').fill('ياسين بوزيد');
            await page.locator('[data-help="stk-participant-pick"] li').first().dispatchEvent('mousedown');
            await blur(page);
        },
    },
    // Inventory report («تقرير المخزون») and the equipment reports of the reports centre. Read only.
    {
        name: 'eq-inventory-report-list',
        url: '/equipment/catalogs',
        targets: ['nav-equipment', 'eq-inventory-report'],
        before: async (page) => { await navIntoView(page, 'nav-equipment'); },
    },
    { name: 'eq-inventory-report-page', url: '/equipment/inventory', targets: ['eq-inv-print', 'eq-inv-tiles', 'eq-inv-value'] },
    {
        name: 'eq-inventory-report-lower',
        url: '/equipment/inventory',
        targets: ['eq-inv-overdue', 'eq-inv-conditions'],
        before: async (page) => { await centre(page, 'eq-inv-conditions'); },
    },
    {
        name: 'eq-inventory-report-centre',
        url: '/reports/equipment',
        targets: ['nav-reports-equipment', 'report-card-report-equipment-inventory', 'report-card-report-equipment-out', 'report-card-report-equipment-valuation', 'report-card-report-equipment-lifecycle', 'report-card-report-equipment-stocktake', 'report-card-link-equipment-stocktake'],
        before: async (page) => { await navIntoView(page, 'nav-reports-equipment'); },
    },
    // --- Board --- (HelpDemoBoardSeeder). Terms: 1 current, 2 previous, 3 next and empty
    // (its «نسخ أعضاء العهدة السابقة» shows). Committee 3 is «لجنة الإعلام», all seats empty.
    // Player 10 is هشام بوعلام (HelpDemoSeeder). Nothing here saves: the organigram picker
    // saves on a click, so its names are never clicked; forms are filled, «حفظ» only ringed.
    {
        name: 'board-roles-committees-roles',
        url: '/board-roles',
        targets: ['nav-board-roles'],
        before: async (page) => { await navIntoView(page, 'nav-board-roles'); },
    },
    {
        // The reader's state before «إضافة»: «مستشار» at the bureau level, order 5.
        name: 'board-roles-committees-role-typed',
        url: '/board-roles',
        targets: ['brole-name', 'brole-level', 'brole-order', 'brole-add'],
        before: async (page) => {
            await page.fill('input[data-help="brole-name"]', 'مستشار');
            await page.selectOption('select[data-help="brole-level"]', 'bureau');
            await page.fill('input[data-help="brole-order"]', '5');
            await blur(page);
        },
    },
    { name: 'board-roles-committees-role-rows', url: '/board-roles', targets: ['brole-edit-secretary'] },
    {
        // The pencil opened on «الأمين العام»: the field shows the name people read. Not saved.
        name: 'board-roles-committees-role-edit',
        url: '/board-roles',
        targets: ['brole-edit-name', 'brole-edit-save'],
        before: async (page) => {
            await page.locator('[data-help="brole-edit-secretary"]').click();
            await page.locator('input[data-help="brole-edit-name"]').waitFor({ state: 'visible' });
            await blur(page);
        },
    },
    {
        name: 'board-roles-committees-committees',
        url: '/board-committees',
        targets: ['nav-board-committees'],
        before: async (page) => { await navIntoView(page, 'nav-board-committees'); },
    },
    {
        // The reader's state before «إضافة»: «لجنة الشباب», order 4.
        name: 'board-roles-committees-committee-typed',
        url: '/board-committees',
        targets: ['bcom-name', 'bcom-order', 'bcom-add'],
        before: async (page) => {
            await page.fill('input[data-help="bcom-name"]', 'لجنة الشباب');
            await page.fill('input[data-help="bcom-order"]', '4');
            await blur(page);
        },
    },
    {
        name: 'board-terms-page',
        url: '/board/members',
        targets: ['nav-board-members', 'board-term-select', 'board-term-dates', 'board-term-new'],
        before: async (page) => { await navIntoView(page, 'nav-board-members'); },
    },
    {
        // «عهدة جديدة» filled like the demo's next term; «تعيين كعهدة حالية» left empty. Not saved.
        name: 'board-terms-new-form',
        url: '/board/members',
        targets: ['board-term-name', 'board-term-start', 'board-term-end', 'board-term-current', 'board-term-save'],
        before: async (page) => {
            await page.locator('[data-help="board-term-new"]').click();
            await page.locator('input[data-help="board-term-name"]').waitFor({ state: 'visible' });
            const next = await page.locator('select[data-help="board-term-select"] option[value="3"]').evaluate((o) => o.textContent.trim());
            await page.fill('input[data-help="board-term-name"]', next);
            const y = Number(next.match(/\d{4}/)[0]);
            await page.fill('input[data-help="board-term-start"]', `${y}-03-01`);
            await page.fill('input[data-help="board-term-end"]', `${y + 4}-02-28`);
            await blur(page);
        },
    },
    { name: 'board-terms-next', url: '/board/members?term=3', targets: ['board-term-select', 'board-copy-previous', 'board-term-edit'] },
    {
        // The copy confirm, open and not confirmed.
        name: 'board-terms-copy-confirm',
        url: '/board/members?term=3',
        targets: ['confirm-panel', 'confirm-ok'],
        before: async (page) => {
            await page.locator('[data-help="board-copy-previous"]').click();
            await page.locator('[data-help="confirm-ok"]').waitFor({ state: 'visible' });
        },
    },
    {
        // «تعديل العهدة» on the next term, «تعيين كعهدة حالية» ticked. Not saved.
        name: 'board-terms-edit-form',
        url: '/board/members?term=3',
        targets: ['board-term-start', 'board-term-end', 'board-term-current', 'board-term-save'],
        before: async (page) => {
            await page.locator('[data-help="board-term-edit"]').click();
            await page.locator('[data-help="board-term-current"] input').check();
            await blur(page);
        },
    },
    {
        name: 'board-add-member-page',
        url: '/board/members',
        targets: ['nav-board-members', 'board-add-member', 'board-box-president'],
        before: async (page) => { await navIntoView(page, 'nav-board-members'); },
    },
    {
        // «لجنة الإعلام» (committee 3) has no one: its head box is an empty seat. Also the
        // committee cards (committee-seats), كمال درارجة's member box (14) in «اللجنة التقنية»
        // and, for former-remove-member, ليلى بوعزة (13) under «أعضاء آخرون» and رشيد مقران's deputy box.
        name: 'board-add-member-empty',
        url: '/board/members',
        targets: ['board-seat-3-head-1', 'nav-board-members', 'board-seat-2-member-1', 'board-other-13', 'board-seat-1-deputy-1'],
        before: async (page) => { await centre(page, 'board-seat-3-head-1'); },
    },
    {
        // «بوعلام» typed in «لاعب»: the list shows هشام بوعلام (player 10). Not clicked here.
        name: 'board-add-member-player',
        url: '/board/members',
        targets: ['board-form-player', 'board-form-player-10'],
        before: async (page) => {
            await page.locator('[data-help="board-add-member"]').click();
            await page.locator('input[data-help="board-form-player"]').fill('بوعلام');
            await page.locator('[data-help="board-form-player-10"]').waitFor({ state: 'visible' });
        },
    },
    {
        // هشام picked (the form list only fills the form), defaults kept. «حفظ» not pressed.
        name: 'board-add-member-form',
        url: '/board/members',
        targets: ['board-form-player', 'board-form-role', 'board-form-term', 'board-form-status', 'board-form-save'],
        before: async (page) => {
            await page.locator('[data-help="board-add-member"]').click();
            await page.locator('input[data-help="board-form-player"]').fill('بوعلام');
            await page.locator('[data-help="board-form-player-10"]').click();
            await blur(page);
        },
    },
    {
        // The seat menu of the president's box.
        name: 'board-add-member-seat',
        url: '/board/members',
        targets: ['board-seat-whatsapp'],
        before: async (page) => {
            await page.locator('[data-help="board-box-president"]').click();
            await page.locator('[data-help="board-seat-whatsapp"]').waitFor({ state: 'visible' });
        },
    },
    {
        // The «لجنة الإعلام» head picker with «بوعلام» typed: هشام بوعلام (player 10), not on
        // the board, so no «في المكتب». A click would save: his name is only ringed.
        name: 'board-committee-seats-picker',
        url: '/board/members',
        targets: ['board-picker-search', 'board-picker-10'],
        before: async (page) => {
            await page.locator('[data-help="board-seat-3-head-1"]').click();
            await page.locator('input[data-help="board-picker-search"]').fill('بوعلام');
            await page.locator('[data-help="board-picker-10"]').waitFor({ state: 'visible' });
        },
    },
    {
        // The seat menu of كمال درارجة, member of «اللجنة التقنية». Its buttons save on a click.
        name: 'board-committee-seats-menu',
        url: '/board/members',
        targets: ['board-seat-pos-head', 'board-seat-pos-deputy', 'board-seat-pos-member', 'board-seat-leave', 'board-seat-edit', 'board-seat-remove'],
        before: async (page) => {
            await page.locator('[data-help="board-seat-2-member-1"]').click();
            await page.locator('[data-help="board-seat-pos-deputy"]').waitFor({ state: 'visible' });
        },
    },
    {
        // «إزالة من اللجنة» asked for كمال: the confirm, open and not confirmed.
        name: 'board-committee-seats-leave-confirm',
        url: '/board/members',
        targets: ['confirm-panel', 'confirm-ok'],
        before: async (page) => {
            await page.locator('[data-help="board-seat-2-member-1"]').click();
            await page.locator('[data-help="board-seat-leave"]').click();
            await page.locator('[data-help="confirm-ok"]').waitFor({ state: 'visible' });
        },
    },
    {
        // The seat menu of ليلى بوعزة (member 13, «أعضاء آخرون»): no committee block.
        name: 'board-former-remove-member-menu',
        url: '/board/members',
        targets: ['board-seat-edit', 'board-seat-remove'],
        before: async (page) => {
            await page.locator('[data-help="board-other-13"]').click();
            await page.locator('[data-help="board-seat-edit"]').waitFor({ state: 'visible' });
        },
    },
    {
        // ليلى's edit form, «الحالة» set to «سابق». «حفظ» not pressed.
        name: 'board-former-remove-member-form',
        url: '/board/members',
        targets: ['board-form-status', 'board-form-save'],
        before: async (page) => {
            await page.locator('[data-help="board-other-13"]').click();
            await page.locator('[data-help="board-seat-edit"]').click();
            await page.selectOption('select[data-help="board-form-status"]', 'former');
            await blur(page);
        },
    },
    {
        // «الأعضاء السابقون (1)» opened (the toggle only expands): مراد زيتوني (member 15).
        name: 'board-former-remove-member-list',
        url: '/board/members',
        targets: ['board-former-toggle', 'board-former-15'],
        before: async (page) => {
            await page.locator('[data-help="board-former-toggle"]').click();
            await page.locator('[data-help="board-former-15"]').waitFor({ state: 'visible' });
            await centre(page, 'board-former-toggle');
        },
    },
    {
        // The seat menu of رشيد مقران (member 12), deputy of «اللجنة المالية».
        name: 'board-former-remove-member-seat',
        url: '/board/members',
        targets: ['board-seat-leave', 'board-seat-edit', 'board-seat-remove'],
        before: async (page) => {
            await page.locator('[data-help="board-seat-1-deputy-1"]').click();
            await page.locator('[data-help="board-seat-remove"]').waitFor({ state: 'visible' });
        },
    },
    {
        // «إزالة من المكتب» asked for رشيد: the confirm, open and not confirmed.
        name: 'board-former-remove-member-confirm',
        url: '/board/members',
        targets: ['confirm-panel', 'confirm-ok'],
        before: async (page) => {
            await page.locator('[data-help="board-seat-1-deputy-1"]').click();
            await page.locator('[data-help="board-seat-remove"]').click();
            await page.locator('[data-help="confirm-ok"]').waitFor({ state: 'visible' });
        },
    },
    // Meetings (ids: 1 the previous term's, 2 «اجتماع المكتب العادي» held, 3 «اجتماع تحضير
    // الجمعية العامة» scheduled, 4 «لقاء مع الممولين» cancelled). Nothing here saves: forms are
    // typed, «إنشاء» / «حفظ المحضر» / «حفظ الحضور» / «رفع» / «إضافة مهمة» only ringed, the
    // cancel and delete confirms opened and not confirmed.
    {
        name: 'board-schedule-meeting-list',
        url: '/board/meetings',
        targets: ['nav-board-meetings', 'meet-filter', 'meet-add', 'meet-row-2', 'meet-row-3', 'meet-row-4'],
        before: async (page) => { await navIntoView(page, 'nav-board-meetings'); },
    },
    {
        // «إضافة اجتماع» opened: «اجتماع تحضير الموسم», «عادي», next Tuesday 18:00, «مقر النادي».
        name: 'board-schedule-meeting-form',
        url: '/board/meetings',
        targets: ['meet-form-title', 'meet-form-type', 'meet-form-date', 'meet-form-location'],
        before: async (page) => { await fillSeasonMeeting(page); },
    },
    {
        // The same form with two agenda items (the second row added by «إضافة»). Not created.
        name: 'board-schedule-meeting-agenda',
        url: '/board/meetings',
        targets: ['meet-form-agenda', 'meet-form-agenda-add', 'meet-form-create'],
        before: async (page) => {
            await fillSeasonMeeting(page);
            await page.locator('[data-help="meet-form-agenda"] input').nth(0).fill('تقييم الموسم الماضي');
            await page.locator('[data-help="meet-form-agenda-add"]').click();
            await page.locator('[data-help="meet-form-agenda"] input').nth(1).fill('ميزانية الموسم الجديد');
            await blur(page);
            await centre(page, 'meet-form-agenda');
        },
    },
    {
        // The calendar on next Tuesday's month, the mouse on its cell: the + shows only on hover.
        name: 'board-schedule-meeting-calendar',
        url: `/board/calendar?month=${ym(nextTuesday)}`,
        targets: ['nav-board-calendar', 'meet-cal-day', 'meet-cal-add'],
        before: async (page) => {
            await tagTuesday(page);
            await navIntoView(page, 'nav-board-calendar');
            await centre(page, 'meet-cal-day');
            await page.locator('[data-help="meet-cal-day"]').hover();
            await page.waitForTimeout(300);
        },
    },
    {
        // Its + pressed: «إضافة اجتماع» with the date filled (18:00); title and place typed. Not created.
        name: 'board-schedule-meeting-calendar-form',
        url: `/board/calendar?month=${ym(nextTuesday)}`,
        targets: ['meet-cal-form', 'meet-cal-form-date', 'meet-cal-form-create'],
        before: async (page) => {
            await tagTuesday(page);
            await page.locator('[data-help="meet-cal-add"]').click();
            await page.locator('[data-help="meet-cal-form"] input').first().fill('اجتماع تحضير الموسم');
            await page.locator('[data-help="meet-cal-form"] input').nth(2).fill('مقر النادي');
            await blur(page);
        },
    },
    // Meeting 2, held: fields, attendance (رشيد مقران, member 12, excused) and the print button.
    { name: 'board-meeting-minutes-page', url: '/board/meetings/2', targets: ['meet-status', 'meet-fields', 'meet-attendance', 'meet-att-12-excused', 'meet-att-save', 'meet-print'] },
    {
        name: 'board-meeting-minutes-minutes',
        url: '/board/meetings/2',
        targets: ['meet-minutes', 'meet-decisions', 'meet-decisions-add', 'meet-save-minutes'],
        before: async (page) => { await centre(page, 'meet-decisions'); },
    },
    {
        // A PDF chosen in «ملف المحضر» (so «رفع» is enabled), not uploaded.
        name: 'board-meeting-minutes-file',
        url: '/board/meetings/2',
        targets: ['meet-file', 'meet-file-input', 'meet-file-upload'],
        before: async (page) => {
            await page.locator('[data-help="meet-file-input"]').setInputFiles({ name: 'محضر-الاجتماع.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF\n') });
            await blur(page);
            await centre(page, 'meet-file');
        },
    },
    {
        // A task typed under «بنود العمل»: «حجز قاعة الجمعية العامة», فاطمة الزهراء بن صالح (member 10), in 5 days. Not added.
        name: 'board-meeting-minutes-task',
        url: '/board/meetings/2',
        targets: ['meet-tasks', 'meet-task-title', 'meet-task-member', 'meet-task-due', 'meet-task-add'],
        before: async (page) => {
            await page.fill('input[data-help="meet-task-title"]', 'حجز قاعة الجمعية العامة');
            await page.selectOption('select[data-help="meet-task-member"]', '10');
            await page.fill('input[data-help="meet-task-due"]', ymd(inDays(5)));
            await blur(page);
            await centre(page, 'meet-tasks');
        },
    },
    // Meeting 3, scheduled: «إلغاء الاجتماع», «حذف الاجتماع», print.
    { name: 'board-cancel-delete-meeting-page', url: '/board/meetings/3', targets: ['meet-cancel', 'meet-delete', 'meet-print'] },
    {
        // «إلغاء الاجتماع» opened, a reason typed. Not confirmed.
        name: 'board-cancel-delete-meeting-cancel',
        url: '/board/meetings/3',
        targets: ['confirm-panel', 'meet-cancel-reason', 'confirm-ok'],
        before: async (page) => {
            await page.locator('[data-help="meet-cancel"]').click();
            await page.locator('[data-help="meet-cancel-reason"]').fill('تأجيل الجمعية العامة إلى موعد لاحق');
            await blur(page);
        },
    },
    // Meeting 4, cancelled by the demo user: the banner, the locked fields, the struck title.
    { name: 'board-cancel-delete-meeting-cancelled', url: '/board/meetings/4', targets: ['meet-cancelled-banner', 'meet-fields'] },
    {
        // «حذف الاجتماع» opened on meeting 3. Not confirmed.
        name: 'board-cancel-delete-meeting-delete',
        url: '/board/meetings/3',
        targets: ['confirm-panel', 'confirm-ok'],
        before: async (page) => {
            await page.locator('[data-help="meet-delete"]').click();
            await page.locator('[data-help="confirm-ok"]').waitFor({ state: 'visible' });
        },
    },
    {
        // Writes: «ساعة توقيت 3» (item 9) is marked lost through the UI so its «استعادة» shows;
        // `after` restores it through the UI, back to the seeded state.
        name: 'eq-damage-restore',
        url: '/equipment/catalogs/4',
        targets: ['eq-restore-9'],
        before: async (page) => {
            await page.locator('[data-help="eq-lost-9"]').click();
            await page.locator('[data-help="confirm-ok"]').waitFor({ state: 'visible' });
            await page.locator('[data-help="confirm-ok"]').click();
            await page.locator('[data-help="eq-restore-9"]').waitFor({ state: 'visible' });
            // Reload so the «تم تسجيل المعدات كمفقودة.» toast is not in the picture.
            await page.reload({ waitUntil: 'networkidle' });
            await page.locator('[data-help="eq-restore-9"]').waitFor({ state: 'visible' });
        },
        after: async (page) => {
            await page.locator('[data-help="eq-restore-9"]').click();
            await page.locator('[data-help="confirm-ok"]').waitFor({ state: 'visible' });
            await page.locator('[data-help="confirm-ok"]').click();
            await page.locator('[data-help="eq-lost-9"]').waitFor({ state: 'visible', timeout: 10000 });
        },
    },
    {
        name: 'fin-tx-saved',
        url: '/transactions/create',
        targets: ['tx-show-amount', 'tx-print-ticket', 'tx-print-a4'],
        before: async (page) => {
            await fillNets(page);
            await Promise.all([
                page.waitForURL(/\/transactions\/\d+$/),
                page.locator('[data-help="txf-save"]').click(),
            ]);
            await page.waitForLoadState('networkidle');
        },
        // Archive the entry just saved (the only row with this title) through the list's «حذف».
        after: async (page) => {
            await page.goto(new URL('/transactions?search=' + encodeURIComponent('شباك المرمى'), page.url()).href, { waitUntil: 'networkidle' });
            await page.locator('tbody tr', { hasText: 'شباك المرمى' }).first().locator('button').last().click();
            await page.getByRole('button', { name: 'حذف', exact: true }).last().click();
            await page.getByText('تمت أرشفة المعاملة بنجاح.').waitFor({ state: 'visible', timeout: 10000 });
        },
    },
];

/** أمين قاسمي's grid cell holding the demo's «R15» (rows are found by last name). */
async function amineR15Cell(page) {
    const now = new Date();
    const months = [...new Set([gridMonth, ym(now), ym(new Date(now.getFullYear(), now.getMonth() - 1, 1))])];
    for (let k = 0; k < months.length; k++) {
        if (k > 0) await page.goto(new URL(`/attendance/grid?${U17}&month=${months[k]}`, page.url()).href, { waitUntil: 'networkidle' });
        const inputs = page.locator('tbody tr', { hasText: 'قاسمي' }).locator('input');
        const i = (await inputs.evaluateAll((els) => els.map((el) => el.value))).indexOf('R15');
        if (i !== -1) return inputs.nth(i);
    }
    throw new Error(`Month grid: no «R15» cell for قاسمي in ${months.join(', ')}`);
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

/** Selects the first option of a <select> whose text matches the pattern. */
async function pickOption(page, selector, pattern) {
    const value = await page.locator(selector).first().evaluate((el, src) => {
        const re = new RegExp(src);
        const o = [...el.options].find((x) => !x.disabled && re.test(x.textContent));
        return o ? o.value : null;
    }, pattern.source);
    if (value === null) throw new Error(`No option matching ${pattern} in ${selector}`);
    await page.locator(selector).first().selectOption(value);
}

/** Meetings list: opens «إضافة اجتماع» and types the schedule-meeting example. Never created. */
async function fillSeasonMeeting(page) {
    await page.locator('[data-help="meet-add"]').click();
    await page.fill('input[data-help="meet-form-title"]', 'اجتماع تحضير الموسم');
    await page.selectOption('select[data-help="meet-form-type"]', 'ordinary');
    await page.fill('input[data-help="meet-form-date"]', `${ymd(nextTuesday)}T18:00`);
    await page.fill('input[data-help="meet-form-location"]', 'مقر النادي');
    await blur(page);
}

/** Board calendar: names next Tuesday's cell `meet-cal-day` and its + `meet-cal-add`. */
async function tagTuesday(page) {
    const key = ymd(nextTuesday);
    await tagIn(page, 'meet-cal-add', page.locator(`[data-help="meet-cal-add-${key}"]`));
    await tagIn(page, 'meet-cal-day', page.locator(`[data-help="meet-cal-day-${key}"]`));
}

/** Scrolls the sidebar so a nav item is inside its visible part. */
async function navIntoView(page, name) {
    await page.locator(`[data-help="${name}"]`).first().evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'instant' }));
    await page.waitForTimeout(150);
}

/** New-user form: the login block (username and the password twice). Typing only; never submitted. */
async function fillUserLogin(page) {
    await page.fill('input[data-help="uc-username"]', 'mourad');
    await page.fill('input[data-help="uc-password"]', 'Mourad#2027');
    await page.fill('input[data-help="uc-password-confirm"]', 'Mourad#2027');
}

/** New-user form: the name block (مراد زيتوني and a phone). */
async function fillUserNames(page) {
    await page.fill('input[data-help="uc-firstname"]', 'مراد');
    await page.fill('input[data-help="uc-lastname"]', 'زيتوني');
    await page.fill('input[data-help="uc-phone"]', '0550123456');
}

/** Drops the focus ring of the last field typed in or ticked. */
async function blur(page) {
    await page.evaluate(() => document.activeElement?.blur());
}

/** Role form: opens «إضافة» and types the Arabic name. Nothing is saved. */
async function openRoleForm(page) {
    await page.locator('[data-help="roles-add"]').click();
    await page.locator('[data-help="role-name-ar"]').waitFor({ state: 'visible' });
    await page.fill('[data-help="role-name-ar"]', 'أمين المخزن');
    await blur(page);
}

/** Role form: «عرض / إضافة / تعديل» on equipment and inventory (the first three boxes of each row). */
async function tickRoleRows(page) {
    for (const row of ['perm-row-equipment', 'perm-row-inventory']) {
        for (let i = 0; i < 3; i++) await page.locator(`[data-help="${row}"] input`).nth(i).check();
    }
    await blur(page);
}

/** Ticks every checkbox inside a data-help block. */
async function tickAll(page, name) {
    for (const box of await page.locator(`[data-help="${name}"] input[type="checkbox"]`).all()) await box.check();
}

/** The record-transaction example: goal nets, 15000 from Football's coffre, paid in cash. */
async function fillNets(page) {
    await page.locator('[data-help="txf-type"] select').selectOption('expense');
    await page.locator('[data-help="txf-title"] input').fill('شراء شباك المرمى');
    await page.locator('[data-help="txf-category"] select').selectOption({ label: 'معدات' });
    await page.locator('[data-help="txf-amount"] input').fill('15000');
    await page.locator('[data-help="txf-caisse"] select').selectOption('5');
    await blur(page);
}

/**
 * Opens a format menu (ExportMenu: «تصدير», «تحميل النموذج») and tags its «Excel (.xlsx)» line:
 * the menu's lines are made by the component and carry no data-help of their own.
 */
async function openMenu(page, menu, tag) {
    await page.locator(`[data-help="${menu}"] button`).first().click();
    const xlsx = page.locator(`[data-help="${menu}"] a`, { hasText: 'Excel (.xlsx)' });
    await xlsx.waitFor({ state: 'visible' });
    await tagIn(page, tag, xlsx);
    await page.waitForTimeout(250);
}

/** Puts a spreadsheet in an import window's file field (a few dummy bytes: it is never uploaded). */
async function chooseSheet(page, field, name) {
    await page.locator(`[data-help="${field}"]`).setInputFiles({
        name,
        mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        buffer: Buffer.from('help screenshot'),
    });
    await blur(page);
}

/** Catalog page: opens «استلام مخزون» and waits for its window. */
async function openReceive(page) {
    await page.locator('[data-help="eq-receive"]').click();
    await page.locator('[data-help="eq-rcv-quantity"]').waitFor({ state: 'visible' });
}

/** Catalog page: opens the rent window of a lot (its «إيجار» row button) and waits for it. */
async function openRent(page, itemId) {
    await page.locator(`[data-help="eq-rent-${itemId}"]`).click();
    await page.locator('[data-help="eq-rent-rental"]').waitFor({ state: 'visible' });
}

/** «المعدات المُخرَجة»: types «بن علي» in the search and waits for the filtered list. */
async function searchBenAli(page) {
    await Promise.all([
        page.waitForResponse((r) => r.url().includes('/equipment/out') && r.url().includes('search=')),
        page.locator('[data-help="eq-out-search"] input').fill('بن علي'),
    ]);
    await page.waitForLoadState('networkidle');
    await blur(page);
}

/** Rent window: picks أمين قاسمي in the «لاعب» list (typed, then the first match). */
async function pickAmine(page) {
    await page.locator('[data-help="eq-rent-player"] button').first().click();
    await page.locator('[data-help="eq-rent-player"] input').fill('أمين قاسمي');
    await page.locator('[data-help="eq-rent-player"] li').first().dispatchEvent('mousedown');
    await blur(page);
}

/** The receive-stock example: 10 «قمع تدريب» (150 from the catalog), into «المخزن الرئيسي», shared (no branch). */
async function fillCones(page) {
    await openReceive(page);
    await page.locator('[data-help="eq-rcv-quantity"] input').fill('10');
    await page.locator('[data-help="eq-rcv-location"] select').selectOption('المخزن الرئيسي');
    await blur(page);
}
