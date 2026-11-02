import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const sourceDir = fileURLToPath(new URL('../../resources/js', import.meta.url));
const inertiaDist = fileURLToPath(new URL('../../node_modules/@inertiajs/vue3/dist/index.js', import.meta.url));

// The properties useForm puts on a form, read from the installed package: it can't be imported under node.
function reservedProperties() {
    const dist = readFileSync(inertiaDist, 'utf8');
    const start = dist.indexOf('(0, import_vue.reactive)({', dist.indexOf('function useForm('));
    const body = dist.slice(start, dist.indexOf('\n  });', start));
    return new Set([...body.matchAll(/^ {4}(\w+)(?:\(|:)/gm)].map((m) => m[1]));
}

function vueFiles(dir) {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);
        if (statSync(path).isDirectory()) return vueFiles(path);
        return entry.endsWith('.vue') ? [path] : [];
    });
}

// The top-level keys of the object literal opening at `start`.
function topLevelKeys(source, start) {
    const keys = [];
    let depth = 0;
    let quote = null;
    let expectKey = false;
    for (let i = start; i < source.length; i++) {
        const c = source[i];
        if (quote) {
            if (c === '\\') i++;
            else if (c === quote) quote = null;
        } else if (c === '\'' || c === '"' || c === '`') {
            quote = c;
            expectKey = false;
        } else if ('{[('.includes(c)) {
            depth++;
            expectKey = depth === 1;
        } else if ('}])'.includes(c)) {
            depth--;
            if (depth === 0) return keys;
        } else if (depth === 1 && c === ',') {
            expectKey = true;
        } else if (expectKey && !/\s/.test(c)) {
            expectKey = false;
            const key = source.slice(i).match(/^[A-Za-z_$][\w$]*/);
            if (key) {
                keys.push(key[0]);
                i += key[0].length - 1;
            }
        }
    }
    return keys;
}

function formFields(source) {
    const opening = /useForm\(\s*(?:['"][^'"]*['"]\s*,\s*)?(?:\(\)\s*=>\s*\(\s*)?\{/g;
    return [...source.matchAll(opening)].flatMap((m) => topLevelKeys(source, m.index + m[0].length - 1));
}

// useForm overwrites a field named like one of its own properties: "progress"
// became null on every submit, and a task saved at 60% came back at 0.
test('no form has a field named like a property of useForm', () => {
    const reserved = reservedProperties();
    for (const property of ['progress', 'processing', 'errors', 'data', 'reset', 'post']) assert.ok(reserved.has(property), property);

    const clashes = vueFiles(sourceDir).flatMap((file) => formFields(readFileSync(file, 'utf8'))
        .filter((field) => reserved.has(field))
        .map((field) => `${relative(sourceDir, file).split(sep).join('/')}: ${field}`));

    assert.deepEqual(clashes, []);
});

test('the field reader finds the keys of a form, not of the values inside it', () => {
    assert.deepEqual(formFields(`useForm({ title: '', meta: { progress: 1 }, list: [{ data: 2 }], note: 'a, reset: b' })`), ['title', 'meta', 'list', 'note']);
    assert.deepEqual(formFields(`useForm(() => ({ progress: 0 }))`), ['progress']);
    assert.deepEqual(formFields(`useForm('key', { progress: 0 })`), ['progress']);
});
