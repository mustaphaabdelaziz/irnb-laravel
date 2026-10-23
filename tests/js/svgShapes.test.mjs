import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { svgShapes } from '../../resources/js/lib/svgShapes.js';

const source = readFileSync(new URL('../../resources/js/Components/Icon.vue', import.meta.url), 'utf8');
const icons = [...source.matchAll(/^ {4}(\w+): '(.*)',$/gm)].map(([, name, markup]) => [name, markup]);

const serialise = (shapes) => shapes
    .map(([tag, attributes]) => `<${tag} ${Object.entries(attributes).map(([k, v]) => `${k}="${v}"`).join(' ')}/>`)
    .join('');

test('svgShapes reads a run of self-closing shapes', () => {
    assert.deepEqual(svgShapes('<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5" stroke-dasharray="1.5 2.5"/>'), [
        ['circle', { cx: '12', cy: '12', r: '9' }],
        ['path', { d: 'm8.5 12 2.5 2.5 4.5-5', 'stroke-dasharray': '1.5 2.5' }],
    ]);
});

// Icon.vue builds its shapes from svgShapes instead of v-html: every icon must
// come back whole, with no shape or attribute dropped.
test('every Icon.vue icon parses back to its exact markup', () => {
    assert.ok(icons.length > 50);
    for (const [name, markup] of icons) {
        assert.equal(serialise(svgShapes(markup)), markup, name);
    }
});
