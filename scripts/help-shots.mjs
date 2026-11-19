// Regenerates the Help Center screenshots on a throwaway demo club.
//
//   npm run build && npm run help:shots          all shots
//   npm run help:shots -- players-index          one shot (several names: a few shots, in list order)
//   npm run help:shots -- --locale=fr            the French set (app in French), into public/help-shots/fr
//
// Dev machine only (needs `npx playwright install chromium` once). Never touches
// the real database: it migrates + seeds storage/help-demo.sqlite and serves it
// on its own port, writing public/help-shots/ar. Fails loudly when a data-help target is missing.
import { execFileSync, spawn } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { chromium } from 'playwright';
import sharp from 'sharp';

// --locale=fr|en takes the shots with the app in that language (default ar), into
// public/help-shots/<locale>. shots.js reads HELP_LOCALE for the few UI texts it waits on,
// so it is imported only once the variable is set.
const args = process.argv.slice(2);
const locale = (args.find((a) => a.startsWith('--locale='))?.split('=')[1] ?? process.env.HELP_LOCALE ?? 'ar').trim();
if (!['ar', 'fr', 'en'].includes(locale)) {
    console.error(`Unknown --locale "${locale}" (ar, fr or en).`);
    process.exit(1);
}
process.env.HELP_LOCALE = locale;
const { default: shots } = await import('../resources/help/shots.js');

const root = resolve(import.meta.dirname, '..');
// Chromium draws native controls (date fields, the file button) in the browser's
// UI language, so it must match the articles: day/month/year, Western digits.
// The context locale alone changes nothing there, so the full Chromium build is
// launched with --lang (the headless shell keeps an English file button).
// ar-DZ gives Arabic-Indic digits while the text says 14/03/2012, so fr-DZ:
// 14/03/2012 and a French, not English, file button. The app itself stays Arabic.
// English articles get en-GB: day/month/year and an English file button.
const browserLocale = { ar: 'fr-DZ', fr: 'fr-DZ', en: 'en-GB' }[locale];
const port = 8765;
const base = `http://127.0.0.1:${port}`;
const viewport = { width: 1280, height: 800 };
const db = join(root, 'storage', 'help-demo.sqlite');
const out = join(root, 'public', 'help-shots', locale);
const only = args.filter((a) => !a.startsWith('--locale='));

// The run's own storage folder, emptied at the start of every run. The public and private
// disks (generated logos, photos, files), sessions and file-cache data move here: the seeding
// CLI gets LARAVEL_STORAGE_PATH, but the served app (php -S, variables_order GPCS) does not see
// it in $_SERVER/$_ENV, so the server reads HELP_DEMO_PUBLIC_ROOT / HELP_DEMO_PRIVATE_ROOT /
// HELP_DEMO_STORAGE instead. Compiled views and logs stay in real storage.
const demoStorage = join(root, 'storage', 'help-demo-storage');
const realPublic = join(root, 'storage', 'app', 'public');
const listRealPublic = () => (existsSync(realPublic) ? readdirSync(realPublic, { recursive: true }).map(String).sort() : []);
const realPublicBefore = listRealPublic();
const realPublicChanged = () => JSON.stringify(listRealPublic()) !== JSON.stringify(realPublicBefore);
// Only the activity files: the real private disk also holds backups other tools write.
const realPrivate = join(root, 'storage', 'app', 'private', 'activities');
const listRealPrivate = () => (existsSync(realPrivate) ? readdirSync(realPrivate, { recursive: true }).map(String).sort() : []);
const realPrivateBefore = listRealPrivate();
const realPrivateChanged = () => JSON.stringify(listRealPrivate()) !== JSON.stringify(realPrivateBefore);

// APP_CONFIG_CACHE points at a file that doesn't exist, so a cached config
// can't override DB_DATABASE (see the db-swap gotchas).
const env = {
    ...process.env,
    APP_ENV: 'local',
    APP_URL: base,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: db,
    APP_CONFIG_CACHE: join(root, 'storage', 'framework', 'help-demo-config.php'),
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'file',
    LARAVEL_STORAGE_PATH: demoStorage,
    // php artisan serve's server process does not see LARAVEL_STORAGE_PATH in $_SERVER/$_ENV
    // (only getenv), so the public disk, sessions and file cache read these variables.
    HELP_DEMO_PUBLIC_ROOT: join(demoStorage, 'app', 'public'),
    HELP_DEMO_PRIVATE_ROOT: join(demoStorage, 'app', 'private'),
    HELP_DEMO_STORAGE: demoStorage,
};

const list = only.length ? shots.filter((s) => only.includes(s.name)) : shots;
if (list.length === 0 || list.length !== (only.length || list.length)) {
    console.error(`No shot named "${only.filter((n) => !shots.some((s) => s.name === n)).join(', ')}" in resources/help/shots.js`);
    process.exit(1);
}
if (existsSync(join(root, 'public', 'hot'))) {
    console.warn('public/hot exists: pages will load assets from the Vite dev server, keep it running.');
}

// An old server still on the port would answer with the wrong database.
const portTaken = await fetch(`${base}/login`).then(() => true, () => false);
if (portTaken) throw new Error(`Port ${port} is already in use — stop the old server first.`);

