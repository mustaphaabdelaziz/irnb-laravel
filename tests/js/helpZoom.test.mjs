import { test } from 'node:test';
import assert from 'node:assert/strict';
import { zoomStyle, bubbleSide } from '../../resources/js/lib/helpZoom.js';

// box values are percentages of the screenshot (x, w of width; y, h of height).

test('a centred target is framed with the margin on each side', () => {
    // 10% wide target → region 10 * (1 + 2 * 0.6) = 22% of the width → image 454.55% of the bubble.
    const s = zoomStyle({ x: 45, y: 45, w: 10, h: 10 }, 1.6);
    assert.equal(s.backgroundSize, '454.55% auto');
    // region x = 50 - 11 = 39 → 39 / (100 - 22) = 50%; region h = 22 * 1.6 = 35.2 → y = 50 - 17.6 = 32.4 → 32.4 / 64.8 = 50%.
    assert.equal(s.backgroundPosition, '50% 50%');
});

test('a tall target sizes the region by its height', () => {
    // h 16% of height = 10% of width at aspect 1.6 → region 22%.
    assert.equal(zoomStyle({ x: 48, y: 42, w: 4, h: 16 }, 1.6).backgroundSize, '454.55% auto');
});

test('regions are clamped inside the picture at the edges', () => {
    assert.equal(zoomStyle({ x: 0, y: 0, w: 10, h: 10 }, 1.6).backgroundPosition, '0% 0%');
    assert.equal(zoomStyle({ x: 90, y: 90, w: 10, h: 10 }, 1.6).backgroundPosition, '100% 100%');
});

test('tiny targets are not blown up past 8% of the width', () => {
    assert.equal(zoomStyle({ x: 50, y: 50, w: 1, h: 1 }, 1.6).backgroundSize, '1250% auto');
});

test('a huge target never asks for more than the whole picture', () => {
    const s = zoomStyle({ x: 0, y: 0, w: 100, h: 100 }, 1.6);
    assert.equal(s.backgroundSize, '100% auto');
    assert.equal(s.backgroundPosition, '0% 0%');
});

test('the bubble sits on the side away from the target', () => {
    assert.equal(bubbleSide({ x: 70, y: 10, w: 10, h: 5 }), 'left');
    assert.equal(bubbleSide({ x: 10, y: 10, w: 10, h: 5 }), 'right');
});
