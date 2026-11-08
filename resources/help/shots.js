// Every screenshot the help articles use. `targets` are data-help names whose
// boxes are saved for rings and magnifiers; `before` runs after the page loads
// (open a menu, type a search). Player 1 is أحمد بن علي (HelpDemoSeeder).
export default [
    { name: 'login', url: '/login', guest: true, targets: ['login-username', 'login-password', 'login-submit', 'login-help'] },
    { name: 'help-center', url: '/help', targets: ['help-search', 'help-button'] },
    { name: 'app-dashboard', url: '/dashboard', targets: ['sidebar', 'nav-members', 'help-button', 'theme-toggle', 'language-switch', 'sidebar-profile'] },
    {
        name: 'app-palette',
        url: '/dashboard',
        targets: ['palette-input'],
        before: async (page) => {
            await page.keyboard.press('Control+k');
            await page.locator('[data-help="palette-input"]').fill('لاعب');
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
];

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

/** Scrolls a data-help element to the middle of the screen, at once (the app scrolls smoothly). */
async function centre(page, name) {
    await page.locator(`[data-help="${name}"]`).first().evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'instant' }));
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
