// Adds or updates flat keys in all three UI catalogs at once, so ar/fr/en can
// never drift apart. Existing keys never move (other branches insert keys in
// place, so a full re-sort would churn unrelated lines and cause merge
// conflicts): a new key goes before the first key that sorts after it, an
// existing key is updated where it stands. Format: 4-space JSON, trailing newline.
//
// Usage: node scripts/i18n-add.mjs keys.json
//   keys.json: { "some_key": { "ar": "…", "fr": "…", "en": "…" }, … }
import { readFileSync, writeFileSync } from 'node:fs';

const [, , file] = process.argv;
if (!file) {
  console.error('usage: node scripts/i18n-add.mjs keys.json');
  process.exit(1);
}

const locales = ['ar', 'en', 'fr'];
const additions = JSON.parse(readFileSync(file, 'utf8'));

for (const [key, values] of Object.entries(additions)) {
  for (const locale of locales) {
    if (typeof values?.[locale] !== 'string' || values[locale] === '') {
      console.error(`✗ "${key}" has no "${locale}" value — nothing written`);
      process.exit(1);
    }
  }
}

for (const locale of locales) {
  const path = new URL(`../resources/js/i18n/${locale}.json`, import.meta.url);
  const entries = Object.entries(JSON.parse(readFileSync(path, 'utf8')));
  for (const [key, values] of Object.entries(additions)) {
    const at = entries.findIndex(([k]) => k === key);
    if (at !== -1) {
      entries[at][1] = values[locale];
      continue;
    }
    const before = entries.findIndex(([k]) => k > key);
    entries.splice(before === -1 ? entries.length : before, 0, [key, values[locale]]);
  }
  writeFileSync(path, JSON.stringify(Object.fromEntries(entries), null, 4) + '\n');
}

console.log(`✓ ${Object.keys(additions).length} key(s) written to ar/en/fr`);
