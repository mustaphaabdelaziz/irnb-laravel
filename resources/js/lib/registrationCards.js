/**
 * A registration's card in the player form. Dates stay plain Y-m-d strings:
 * they never pass through new Date(), which could move them across midnight.
 */
function otherPositionsFor(branchId, otherPositions, isFirst) {
    return otherPositions
        .filter((position) => (position.branch_id === null || position.branch_id === undefined
            ? isFirst
            : Number(position.branch_id) === Number(branchId)))
        .map((position) => position.id);
}

export function cardFromRegistration(registration, otherPositions = [], isFirst = false) {
    return {
        id: registration.id,
        branch_id: registration.branch_id,
        membership_id: registration.membership_id || '',
        legacy_join_year: registration.legacy_join_year ?? null,
        had_date: !!registration.join_date,
        is_new: false,
        join_date: registration.join_date || '',
        category_id: registration.category_id || '',
        position_id: registration.position_id || '',
        other_position_ids: otherPositionsFor(registration.branch_id, otherPositions, isFirst),
        licence_number: registration.licence_number || '',
        status_id: registration.status_id || '',
        left_at: registration.left_at || '',
        sporting_status_id: registration.sporting_status_id || '',
    };
}

const hasNoBranch = (position) => position.branch_id === null || position.branch_id === undefined;
const fitsBranch = (position, branchId) => hasNoBranch(position) || Number(position.branch_id) === Number(branchId);

/**
 * A first card carries over what a player with no registration already holds:
 * the form sends every key, so the server's "take it from the player" path never runs.
 */
export function newCard(branchId, player = {}, isFirst = false, defaultStatusId = '', positions = []) {
    const mainPosition = isFirst && player.position_id
        ? positions.find((position) => Number(position.id) === Number(player.position_id))
        : null;
    return {
        branch_id: branchId,
        membership_id: '',
        legacy_join_year: null,
        had_date: false,
        is_new: true,
        join_date: '',
        category_id: isFirst ? (player.category_id || '') : '',
        position_id: mainPosition && fitsBranch(mainPosition, branchId) ? mainPosition.id : '',
        // Positions of this branch show on any card; those with no branch only on the first.
        other_position_ids: (player.other_positions || [])
            .filter((position) => (hasNoBranch(position) ? isFirst : fitsBranch(position, branchId)))
            .map((position) => position.id),
        licence_number: isFirst ? (player.licence_number || '') : '',
        status_id: isFirst ? (player.status_id || defaultStatusId) : defaultStatusId,
        left_at: isFirst ? (player.left_at || '') : '',
        sporting_status_id: '',
    };
}

/**
 * The cards left after one is deleted. Only the first card lists positions with
 * no branch, so deleting it hands them to the card that becomes first.
 */
export function withoutCard(cards, id, positions = []) {
    const index = cards.findIndex((card) => card.id === id);
    const remaining = cards.filter((card) => card.id !== id);
    if (index !== 0 || !remaining.length) return remaining;

    const noBranchIds = new Set(positions.filter(hasNoBranch).map((position) => Number(position.id)));
    const carried = (cards[0].other_position_ids || []).filter((positionId) => noBranchIds.has(Number(positionId)));
    const existing = remaining[0].other_position_ids || [];
    const extra = carried.filter((positionId) => !existing.some((value) => Number(value) === Number(positionId)));
    if (!extra.length) return remaining;

    return [{ ...remaining[0], other_position_ids: [...existing, ...extra] }, ...remaining.slice(1)];
}

/**
 * What reaches the server. '' keeps an emptied list present: forceFormData drops empty arrays.
 * Left-ness comes from the status alone, so a card never holds a flag of its own.
 */
export function cardPayload(card, leftStatusId = null) {
    const isLeft = leftStatusId !== null && leftStatusId !== undefined
        && Number(card.status_id) === Number(leftStatusId);
    return {
        branch_id: card.branch_id,
        join_date: card.join_date || null,
        category_id: card.category_id || null,
        position_id: card.position_id || null,
        other_position_ids: card.other_position_ids?.length ? card.other_position_ids : '',
        licence_number: card.licence_number || null,
        status_id: card.status_id || null,
        left_at: isLeft ? (card.left_at || null) : null,
        sporting_status_id: card.sporting_status_id || null,
    };
}