rmSync(demoStorage, { recursive: true, force: true });
for (const dir of ['app/public', 'app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) {
    mkdirSync(join(demoStorage, dir), { recursive: true });
}

rmSync(db, { force: true });
writeFileSync(db, '');
execFileSync('php', ['artisan', 'migrate:fresh', '--force', '--seed', '--seeder=HelpDemoSeeder'], { cwd: root, env, stdio: 'inherit' });
if (realPublicChanged()) throw new Error('Real storage/app/public changed during the run');
if (realPrivateChanged()) throw new Error('Real storage/app/private changed during the run');

const server = spawn('php', ['artisan', 'serve', '--no-reload', '--host=127.0.0.1', `--port=${port}`], { cwd: root, env, stdio: 'ignore' });
const stopServer = () => {
    if (process.platform === 'win32') {
        try { execFileSync('taskkill', ['/pid', String(server.pid), '/T', '/F'], { stdio: 'ignore' }); } catch { /* already gone */ }
    } else {
        server.kill();
    }
};

async function waitForServer() {
    for (let i = 0; i < 60; i++) {
        try {
            const res = await fetch(`${base}/login`);
            if (res.ok) return;
        } catch { /* not up yet */ }
        await new Promise((r) => setTimeout(r, 500));
    }
    throw new Error('php artisan serve did not start on ' + base);
}

const pct = (n) => Math.round(n * 100) / 100;

async function capture(context, shot, theme) {
    const page = await context.newPage();
    // A guest page (login) has no signed-in user's language: set it on the guest session.
    if (shot.guest && locale !== 'ar') await page.goto(`${base}/lang/${locale}`);
    await page.goto(base + shot.url, { waitUntil: 'networkidle' });
    if (shot.before) await shot.before(page);
    await page.waitForTimeout(400);

    const targets = {};
    for (const name of shot.targets) {
        const el = page.locator(`[data-help="${name}"]`).first();
        if ((await el.count()) === 0) throw new Error(`Shot "${shot.name}": no element with data-help="${name}" on ${shot.url}`);
        const box = await el.boundingBox();
        if (!box) throw new Error(`Shot "${shot.name}": data-help="${name}" is hidden on ${shot.url}`);
        const t = {
            x: pct((box.x / viewport.width) * 100),
            y: pct((box.y / viewport.height) * 100),
            w: pct((box.width / viewport.width) * 100),
            h: pct((box.height / viewport.height) * 100),
        };
        if (box.width <= 0 || box.height <= 0 || t.x < -0.5 || t.y < -0.5 || t.x + t.w > 100.5 || t.y + t.h > 100.5) {
            throw new Error(`Shot "${shot.name}": data-help="${name}" is outside the screenshot (scroll it into view in \`before\`)`);
        }
        targets[name] = t;
    }

    const png = await page.screenshot({ animations: 'disabled', caret: 'hide' });
    mkdirSync(join(out, theme), { recursive: true });
    await sharp(png).webp({ quality: 82 }).toFile(join(out, theme, `${shot.name}.webp`));
    if (theme === 'light') {
        writeFileSync(join(out, `${shot.name}.json`), JSON.stringify({ ...viewport, targets }, null, 2) + '\n');
    }
    if (shot.after) await shot.after(page);
    await page.close();
}

async function signIn(context) {
    const page = await context.newPage();
    await page.goto(`${base}/login`);
    await page.fill('#username', 'demo');
    await page.fill('#password', 'demo-help-2026');
    // Enter submits the form (PrimaryButton may not carry type="submit").
    await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/login')),
        page.press('#password', 'Enter'),
    ]);
    await page.goto(`${base}/lang/${locale}`);
    await page.close();
}

let browser;
try {
    await waitForServer();
    browser = await chromium.launch({ channel: 'chromium', args: [`--lang=${browserLocale}`] });

    const loginContext = await browser.newContext({ viewport, locale: browserLocale });
    await signIn(loginContext);
    const signedIn = await loginContext.storageState();
    await loginContext.close();

    // Each shot is taken light, then dark, before the next one: a shot that writes
    // (log in as, a login) cannot make the dark pass of a later shot differ from its light pass.
    for (const shot of list) {
        for (const theme of ['light', 'dark']) {
            const context = await browser.newContext({
                viewport,
                locale: browserLocale,
                colorScheme: theme,
                storageState: shot.guest || shot.ownLogin ? undefined : signedIn,
            });
            await context.addInitScript((t) => localStorage.setItem('theme', t), theme);
            if (shot.ownLogin) await signIn(context);
            try {
                await capture(context, shot, theme);
            } catch (error) {
                // A busy machine can time a wait out or catch a page mid-render: one retry
                // on a fresh page before failing (a real missing target fails twice).
                console.warn(`… retrying ${theme} ${shot.name} (${String(error.message).split('\n')[0]})`);
                await capture(context, shot, theme);
            }
            await context.close();
            console.log(`✓ ${theme} ${shot.name}`);
        }
    }
} catch (error) {
    console.error(`\n✗ ${error.message}`);
    process.exitCode = 1;
} finally {
    await browser?.close();
    stopServer();
    rmSync(db, { force: true });
    // Names only: this would also fire if the real dev app uploads during a run.
    if (realPublicChanged()) {
        console.error('✗ Real storage/app/public changed during the run');
        process.exitCode = 1;
    }
    if (realPrivateChanged()) {
        console.error('✗ Real storage/app/private changed during the run');
        process.exitCode = 1;
    }
}
