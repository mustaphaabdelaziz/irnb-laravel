// The tarification forecast: does a subscription's price cover its costs?
// The only copy of these formulas. The server sends inputs and never computes
// a result, because the form has to react on each keystroke.

export const UNITS = ['per_session', 'per_trip', 'per_month', 'per_season', 'per_player'];
export const DEFAULT_WEEKS = 40;
export const DEFAULT_PAYMENT_RATE = 100;

const isBlank = (value) => value === null || value === undefined || value === '';

const number = (value) => {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
};

const orDefault = (value, fallback) => (isBlank(value) ? fallback : number(value));

const money = (value) => Math.round(value * 100) / 100;

// Rounds up to a whole number, without letting float noise add one.
const roundUp = (value) => Math.ceil(Number(value.toFixed(6)));

export function lineCost(line, { sessions, players }) {
    const base = number(line.amount) * number(line.quantity);

    if (line.unit === 'per_session') return base * sessions;
    if (line.unit === 'per_player') return base * players;

    return base;
}

const includesId = (ids, id) => ids.some((other) => Number(other) === Number(id));

// Same rule as Subscription::scopeOpenTo: no category or no branch means open.
export function matchingGroups(roster, subscription) {
    const categoryIds = subscription.category_ids ?? [];
    const branchIds = subscription.branch_ids ?? [];

    return roster.filter((group) => {
        const inCategory = !categoryIds.length || (group.category_id !== null && includesId(categoryIds, group.category_id));
        const inBranch = !branchIds.length || group.branch_ids.some((id) => includesId(branchIds, id));

        return inCategory && inBranch;
    });
}

export function autoPlayers(roster, subscription) {
    return matchingGroups(roster, subscription).reduce((sum, group) => sum + group.count, 0);
}

// Same result as Subscription::amountFor, from values that may still be unsaved.
function priceFor(group, subscription) {
    const column = group.is_student ? 'amount_student' : 'amount_worker';
    const override = subscription.category_prices?.[group.category_id]?.[column];

    return number(isBlank(override) ? subscription[column] : override);
}

export function averagePrice(roster, subscription) {
    const groups = matchingGroups(roster, subscription);
    const players = groups.reduce((sum, group) => sum + group.count, 0);

    if (!players) {
        return (number(subscription.amount_student) + number(subscription.amount_worker)) / 2;
    }

    return groups.reduce((sum, group) => sum + priceFor(group, subscription) * group.count, 0) / players;
}

// The highest slot count of one group, not a sum: each cost line is for one group.
export function defaultSessionsPerWeek(schedule, subscription) {
    const categoryIds = subscription.category_ids ?? [];
    const branchIds = subscription.branch_ids ?? [];

    return schedule
        .filter((row) => !categoryIds.length || includesId(categoryIds, row.category_id))
        .filter((row) => !branchIds.length || row.branch_id === null || includesId(branchIds, row.branch_id))
        .reduce((highest, row) => Math.max(highest, row.slots), 0);
}

export function calculate({ lines, sessions, players, paymentRate, price }) {
    const context = { sessions, players };
    const costed = lines.map((line) => ({ ...line, total: money(lineCost(line, context)) }));

    const isPerPlayer = (line) => line.unit === 'per_player';
    const fixed = lines.filter((line) => !isPerPlayer(line)).reduce((sum, line) => sum + lineCost(line, context), 0);
    const perPlayer = lines.filter(isPerPlayer).reduce((sum, line) => sum + number(line.amount) * number(line.quantity), 0);

    const costs = money(fixed + perPlayer * players);
    const income = money((players * price * paymentRate) / 100);
    const margin = money(income - costs);
    const hasCosts = fixed > 0 || perPlayer > 0;

    // In percent so 85% stays exact: 20 × 0.85 is not quite 17 in floats.
    const netPrice = (price * paymentRate) / 100;

    const breakEvenPrice = hasCosts && players > 0 && paymentRate > 0
        ? roundUp((costs * 100) / (players * paymentRate))
        : null;

    const breakEvenPlayers = hasCosts && netPrice > perPlayer
        ? roundUp(fixed / (netPrice - perPlayer))
        : null;

    let status = null;
    if (hasCosts) {
        status = margin > 0 ? 'win' : margin < 0 ? 'lose' : 'even';
    }

    return {
        sessions,
        lines: costed,
        income,
        costs,
        margin,
        marginPerPlayer: players > 0 ? money(margin / players) : null,
        breakEvenPrice,
        breakEvenPlayers,
        status,
        hasCosts,
        players,
        price,
        paymentRate,
    };
}

export function forecast({ tarification, roster, schedule, subscription }) {
    const exceptional = subscription.kind === 'exceptional';
    const auto = autoPlayers(roster, subscription);
    const autoSessionsPerWeek = defaultSessionsPerWeek(schedule, subscription);

    const players = orDefault(tarification.expected_players, auto);
    const sessionsPerWeek = orDefault(tarification.sessions_per_week, autoSessionsPerWeek);
    const weeks = orDefault(tarification.weeks, DEFAULT_WEEKS);

    const sessions = exceptional ? 0 : sessionsPerWeek * weeks;
    const lines = tarification.lines ?? [];
    const hasSessionCost = lines.some((line) => line.unit === 'per_session' && number(line.amount) > 0 && number(line.quantity) > 0);

    const result = calculate({
        lines,
        sessions,
        players,
        paymentRate: orDefault(tarification.expected_payment_rate, DEFAULT_PAYMENT_RATE),
        price: averagePrice(roster, subscription),
    });

    // Per-session costs count as 0 without sessions, so a verdict would be a false win.
    let prompt = null;
    if (hasSessionCost && exceptional) prompt = 'tarification.exceptional_sessions';
    else if (hasSessionCost && sessions === 0) prompt = 'tarification.no_sessions';
    else if (!result.hasCosts) prompt = 'tarification.empty';

    return {
        ...result,
        autoPlayers: auto,
        autoSessionsPerWeek,
        sessionsPerWeek,
        weeks,
        prompt,
    };
}

// The saved subscription, as the Show page receives it, in the form's shape.
export function subscriptionInput(subscription) {
    const categories = subscription.categories ?? [];

    return {
        kind: subscription.kind,
        amount_student: subscription.amount_student,
        amount_worker: subscription.amount_worker,
        category_ids: categories.map((category) => category.id),
        branch_ids: (subscription.branches ?? []).map((branch) => branch.id),
        category_prices: Object.fromEntries(categories.map((category) => [category.id, {
            amount_student: category.pivot?.amount_student ?? null,
            amount_worker: category.pivot?.amount_worker ?? null,
        }])),
    };
}
