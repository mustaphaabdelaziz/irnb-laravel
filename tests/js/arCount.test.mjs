import { test } from 'node:test';
import assert from 'node:assert/strict';
import { arMembers } from '../../resources/js/lib/arCount.js';

test('Arabic member counts agree with their number', () => {
    assert.equal(arMembers(1), 'عضو واحد');
    assert.equal(arMembers(2), 'عضوان');
    assert.equal(arMembers(3), '3 أعضاء');
    assert.equal(arMembers(7), '7 أعضاء');
    assert.equal(arMembers(10), '10 أعضاء');
    assert.equal(arMembers(11), '11 عضوًا');
    assert.equal(arMembers(25), '25 عضوًا');
});
