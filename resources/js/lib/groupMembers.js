/** The players ticked since the dialog opened and the ones unticked. */
export function memberChanges(before, ticked) {
    const was = new Set(before.map(Number));
    const now = new Set(ticked.map(Number));

    return {
        add: [...now].filter((id) => !was.has(id)),
        remove: [...was].filter((id) => !now.has(id)),
    };
}

/**
 * What saving a group's tick list sends: every ticked player (not only the new
 * ones), so the "changes apply from" date also reaches players already in the
 * group: an earlier or empty date moves their start back, today's date leaves
 * them as they are (GroupMembership::add). `changed` = a tick moved.
 */
export function memberSave(before, ticked) {
    const { add, remove } = memberChanges(before, ticked);

    return { add: ticked.map(Number), remove, changed: add.length > 0 || remove.length > 0 };
}

/** The tick list after "select all": the shown players ticked or unticked, the others kept. */
export function tickAll(ticked, shownIds, on) {
    const current = ticked.map(Number);
    const shown = shownIds.map(Number);

    return on
        ? [...current, ...shown.filter((id) => !current.includes(id))]
        : current.filter((id) => !shown.includes(id));
}

/**
 * The groups gathered by branch + category (their `key`), in the order given,
 * each with the roster players who have no group yet.
 */
export function groupSections(groups, rosters) {
    const sections = new Map();

    for (const group of groups) {
        if (!sections.has(group.key)) {
            sections.set(group.key, {
                key: group.key,
                branch_id: group.branch_id,
                category_id: group.category_id,
                groups: [],
                waiting: (rosters[group.key] ?? []).filter((player) => player.group_id == null),
            });
        }
        sections.get(group.key).groups.push(group);
    }

    return [...sections.values()];
}
