// The ages a document type applies to, as a label: "up to 17", "from 15",
// "15 to 17". null = every age. `t` is vue-i18n's t().
export function ageRangeLabel(t, type) {
    const min = type?.min_age ?? null;
    const max = type?.max_age ?? null;

    if (min && max) {
        return min === max ? t('doc_age_exact', { age: min }) : t('doc_age_range', { min, max });
    }
    if (min) return t('doc_from_age', { age: min });
    if (max) return t('doc_up_to_age', { age: max });

    return null;
}
