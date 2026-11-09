import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const pagesDir = fileURLToPath(new URL('../../resources/js/Pages/', import.meta.url));

const files = [
    'Equipment/Catalog/Index.vue',
    'Equipment/Catalog/Show.vue',
    'Settings/EquipmentCategories.vue',
    'Settings/StorageLocations.vue',
];

// ConfirmModal's default button is a red «حذف»: an action that deletes
// nothing (repair, fixed, lost, restore) must say what it does instead.
test('equipment confirms that delete nothing are not labelled «delete»', () => {
    for (const file of files) {
        const source = readFileSync(pagesDir + file, 'utf8');
        const modals = source.match(/<ConfirmModal\b[\s\S]*?\/>/g) ?? [];
        for (const modal of modals) {
            const handler = modal.match(/@confirm="([^"]+)"/)?.[1] ?? '';
            if (/delete|destroy/i.test(handler)) continue;
            assert.match(modal, /variant="primary"/, `${file}: ${handler} keeps the red delete button`);
            assert.match(modal, /:confirm-label="t\('confirm'\)"/, `${file}: ${handler} has no neutral label`);
        }
    }
});

// A Latin «?» glued to an Arabic label reads wrong; each confirm has its own sentence.
test('equipment confirm messages are whole translated sentences', () => {
    for (const file of [...files, 'Inventory/Session.vue']) {
        const source = readFileSync(pagesDir + file, 'utf8');
        assert.doesNotMatch(source, /t\('[^']+'\)\s*\+\s*'\?'/, `${file} builds a question with + '?'`);
    }
});

test('the confirm sentences end with the question mark of their language', () => {
    const load = (locale) => JSON.parse(readFileSync(new URL(`../../resources/js/i18n/${locale}.json`, import.meta.url), 'utf8'));
    const [ar, fr, en] = ['ar', 'fr', 'en'].map(load);
    for (const key of ['send_to_repair_confirm', 'mark_lost_confirm', 'restore_confirm', 'complete_inventory_confirm']) {
        assert.match(ar[key] ?? '', /؟$/, `ar ${key}`);
        assert.match(fr[key] ?? '', / \?$/, `fr ${key}`);
        assert.match(en[key] ?? '', /\S\?$/, `en ${key}`);
    }
});

test('the storage place field suggests a name in the reader\'s language', () => {
    const source = readFileSync(pagesDir + 'Settings/StorageLocations.vue', 'utf8');
    assert.doesNotMatch(source, /placeholder="Storage 01"/);
    assert.match(source, /:placeholder="t\('storage_location_example'\)"/);
});
