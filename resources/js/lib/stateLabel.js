// Translate an equipment enum value (status/condition) via its lowercased,
// underscored i18n key ("Under Repair" → under_repair), falling back to the raw value.
export function stateLabel(t, value) {
    if (!value) return '—';
    const key = String(value).toLowerCase().replaceAll(' ', '_');
    const translated = t(key);
    return translated === key ? value : translated;
}
