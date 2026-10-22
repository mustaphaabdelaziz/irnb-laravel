import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { usesAppShell } from '../../resources/js/lib/layouts.js';

const pagesDir = fileURLToPath(new URL('../../resources/js/Pages', import.meta.url));

function pages(dir) {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);
        if (statSync(path).isDirectory()) return entry === 'Partials' ? [] : pages(path);
        return entry.endsWith('.vue') ? [path] : [];
    });
}

test('usesAppShell keeps the sign-in and public pages out of the frame', () => {
    assert.equal(usesAppShell('Auth/Login'), false);
    assert.equal(usesAppShell('Public/Home'), false);
    assert.equal(usesAppShell('Players/Index'), true);
    assert.equal(usesAppShell('Dashboard'), true);
});

// AuthenticatedLayout teleports the page header into AppShell's top bar: a page
// using it outside the frame would lose its header, and a page inside the frame
// without it would be drawn without its main column.
test('exactly the pages built on AuthenticatedLayout get the persistent frame', () => {
    const files = pages(pagesDir);
    assert.ok(files.length > 50);
    for (const file of files) {
        const name = relative(pagesDir, file).split(sep).join('/').replace(/\.vue$/, '');
        const usesLayout = readFileSync(file, 'utf8').includes('AuthenticatedLayout');
        assert.equal(usesAppShell(name), usesLayout, `${name}: AppShell ${usesAppShell(name)}, AuthenticatedLayout ${usesLayout}`);
    }
});
