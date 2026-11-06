/**
 * Which categories a picker offers. New choices: active ones of the given
 * branches, plus any with no owner. A value already stored stays listed until it
 * is changed, even if inactive or from another branch. The server checks too
 * (App\Support\CategoryOwnership).
 */
export function categoriesForBranches(categories, branchIds, stored = []) {
    const branches = new Set((branchIds ?? []).filter(Boolean).map(Number));
    const kept = new Set((stored ?? []).filter(Boolean).map(Number));

    return categories.filter((category) => {
        if (kept.has(Number(category.id))) return true;
        if (category.is_active === false) return false;
        return branches.size === 0 || category.branch_id == null || branches.has(Number(category.branch_id));
    });
}

export function categoriesForBranch(categories, branchId, stored = []) {
    return categoriesForBranches(categories, branchId ? [branchId] : [], stored);
}
