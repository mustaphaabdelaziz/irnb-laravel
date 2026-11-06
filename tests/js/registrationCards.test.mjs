import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cardFromRegistration, cardPayload, newCard, withFreshIds, withoutCard } from '../../resources/js/lib/registrationCards.js';

test('an existing registration keeps its plain date and its branch\'s other positions', () => {
    const card = cardFromRegistration(
        { branch_id: 1, join_date: '2017-03-12', category_id: 2, status_id: 3, membership_id: 'FB-2017-00001', legacy_join_year: 2017 },
        [{ id: 7, branch_id: 1 }, { id: 8, branch_id: 2 }, { id: 9, branch_id: null }],
        true,
    );
    assert.equal(card.join_date, '2017-03-12');
    assert.deepEqual(card.other_position_ids, [7, 9]);
    assert.equal(card.is_new, false);
});

test('a position with no branch stays with the first card only', () => {
    const card = cardFromRegistration({ branch_id: 2 }, [{ id: 9, branch_id: null }], false);
    assert.deepEqual(card.other_position_ids, []);
});

test('the first new card of a player with no registration starts from their values', () => {
    const card = newCard(1, { category_id: 2, status_id: 3, licence_number: 'L', position_id: 5 }, true, 4);
    assert.equal(card.category_id, 2);
    assert.equal(card.status_id, 3);
    assert.equal(card.licence_number, 'L');
    assert.equal(card.join_date, '');
});

const POSITIONS = [
    { id: 5, branch_id: 1 },
    { id: 6, branch_id: 2 },
    { id: 7, branch_id: 1 },
    { id: 8, branch_id: null },
];

test('the first card carries the leave date a player already has', () => {
    const card = newCard(1, { status_id: 3, left_at: '2025-06-30' }, true, 4, POSITIONS);
    assert.equal(card.left_at, '2025-06-30');
});

test('the first card carries the main position only when it fits the branch or has none', () => {
    assert.equal(newCard(1, { position_id: 5 }, true, 4, POSITIONS).position_id, 5);
    assert.equal(newCard(1, { position_id: 8 }, true, 4, POSITIONS).position_id, 8);
    assert.equal(newCard(1, { position_id: 6 }, true, 4, POSITIONS).position_id, '');
    assert.equal(newCard(1, { position_id: 99 }, true, 4, POSITIONS).position_id, '');
});

test('the first card carries other positions of its branch and those with no branch', () => {
    const player = { other_positions: [{ id: 7, branch_id: 1 }, { id: 6, branch_id: 2 }, { id: 8, branch_id: null }] };
    assert.deepEqual(newCard(1, player, true, 4, POSITIONS).other_position_ids, [7, 8]);
    assert.deepEqual(newCard(2, player, true, 4, POSITIONS).other_position_ids, [6, 8]);
});

test('a later card carries no main position or leave date from the player', () => {
    const card = newCard(2, { position_id: 6, left_at: '2025-06-30', other_positions: [{ id: 6, branch_id: 2 }] }, false, 4, POSITIONS);
    assert.equal(card.position_id, '');
    assert.equal(card.left_at, '');
});

test('a later card lists the saved other positions of its own branch, not those of others or with none', () => {
    const player = { other_positions: [{ id: 7, branch_id: 1 }, { id: 6, branch_id: 2 }, { id: 8, branch_id: null }] };
    assert.deepEqual(newCard(2, player, false, 4, POSITIONS).other_position_ids, [6]);
    assert.deepEqual(newCard(3, player, false, 4, POSITIONS).other_position_ids, []);
});

test('deleting the first card hands its no-branch other positions to the new first card', () => {
    const cards = [
        { id: 1, branch_id: 1, other_position_ids: [7, 8] },
        { id: 2, branch_id: 2, other_position_ids: [6] },
    ];
    const rest = withoutCard(cards, 1, POSITIONS);
    assert.equal(rest.length, 1);
    assert.deepEqual(rest[0].other_position_ids, [6, 8]);
    assert.deepEqual(cards[1].other_position_ids, [6]);
});

test('handing over no-branch positions adds no duplicates', () => {
    const cards = [
        { id: 1, branch_id: 1, other_position_ids: [8] },
        { id: 2, branch_id: 2, other_position_ids: [8, 6] },
    ];
    assert.deepEqual(withoutCard(cards, 1, POSITIONS)[0].other_position_ids, [8, 6]);
});

test('deleting a later card changes nothing else', () => {
    const cards = [
        { id: 1, branch_id: 1, other_position_ids: [8] },
        { id: 2, branch_id: 2, other_position_ids: [6] },
    ];
    const rest = withoutCard(cards, 2, POSITIONS);
    assert.equal(rest.length, 1);
    assert.deepEqual(rest[0].other_position_ids, [8]);
});

test('a later new card starts blank with the default status', () => {
    const card = newCard(2, { category_id: 2, status_id: 3 }, false, 4);
    assert.equal(card.category_id, '');
    assert.equal(card.status_id, 4);
});

test('the payload sends an empty string to clear other positions and nulls for blanks', () => {
    const payload = cardPayload({
        branch_id: 1, join_date: '', category_id: '', position_id: '', other_position_ids: [],
        licence_number: '', status_id: 3, left_at: '2026-01-01', sporting_status_id: '',
    }, 3);
    assert.equal(payload.other_position_ids, '');
    assert.equal(payload.join_date, null);
    assert.equal(payload.category_id, null);
    assert.equal(payload.left_at, '2026-01-01');
});

test('only a card whose status is the left status sends a leave date', () => {
    const payload = cardPayload({ branch_id: 1, other_position_ids: [1], status_id: '4', left_at: '2026-01-01' }, 3);
    assert.equal(payload.left_at, null);
    assert.deepEqual(payload.other_position_ids, [1]);
    assert.equal(cardPayload({ branch_id: 1, status_id: '3', left_at: '2026-01-01' }, 3).left_at, '2026-01-01');
    assert.equal(cardPayload({ branch_id: 1, status_id: 3, left_at: '2026-01-01' }).left_at, null);
});

test('a left card with an empty date sends null, never a made-up day', () => {
    assert.equal(cardPayload({ branch_id: 1, status_id: 3, left_at: '' }, 3).left_at, null);
});

test('a card picks up the ID the server gave its registration after a delete', () => {
    const cards = [
        { id: 1, branch_id: 1, membership_id: '' },
        { branch_id: 2, membership_id: '', is_new: true },
        { id: 3, branch_id: 3, membership_id: 'OLD' },
    ];
    const fresh = withFreshIds(cards, [{ id: 1, membership_id: 'SW-2020-00001' }, { id: 3, membership_id: null }]);
    assert.equal(fresh[0].membership_id, 'SW-2020-00001');
    assert.equal(fresh[1].membership_id, '');
    assert.equal(fresh[2].membership_id, '');
});
