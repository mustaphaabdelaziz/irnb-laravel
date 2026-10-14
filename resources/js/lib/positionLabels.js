/**
 * Positions are per sport, so two can share a name ("Goalkeeper" in football
 * and in handball). Returns a labeller that appends " · Sport" only to those
 * shared names, leaving every other option as before.
 */
export function positionLabeller(positions, base = (p) => p.name) {
    const counts = new Map();
    for (const p of positions) counts.set(p.name, (counts.get(p.name) || 0) + 1);

    return (p) => {
        const sport = p.branch?.localized_name || p.branch?.name;
        return counts.get(p.name) > 1 && sport ? `${base(p)} · ${sport}` : base(p);
    };
}
