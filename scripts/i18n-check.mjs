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
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { STATUS_KEYS } from '../resources/js/lib/statusLabels.js';

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

// Every status label the screens render through statusLabel().
const statusKeys = new Set(Object.values(STATUS_KEYS).flatMap((map) => Object.values(map)));

let failures = 0;
for (const locale of ['ar', 'fr', 'en']) {
  i18n.global.locale.value = locale;
  for (const key of [...keys, ...statusKeys]) {
    const out = i18n.global.t(key);
    if (out === key) {
      console.error(`  [${locale}] does not resolve: ${key}`);
      failures++;
    }
  }
}

// Every literal t('key') / $t("key") in the Vue and JS sources must exist in
// each catalog itself. Read the files directly: through t() the English
// fallback would hide a key missing in ar or fr. Keys built at runtime
// (template literals, concatenation) cannot be checked; they are listed.
const sourceRoot = fileURLToPath(new URL('../resources/js', import.meta.url));
const LITERAL = /(?<![\w.$])\$?t\(\s*(['"])([^'"\\\n]+)\1\s*(?=[,)])/g;
const DYNAMIC = /(?<![\w.$])\$?t\(\s*(`[^`]*`|['"][^'"\n]*['"]\s*\+)/g;

const sources = [];
const walkSources = (dir) => {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) {
      if (e.name !== 'i18n') walkSources(p);
    } else if (/\.(vue|js)$/.test(e.name)) sources.push(p);
  }
};
walkSources(sourceRoot);

const templateKeys = new Map(); // key -> first file using it
const dynamicUses = [];
for (const file of sources) {
  const src = readFileSync(file, 'utf8');
  for (const m of src.matchAll(LITERAL)) if (!templateKeys.has(m[2])) templateKeys.set(m[2], relative(sourceRoot, file));
  for (const m of src.matchAll(DYNAMIC)) dynamicUses.push(`${relative(sourceRoot, file)}: ${m[1]}`);
}

for (const locale of ['ar', 'fr', 'en']) {
  for (const [key, file] of templateKeys) {
    if (!Object.hasOwn(messages[locale], key)) {
      console.error(`  [${locale}] missing: ${key}  (${file})`);
      failures++;
    }
  }
}

if (dynamicUses.length) {
  console.warn(`! ${dynamicUses.length} runtime-built key(s) not checked:`);
  for (const use of dynamicUses) console.warn(`    ${use}`);
}

if (failures) {
  console.error(`\n✗ ${failures} key(s) render raw. Add them to resources/js/i18n/*.json.`);
  process.exit(1);
}
console.log(`✓ all ${keys.size} flash keys, ${statusKeys.size} status keys and ${templateKeys.size} template keys resolve in ar/fr/en`);
