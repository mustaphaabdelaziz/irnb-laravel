import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cardFromRegistration, cardPayload, newCard } from '../../resources/js/lib/registrationCards.js';

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

test('a later new card starts blank with the default status', () => {
    const card = newCard(2, { category_id: 2, status_id: 3 }, false, 4);
    assert.equal(card.category_id, '');
    assert.equal(card.status_id, 4);
});

test('the payload sends an empty string to clear other positions and nulls for blanks', () => {
    const payload = cardPayload({
        branch_id: 1, join_date: '', category_id: '', position_id: '', other_position_ids: [],
        licence_number: '', status_id: 3, left_at: '2026-01-01', sporting_status_id: '', is_left: true,
    });
    assert.equal(payload.other_position_ids, '');
    assert.equal(payload.join_date, null);
    assert.equal(payload.category_id, null);
    assert.equal(payload.left_at, '2026-01-01');
});

test('only a left card sends a leave date', () => {
    const payload = cardPayload({ branch_id: 1, other_position_ids: [1], left_at: '2026-01-01', is_left: false });
    assert.equal(payload.left_at, null);
    assert.deepEqual(payload.other_position_ids, [1]);
});
