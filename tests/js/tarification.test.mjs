import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    UNITS, lineCost, matchingGroups, autoPlayers, averagePrice,
    defaultSessionsPerWeek, calculate, forecast, subscriptionInput,
} from '../../resources/js/lib/tarification.js';

const line = (unit, amount, quantity = 1) => ({ label: unit, unit, amount, quantity });

const sub = (over = {}) => ({
    kind: 'annual', amount_student: 15000, amount_worker: 15000,
    category_ids: [], branch_ids: [], category_prices: {}, ...over,
});

test('UNITS lists the five units', () => {
    assert.deepEqual(UNITS, ['per_session', 'per_trip', 'per_month', 'per_season', 'per_player']);
});

test('lineCost: each unit', () => {
    const ctx = { sessions: 120, players: 20 };
    assert.equal(lineCost(line('per_session', 700, 2), ctx), 168000);
    assert.equal(lineCost(line('per_trip', 15000, 10), ctx), 150000);
    assert.equal(lineCost(line('per_month', 30000, 10), ctx), 300000);
    assert.equal(lineCost(line('per_season', 5000, 3), ctx), 15000);
    assert.equal(lineCost(line('per_player', 1500), ctx), 30000);
});

test('lineCost: strings from inputs and blanks do not break it', () => {
    assert.equal(lineCost({ unit: 'per_trip', amount: '800.00', quantity: '2' }, { sessions: 0, players: 0 }), 1600);
    assert.equal(lineCost({ unit: 'per_trip', amount: '', quantity: '' }, { sessions: 0, players: 0 }), 0);
});

test('calculate: the worked example from the spec', () => {
    const r = calculate({
        lines: [line('per_session', 800), line('per_session', 700, 2), line('per_trip', 15000, 10)],
        sessions: 120, players: 20, paymentRate: 85, price: 15000,
    });
    assert.equal(r.income, 255000);
    assert.equal(r.costs, 414000);
    assert.equal(r.margin, -159000);
    assert.equal(r.marginPerPlayer, -7950);
    assert.equal(r.status, 'lose');
    assert.equal(r.breakEvenPrice, 24353);
    assert.equal(r.breakEvenPlayers, 33);
    assert.deepEqual(r.lines.map((l) => l.total), [96000, 168000, 150000]);
});

test('calculate: win and exact even', () => {
    const base = { lines: [line('per_season', 100000)], sessions: 0, players: 10, paymentRate: 100 };
    assert.equal(calculate({ ...base, price: 15000 }).status, 'win');
    assert.equal(calculate({ ...base, price: 10000 }).status, 'even');
});

test('calculate: an exact break-even price is not pushed up by float noise', () => {
    const r = calculate({ lines: [line('per_season', 255000)], sessions: 0, players: 20, paymentRate: 85, price: 15000 });
    assert.equal(r.breakEvenPrice, 15000);
    assert.equal(r.breakEvenPlayers, 20);
    assert.equal(r.status, 'even');
});

test('calculate: per-player costs grow with players', () => {
    const r = calculate({
        lines: [line('per_season', 90000), line('per_player', 1000)],
        sessions: 0, players: 10, paymentRate: 100, price: 10000,
    });
    assert.equal(r.costs, 100000);
    assert.equal(r.breakEvenPrice, 10000);
    assert.equal(r.breakEvenPlayers, 10);
});

test('calculate: no players leaves per-player figures undefined, players break-even stays', () => {
    const r = calculate({ lines: [line('per_season', 100000)], sessions: 0, players: 0, paymentRate: 100, price: 10000 });
    assert.equal(r.income, 0);
    assert.equal(r.status, 'lose');
    assert.equal(r.marginPerPlayer, null);
    assert.equal(r.breakEvenPrice, null);
    assert.equal(r.breakEvenPlayers, 10);
});

