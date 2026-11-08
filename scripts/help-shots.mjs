// Regenerates the Help Center screenshots on a throwaway demo club.
//
//   npm run build && npm run help:shots          all shots
//   npm run help:shots -- players-index          one shot (several names: a few shots, in list order)
//
// Dev machine only (needs `npx playwright install chromium` once). Never touches
// the real database: it migrates + seeds storage/help-demo.sqlite and serves it
// on its own port, writing public/help-shots/ar. Fails loudly when a data-help target is missing.
import { execFileSync, spawn } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { chromium } from 'playwright';
import sharp from 'sharp';
import shots from '../resources/help/shots.js';

const root = resolve(import.meta.dirname, '..');
const locale = 'ar';
// Chromium draws native controls (date fields, the file button) in the browser's
// UI language, so it must match the articles: day/month/year, Western digits.
// The context locale alone changes nothing there, so the full Chromium build is
// launched with --lang (the headless shell keeps an English file button).
// ar-DZ gives Arabic-Indic digits while the text says 14/03/2012, so fr-DZ:
// 14/03/2012 and a French, not English, file button. The app itself stays Arabic.
const browserLocale = 'fr-DZ';
const port = 8765;
const base = `http://127.0.0.1:${port}`;
const viewport = { width: 1280, height: 800 };
const db = join(root, 'storage', 'help-demo.sqlite');
const out = join(root, 'public', 'help-shots', locale);
const only = process.argv.slice(2);

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
};

const list = only.length ? shots.filter((s) => only.includes(s.name)) : shots;
if (list.length === 0 || list.length !== (only.length || list.length)) {
    console.error(`No shot named "${only.join(', ')}" in resources/help/shots.js`);
    process.exit(1);
}
if (existsSync(join(root, 'public', 'hot'))) {
    console.warn('public/hot exists: pages will load assets from the Vite dev server, keep it running.');
}

// An old server still on the port would answer with the wrong database.
const portTaken = await fetch(`${base}/login`).then(() => true, () => false);
if (portTaken) throw new Error(`Port ${port} is already in use — stop the old server first.`);

rmSync(db, { force: true });
writeFileSync(db, '');
execFileSync('php', ['artisan', 'migrate:fresh', '--force', '--seed', '--seeder=HelpDemoSeeder'], { cwd: root, env, stdio: 'inherit' });

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

let browser;
try {
    await waitForServer();
    browser = await chromium.launch({ channel: 'chromium', args: [`--lang=${browserLocale}`] });

    const loginContext = await browser.newContext({ viewport, locale: browserLocale });
    const loginPage = await loginContext.newPage();
    await loginPage.goto(`${base}/login`);
    await loginPage.fill('#username', 'demo');
    await loginPage.fill('#password', 'demo-help-2026');
    // Enter submits the form (PrimaryButton may not carry type="submit").
    await Promise.all([
        loginPage.waitForURL((url) => !url.pathname.startsWith('/login')),
        loginPage.press('#password', 'Enter'),
    ]);
    await loginPage.goto(`${base}/lang/${locale}`);
    const signedIn = await loginContext.storageState();
    await loginContext.close();

    for (const theme of ['light', 'dark']) {
        for (const shot of list) {
            const context = await browser.newContext({
                viewport,
                locale: browserLocale,
                colorScheme: theme,
                storageState: shot.guest ? undefined : signedIn,
            });
            await context.addInitScript((t) => localStorage.setItem('theme', t), theme);
            await capture(context, shot, theme);
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
}
