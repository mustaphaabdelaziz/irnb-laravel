/**
 * Which training groups a picker offers: those of one branch + category
 * (a group belongs to both). No category, or a category without groups, offers
 * nothing: the field is then not shown. A null branch = the club-wide groups.
 * The server checks too.
 */
export function groupsFor(groups, branchId, categoryId) {
    if (!categoryId) return [];

    return (groups ?? []).filter((group) => Number(group.category_id) === Number(categoryId)
        && Number(group.branch_id ?? 0) === Number(branchId ?? 0));
}
