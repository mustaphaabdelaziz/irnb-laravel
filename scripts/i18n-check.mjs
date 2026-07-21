// Verifies every flash.* key a controller emits actually RESOLVES through
// vue-i18n in all three locales — not merely that it exists in the JSON.
//
// The PHP FlashTranslationTest checks the catalog files directly, which once
// let a runtime bug through: te() reported flat dotted keys as missing while
// t() resolved them, so the flash toast rendered a raw "flash.player_updated".
//
// Run: node scripts/i18n-check.mjs   (exit 1 on any failure)
import { createI18n } from 'vue-i18n';
import { readFileSync, readdirSync } from 'node:fs';

const load = (l) => JSON.parse(readFileSync(new URL(`../resources/js/i18n/${l}.json`, import.meta.url)));
const messages = { ar: load('ar'), en: load('en'), fr: load('fr') };
const i18n = createI18n({ legacy: false, locale: 'ar', fallbackLocale: 'en', messages });

// Collect every flash.* key emitted by a controller or exception.
const keys = new Set();
const walk = (dir) => {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = `${dir}/${e.name}`;
    if (e.isDirectory()) walk(p);
    else if (e.name.endsWith('.php')) {
      for (const m of readFileSync(p, 'utf8').matchAll(/'(flash\.[a-z0-9_]+)'/g)) keys.add(m[1]);
    }
  }
};
walk(new URL('../app/Http/Controllers', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
walk(new URL('../app/Exceptions', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));

let failures = 0;
for (const locale of ['ar', 'fr', 'en']) {
  i18n.global.locale.value = locale;
  for (const key of keys) {
    const out = i18n.global.t(key);
    if (out === key) {
      console.error(`  [${locale}] does not resolve: ${key}`);
      failures++;
    }
  }
}

if (failures) {
  console.error(`\n✗ ${failures} flash key(s) render raw. Add them to resources/js/i18n/*.json.`);
  process.exit(1);
}
console.log(`✓ all ${keys.size} flash keys resolve in ar/fr/en`);
