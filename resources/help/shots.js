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
];