test('calculate: 0% payment has no break-even at all', () => {
    const r = calculate({ lines: [line('per_season', 1000)], sessions: 0, players: 10, paymentRate: 0, price: 10000 });
    assert.equal(r.breakEvenPrice, null);
    assert.equal(r.breakEvenPlayers, null);
});

test('calculate: a price under the per-player cost can never break even by adding players', () => {
    const r = calculate({ lines: [line('per_player', 2000)], sessions: 0, players: 10, paymentRate: 100, price: 1500 });
    assert.equal(r.breakEvenPlayers, null);
    assert.equal(r.breakEvenPrice, 2000);
});

test('calculate: a free subscription with costs has no players break-even', () => {
    const r = calculate({ lines: [line('per_season', 1000)], sessions: 0, players: 10, paymentRate: 100, price: 0 });
    assert.equal(r.breakEvenPlayers, null);
});

test('calculate: no costs means no verdict, not a win', () => {
    const r = calculate({ lines: [], sessions: 0, players: 10, paymentRate: 100, price: 10000 });
    assert.equal(r.hasCosts, false);
    assert.equal(r.status, null);
    assert.equal(r.breakEvenPrice, null);
    assert.equal(r.breakEvenPlayers, null);
});

const roster = [
    { category_id: 1, is_student: true, branch_ids: [10], count: 5 },
    { category_id: 1, is_student: false, branch_ids: [10, 20], count: 3 },
    { category_id: 2, is_student: true, branch_ids: [20], count: 4 },
    { category_id: null, is_student: true, branch_ids: [], count: 2 },
];

test('matchingGroups: no category and no branch is open to everyone', () => {
    assert.equal(matchingGroups(roster, sub()).length, 4);
    assert.equal(autoPlayers(roster, sub()), 14);
});

test('matchingGroups: category filter leaves out players with no category', () => {
    assert.equal(autoPlayers(roster, sub({ category_ids: [1] })), 8);
});

test('matchingGroups: branch filter counts a two-branch player once', () => {
    assert.equal(autoPlayers(roster, sub({ branch_ids: [10, 20] })), 12);
    assert.equal(autoPlayers(roster, sub({ branch_ids: [10] })), 8);
});

test('averagePrice: weighted by count, student and worker rates', () => {
    const s = sub({ amount_student: 1000, amount_worker: 2000, category_ids: [1] });
    assert.equal(averagePrice(roster, s), (5 * 1000 + 3 * 2000) / 8);
});

test('averagePrice: category override wins, a blank override falls back', () => {
    const s = sub({
        amount_student: 1000, amount_worker: 1000, category_ids: [1, 2],
        category_prices: { 1: { amount_student: 500, amount_worker: '' }, 2: { amount_student: null, amount_worker: null } },
    });
    assert.equal(averagePrice(roster, s), (5 * 500 + 3 * 1000 + 4 * 1000) / 12);
});

test('averagePrice: no matching players takes the mean of the default prices', () => {
    assert.equal(averagePrice([], sub({ amount_student: 1000, amount_worker: 2000 })), 1500);
});

const schedule = [
    { branch_id: 10, category_id: 1, slots: 3 },
    { branch_id: 10, category_id: 2, slots: 2 },
    { branch_id: 20, category_id: 1, slots: 5 },
    { branch_id: null, category_id: 3, slots: 4 },
];

test('defaultSessionsPerWeek: the highest slot count, never a sum', () => {
    assert.equal(defaultSessionsPerWeek(schedule, sub({ category_ids: [1, 2], branch_ids: [10] })), 3);
    assert.equal(defaultSessionsPerWeek(schedule, sub({ category_ids: [1], branch_ids: [10, 20] })), 5);
});

test('defaultSessionsPerWeek: a club-wide slot applies to any branch', () => {
    assert.equal(defaultSessionsPerWeek(schedule, sub({ category_ids: [3], branch_ids: [10] })), 4);
});

test('defaultSessionsPerWeek: no matching slots is 0', () => {
    assert.equal(defaultSessionsPerWeek([], sub()), 0);
    assert.equal(defaultSessionsPerWeek(schedule, sub({ category_ids: [99] })), 0);
});

