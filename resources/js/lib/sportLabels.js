/**
 * Positions and categories are per sport, so two can share a name ("Goalkeeper"
 * in football and in handball, "Senior" in football and in swimming). Returns a
 * labeller that appends " · Sport" only to those shared names, leaving every
 * other option as before.
 */
export function sportLabeller(items, base = (item) => item.name) {
    const counts = new Map();
    for (const item of items) counts.set(item.name, (counts.get(item.name) || 0) + 1);

    return (item) => {
        const sport = item.branch?.localized_name || item.branch?.name;
        return counts.get(item.name) > 1 && sport ? `${base(item)} · ${sport}` : base(item);
    };
}
