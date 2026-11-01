// The modules behind each Reports category: the sidebar shows a category when
// the user can view any of them. Mirrors ReportRegistry::categoryModules();
// ReportCategoriesMirrorTest fails when the two drift apart.
export const REPORT_CATEGORY_MODULES = {
    "players": ["players", "documents"],
    "finance": ["finance", "subscriptions", "transactions", "reports"],
    "equipment": ["equipment", "inventory"],
    "competitions": ["games"],
    "attendance": ["attendance"]
};

export const REPORT_CATEGORY_ICONS = {
    players: 'players',
    finance: 'money',
    equipment: 'equipment',
    competitions: 'flag',
    attendance: 'calendar',
};
