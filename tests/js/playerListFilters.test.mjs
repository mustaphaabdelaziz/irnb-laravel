import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PLAYER_FILTERS_KEY, rememberedFilterOptions, savedPlayerFilters } from '../../resources/js/lib/playerListFilters.js';

const LIST = 'http://127.0.0.1:8100/players';
const saved = { category_id: ['1'], per_page: '100' };
const read = () => saved;
const storage = (value) => ({ getItem: (key) => (key === PLAYER_FILTERS_KEY ? value : null) });

test('a bare visit to the list asks for the remembered filters', () => {
    assert.deepEqual(rememberedFilterOptions(LIST, {}, LIST, read), { data: saved });
    assert.deepEqual(rememberedFilterOptions('/players', { method: 'get' }, LIST, read), { data: saved });
    // A hover prefetch builds the same URL, so the click finds it in the cache.
    assert.deepEqual(rememberedFilterOptions(LIST, { prefetch: true, async: true }, LIST, read), { data: saved });
});

test('every other visit is left alone', () => {
    const none = {};
    assert.deepEqual(rememberedFilterOptions(`${LIST}?category_id[]=2`, {}, LIST, read), none, 'own query wins');
    assert.deepEqual(rememberedFilterOptions(`${LIST}/5`, {}, LIST, read), none, 'a player page');
    assert.deepEqual(rememberedFilterOptions('/settings', {}, LIST, read), none);
    assert.deepEqual(rememberedFilterOptions(LIST, { method: 'post' }, LIST, read), none);
    assert.deepEqual(rememberedFilterOptions(LIST, { data: { per_page: '50' } }, LIST, read), none);
    // The page's own filter reloads are partial: clearing every filter sends no
    // data and must not get the old filters back.
    assert.deepEqual(rememberedFilterOptions(LIST, { only: ['players', 'filters'], data: {} }, LIST, read), none);
    assert.deepEqual(rememberedFilterOptions(LIST, {}, LIST, () => null), none, 'nothing remembered');
});

test('savedPlayerFilters keeps the list parameters only', () => {
    assert.deepEqual(savedPlayerFilters(storage('{"category_id":["1"],"per_page":"100","junk":1,"search":""}')), { category_id: ['1'], per_page: '100' });
    assert.equal(savedPlayerFilters(storage('{"junk":1}')), null);
    assert.equal(savedPlayerFilters(storage('not json')), null);
    assert.equal(savedPlayerFilters(storage(null)), null);
    assert.equal(savedPlayerFilters(storage('[1]')), null);
});

test('savedEquipmentFilters keeps the equipment list parameters only', async () => {
    const { EQUIPMENT_FILTERS_KEY, savedEquipmentFilters } = await import('../../resources/js/lib/playerListFilters.js');
    const store = (value) => ({ getItem: (key) => (key === EQUIPMENT_FILTERS_KEY ? value : null) });
    assert.deepEqual(savedEquipmentFilters(store('{"category":["Balls"],"search":"net","category_id":["1"]}')), { category: ['Balls'], search: 'net' });
    assert.equal(savedEquipmentFilters(store('{"status":["x"]}')), null);
    assert.equal(savedEquipmentFilters(store(null)), null);
});

test('saveListFilters stores the filters and forgets them once cleared', async () => {
    const { saveListFilters } = await import('../../resources/js/lib/playerListFilters.js');
    const data = {};
    const store = { setItem: (k, v) => { data[k] = v; }, removeItem: (k) => { delete data[k]; } };
    saveListFilters('k', { category: ['Balls'] }, store);
    assert.equal(data.k, '{"category":["Balls"]}');
    saveListFilters('k', {}, store);
    assert.equal(data.k, undefined);
    saveListFilters('k', { a: 1 }, { setItem: () => { throw new Error('quota'); } });
});

test('rememberedListsOptions answers for whichever list the visit goes to', async () => {
    const { rememberedListsOptions } = await import('../../resources/js/lib/playerListFilters.js');
    const EQUIP = 'http://127.0.0.1:8100/equipment/catalogs';
    const equipSaved = { category: ['Balls'] };
    const lists = [[LIST, read], [EQUIP, () => equipSaved]];
    assert.deepEqual(rememberedListsOptions(LIST, {}, lists), { data: saved });
    assert.deepEqual(rememberedListsOptions(EQUIP, {}, lists), { data: equipSaved });
    assert.deepEqual(rememberedListsOptions(`${EQUIP}?search=x`, {}, lists), {});
    assert.deepEqual(rememberedListsOptions('/settings', {}, lists), {});
});
