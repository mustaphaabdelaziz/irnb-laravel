import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createI18n } from 'vue-i18n';

const load = (l) => JSON.parse(readFileSync(new URL(`../../resources/js/i18n/${l}.json`, import.meta.url)));
const messages = { ar: load('ar'), en: load('en'), fr: load('fr') };

const KEYS = [
    'nav.config', 'nav.system', 'nav.board_of_directors', 'nav.board_overview',
    'nav.search', 'nav.search_placeholder', 'nav.no_results',
    'nav.collapse_sidebar', 'nav.expand_sidebar',
];

test('every sidebar key resolves through t() in ar, en and fr', () => {
    const i18n = createI18n({ legacy: false, locale: 'ar', messages });
    for (const locale of ['ar', 'en', 'fr']) {
        i18n.global.locale.value = locale;
        for (const key of KEYS) assert.notEqual(i18n.global.t(key), key, `${locale}: ${key}`);
    }
});

test('owner-chosen section names', () => {
    assert.equal(messages.ar['nav.config'], 'تهيئة التطبيق');
    assert.equal(messages.en['nav.config'], 'App Configuration');
    assert.equal(messages.fr['nav.config'], 'Paramétrage');
    assert.equal(messages.ar['nav.board_of_directors'], 'مجلس إدارة النادي');
    assert.equal(messages.en['nav.board_of_directors'], 'Board of Directors');
    assert.equal(messages.fr['nav.board_of_directors'], 'Bureau du club');
});
