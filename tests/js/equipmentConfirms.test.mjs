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
