import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createI18n } from 'vue-i18n';
import { arMembers, arPluralIndex } from '../../resources/js/lib/arCount.js';

const load = (l) => JSON.parse(readFileSync(new URL(`../../resources/js/i18n/${l}.json`, import.meta.url)));
const [ar, fr, en] = ['ar', 'fr', 'en'].map(load);

test('Arabic member counts agree with their number', () => {
    assert.equal(arMembers(1), 'عضو واحد');
    assert.equal(arMembers(2), 'عضوان');
    assert.equal(arMembers(3), '3 أعضاء');
    assert.equal(arMembers(7), '7 أعضاء');
    assert.equal(arMembers(10), '10 أعضاء');
    assert.equal(arMembers(11), '11 عضوًا');
    assert.equal(arMembers(25), '25 عضوًا');
    assert.equal(arMembers(103), '103 أعضاء');
    assert.equal(arMembers(112), '112 عضوًا');
});

test('Arabic plural rule picks one of four forms', () => {
    assert.deepEqual([0, 1, 2, 3, 10, 11, 25, 100].map((n) => arPluralIndex(n, 4)), [2, 0, 1, 2, 2, 3, 3, 3]);
    // From 100 on the last two digits decide.
    assert.deepEqual([103, 110, 111, 150, 205, 238].map((n) => arPluralIndex(n, 4)), [2, 2, 3, 3, 2, 3]);
});

test('Arabic plural rule copes with messages of fewer forms', () => {
    assert.equal(arPluralIndex(1, 2), 0);
    assert.equal(arPluralIndex(5, 2), 1);
    assert.equal(arPluralIndex(5, 1), 0);
});

test('player-count flashes agree with their number in ar/fr/en', () => {
    const say = (locale, n) => {
        const i18n = createI18n({ legacy: false, locale, messages: { ar, fr, en }, pluralRules: { ar: arPluralIndex } });
        return i18n.global.t('flash.players_assigned', { count: n }, n);
    };
    assert.equal(say('ar', 1), 'تم إسناد لاعب واحد.');
    assert.equal(say('ar', 2), 'تم إسناد لاعبَين.');
    assert.equal(say('ar', 7), 'تم إسناد 7 لاعبين.');
    assert.equal(say('ar', 12), 'تم إسناد 12 لاعبًا.');
    assert.equal(say('fr', 1), '1 joueur affecté.');
    assert.equal(say('fr', 3), '3 joueurs affectés.');
    assert.equal(say('en', 1), '1 player assigned.');
    assert.equal(say('en', 3), '3 players assigned.');
});
