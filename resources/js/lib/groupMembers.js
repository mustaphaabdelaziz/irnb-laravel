/**
 * What saving a group's tick list must send: the players ticked since the
 * dialog opened and the ones unticked. Only changes go to the server, so a
 * player nobody touched keeps their dates.
 */
export function memberChanges(before, ticked) {
    const was = new Set(before.map(Number));
    const now = new Set(ticked.map(Number));

    return {
        add: [...now].filter((id) => !was.has(id)),
        remove: [...was].filter((id) => !now.has(id)),
    };
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