test('forecast: blank inputs take the automatic values and the defaults', () => {
    const r = forecast({
        tarification: { sessions_per_week: null, weeks: '', expected_players: '', expected_payment_rate: '', lines: [line('per_session', 800)] },
        roster,
        schedule,
        subscription: sub({ category_ids: [1], branch_ids: [10] }),
    });
    assert.equal(r.autoPlayers, 8);
    assert.equal(r.players, 8);
    assert.equal(r.autoSessionsPerWeek, 3);
    assert.equal(r.sessionsPerWeek, 3);
    assert.equal(r.weeks, 40);
    assert.equal(r.sessions, 120);
    assert.equal(r.paymentRate, 100);
    assert.equal(r.costs, 96000);
});

test('forecast: typed values win, 0 is a real value', () => {
    const r = forecast({
        tarification: { sessions_per_week: 0, weeks: 30, expected_players: 25, expected_payment_rate: 0, lines: [line('per_session', 800)] },
        roster,
        schedule,
        subscription: sub({ category_ids: [1], branch_ids: [10] }),
    });
    assert.equal(r.players, 25);
    assert.equal(r.sessions, 0);
    assert.equal(r.paymentRate, 0);
    assert.equal(r.income, 0);
});

test('forecast: an exceptional subscription has no sessions', () => {
    const r = forecast({
        tarification: { sessions_per_week: 3, weeks: 40, expected_players: 10, expected_payment_rate: 100, lines: [line('per_player', 500)] },
        roster,
        schedule,
        subscription: sub({ kind: 'exceptional' }),
    });
    assert.equal(r.sessions, 0);
    assert.equal(r.costs, 5000);
});

const missing = (tarification, subscription = sub()) => forecast({
    tarification: { weeks: '', expected_players: '', expected_payment_rate: '', ...tarification },
    roster,
    schedule: [],
    subscription,
}).missingSessions;

test('forecast: missingSessions when per-session costs have no sessions', () => {
    assert.equal(missing({ sessions_per_week: '', lines: [line('per_session', 800), line('per_trip', 5000)] }), true);
});

test('forecast: not missing once the sessions per week are typed', () => {
    assert.equal(missing({ sessions_per_week: 3, lines: [line('per_session', 800), line('per_trip', 5000)] }), false);
});

test('forecast: not missing when the schedule supplies the default', () => {
    const r = forecast({
        tarification: { sessions_per_week: '', weeks: '', expected_players: '', expected_payment_rate: '', lines: [line('per_session', 800), line('per_trip', 5000)] },
        roster,
        schedule,
        subscription: sub({ category_ids: [1], branch_ids: [10] }),
    });
    assert.equal(r.missingSessions, false);
});

test('forecast: an exceptional subscription is never missing sessions', () => {
    assert.equal(missing({ sessions_per_week: '', lines: [line('per_session', 800)] }, sub({ kind: 'exceptional' })), false);
});

test('forecast: only a per-trip line is not missing sessions', () => {
    assert.equal(missing({ sessions_per_week: '', lines: [line('per_trip', 5000)] }), false);
});

test('forecast: a per-session line of 0 is not missing sessions', () => {
    assert.equal(missing({ sessions_per_week: '', lines: [line('per_session', 0)] }), false);
});

test('subscriptionInput: reads the shape the Show page receives', () => {
    const input = subscriptionInput({
        kind: 'annual', amount_student: '1000.00', amount_worker: '2000.00',
        categories: [{ id: 1, pivot: { amount_student: '500.00', amount_worker: null } }],
        branches: [{ id: 10 }],
    });
    assert.deepEqual(input.category_ids, [1]);
    assert.deepEqual(input.branch_ids, [10]);
    assert.deepEqual(input.category_prices, { 1: { amount_student: '500.00', amount_worker: null } });
});
