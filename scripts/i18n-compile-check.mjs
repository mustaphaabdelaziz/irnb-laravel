// Compiles every message in resources/js/i18n/*.json with vue-i18n's own
// message compiler. A message that does not compile throws at runtime the
// first time a page renders it (e.g. a bare "@" starts a linked message and
// fails with "SyntaxError: 10"), so this catches it before it ships.
//
// Literal special characters must be written as {'@'}, {'|'}, {'{'}, {'}'}.
//
// It also rejects a dotted key whose prefix is itself a message and whose next
// segment is a String method: vue-i18n resolves "activities.search" as a path
// first, lands on "الأنشطة".search (a function) and crashes the render.
//
// Run: node scripts/i18n-compile-check.mjs   (exit 1 on any failure)
import { baseCompile } from '@intlify/message-compiler';
import { readFileSync } from 'node:fs';

let failures = 0;
for (const locale of ['ar', 'fr', 'en']) {
    const messages = JSON.parse(readFileSync(new URL(`../resources/js/i18n/${locale}.json`, import.meta.url)));
    for (const [key, message] of Object.entries(messages)) {
        const parts = key.split('.');
        for (let i = 1; i < parts.length; i++) {
            if (typeof messages[parts.slice(0, i).join('.')] === 'string' && parts[i] in String.prototype) {
                failures++;
                console.log(`✗ ${locale} ${key}: "${parts[i]}" is a String method under the message "${parts.slice(0, i).join('.')}"; rename the key`);
            }
        }
        if (typeof message !== 'string') continue;
        const errors = [];
        baseCompile(message, { onError: (e) => errors.push(e) });
        if (errors.length) {
            failures++;
            console.log(`✗ ${locale} ${key} (compile error ${errors[0].code}): ${JSON.stringify(message)}`);
        }
    }
}

if (failures) {
    console.log(`✗ ${failures} message(s) do not compile.`);
    process.exit(1);
}
console.log('✓ every message compiles in ar/fr/en');
