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
];

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
