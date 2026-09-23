// Adds or updates flat keys in all three UI catalogs at once, so ar/fr/en can
// never drift apart. Keys stay in the files' existing order (default
// code-point sort) and format (4-space JSON, trailing newline).
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
  const catalog = JSON.parse(readFileSync(path, 'utf8'));
  for (const [key, values] of Object.entries(additions)) catalog[key] = values[locale];
  const sorted = Object.fromEntries(Object.keys(catalog).sort().map((k) => [k, catalog[k]]));
  writeFileSync(path, JSON.stringify(sorted, null, 4) + '\n');
}

console.log(`✓ ${Object.keys(additions).length} key(s) written to ar/en/fr`);
