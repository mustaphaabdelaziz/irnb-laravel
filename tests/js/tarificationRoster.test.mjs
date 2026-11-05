import { test } from 'node:test';
import assert from 'node:assert/strict';
import { autoPlayers, averagePrice } from '../../resources/js/lib/tarification.js';

const FOOT = 1;
const SWIM = 2;
const SENIOR = 10;
const U15 = 20;

const twoBranch = {
    category_id: SENIOR,
    is_student: true,
    count: 3,
    registrations: [{ branch_id: FOOT, category_id: SENIOR }, { branch_id: SWIM, category_id: U15 }],
};
const noRegistration = { category_id: SENIOR, is_student: true, count: 2, registrations: [] };

const sub = (over = {}) => ({
    amount_student: 1500,
    amount_worker: 1500,
    category_ids: [],
    branch_ids: [],
    category_prices: {},
    ...over,
});

test('branch and category must hold on one registration', () => {
    assert.equal(autoPlayers([twoBranch], sub({ branch_ids: [SWIM], category_ids: [U15] })), 3);
    assert.equal(autoPlayers([twoBranch], sub({ branch_ids: [SWIM], category_ids: [SENIOR] })), 0);
    assert.equal(autoPlayers([twoBranch], sub({ branch_ids: [FOOT] })), 3);
    assert.equal(autoPlayers([twoBranch], sub({ category_ids: [U15] })), 3);
});

test('a two-branch player counts once', () => {
    assert.equal(autoPlayers([twoBranch], sub()), 3);
    assert.equal(autoPlayers([twoBranch], sub({ branch_ids: [FOOT, SWIM] })), 3);
});

test('no registration matches on its own category and never on a branch', () => {
    assert.equal(autoPlayers([noRegistration], sub({ category_ids: [SENIOR] })), 2);
    assert.equal(autoPlayers([noRegistration], sub({ category_ids: [U15] })), 0);
    assert.equal(autoPlayers([noRegistration], sub({ branch_ids: [FOOT] })), 0);
});

test('price follows Subscription::amountFor', () => {
    const prices = {
        [SENIOR]: { amount_student: 900, amount_worker: 900 },
        [U15]: { amount_student: 700, amount_worker: 700 },
    };
    const categories = { category_ids: [SENIOR, U15], category_prices: prices };

    assert.equal(averagePrice([twoBranch], sub({ ...categories, branch_ids: [SWIM] })), 700);
    assert.equal(averagePrice([twoBranch], sub({ ...categories, branch_ids: [FOOT, SWIM] })), 900);
    assert.equal(averagePrice([twoBranch], sub(categories)), 900);
});

test('a main category off the list takes the sole price, then the default', () => {
    assert.equal(averagePrice([twoBranch], sub({
        category_ids: [U15],
        category_prices: { [U15]: { amount_student: 700, amount_worker: 700 } },
    })), 700);
    assert.equal(averagePrice([twoBranch], sub({
        category_ids: [U15, 30],
        category_prices: { [U15]: { amount_student: 700, amount_worker: 700 }, 30: { amount_student: 650, amount_worker: 650 } },
    })), 1500);
});
