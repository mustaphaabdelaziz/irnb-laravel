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

/** A first card carries over what a player with no registration already holds. */
export function newCard(branchId, player = {}, isFirst = false, defaultStatusId = '') {
    return {
        branch_id: branchId,
        membership_id: '',
        legacy_join_year: null,
        had_date: false,
        is_new: true,
        join_date: '',
        category_id: isFirst ? (player.category_id || '') : '',
        position_id: isFirst ? (player.position_id || '') : '',
        other_position_ids: [],
        licence_number: isFirst ? (player.licence_number || '') : '',
        status_id: isFirst ? (player.status_id || defaultStatusId) : defaultStatusId,
        left_at: '',
        sporting_status_id: '',
    };
}

/** What reaches the server. '' keeps an emptied list present: forceFormData drops empty arrays. */
export function cardPayload(card) {
    return {
        branch_id: card.branch_id,
        join_date: card.join_date || null,
        category_id: card.category_id || null,
        position_id: card.position_id || null,
        other_position_ids: card.other_position_ids?.length ? card.other_position_ids : '',
        licence_number: card.licence_number || null,
        status_id: card.status_id || null,
        left_at: card.is_left ? (card.left_at || null) : null,
        sporting_status_id: card.sporting_status_id || null,
    };
}
