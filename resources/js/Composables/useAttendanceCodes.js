import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

/** The six built-in statuses in their fixed order (App\Enums\AttendanceStatus); custom codes follow them. */
export const STATUSES = ['present', 'late', 'left_early', 'not_training', 'absent_excused', 'absent_unexcused'];
const WITH_MINUTES = ['late', 'left_early'];
const WITH_REASON = ['absent_excused', 'not_training'];
const FALLBACK_COLOR = '#64748b';
const SLATE_900 = '#0f172a';

/** Arabic-Indic and Persian digits, normalised to Latin 0-9 (mirrors AttendanceCode::DIGITS). */
const DIGITS = {
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
};
const normaliseDigits = (v) => v.replace(/[٠-٩۰-۹]/g, (d) => DIGITS[d]);

/** WCAG relative luminance of a #rrggbb colour (0 = black, 1 = white). */
function luminance(hex) {
    const [r, g, b] = [hex.slice(1, 3), hex.slice(3, 5), hex.slice(5, 7)]
        .map((h) => parseInt(h, 16) / 255)
        .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
const contrast = (l1, l2) => (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);

/** The readable text colour (white or slate-900) for text on a #rrggbb background, by WCAG contrast. */
export function textOn(hex) {
    const bg = luminance(hex);

    return contrast(bg, 1) >= contrast(bg, luminance(SLATE_900)) ? '#ffffff' : SLATE_900;
}

/**
 * The configured status codes, names and colours (AttendanceStatusCatalog:
 * the six built-in statuses, then the owner's custom codes), read from the
 * `attendanceCodes` prop every attendance page receives, in that order. A
 * built-in name left empty falls back to the built-in translation; a custom
 * one arrives already resolved.
 *
 * `statuses` lists every status (hidden custom codes included, so old marks
 * still show); `activeStatuses` only those that can be picked.
 */
export function useAttendanceCodes() {
    const page = usePage();
    const { t, locale } = useI18n();
    const codes = computed(() => page.props.attendanceCodes ?? {});

    const statuses = computed(() => (Object.keys(codes.value).length ? Object.keys(codes.value) : STATUSES));
    const activeStatuses = computed(() => statuses.value.filter((s) => codes.value[s]?.active !== false));
    const code = (status) => codes.value[status]?.code ?? '';
    const label = (status) => codes.value[status]?.label?.[locale.value]
        || (codes.value[status]?.custom || !STATUSES.includes(status) ? code(status) || status : t(`att.status.${status}`));
    const color = (status) => codes.value[status]?.color || FALLBACK_COLOR;
    /** Solid chip: the configured colour behind whichever text colour reads best on it. */
    const chipStyle = (status) => ({ backgroundColor: color(status), color: textOn(color(status)) });
    /** A light wash of the colour (hex alpha), for grid cells. */
    const tint = (status, alpha = '26') => ({ backgroundColor: `${color(status)}${alpha}` });
    /** late / left_early take a minutes value; mirrors AttendanceStatus::takesMinutes(). Custom codes never do. */
    const takesMinutes = (status) => WITH_MINUTES.includes(status);
    /** absent_excused / not_training take a reason; mirrors AttendanceStatus::takesReason(). */
    const takesReason = (status) => WITH_REASON.includes(status);

    /** Mirrors AttendanceCode::parse() to colour a typed code; the server still validates. */
    function statusOf(value) {
        const v = normaliseDigits(String(value ?? '').replace(/\s+/gu, '')).toUpperCase();
        if (v === '') return null;
        const simple = statuses.value.find((s) => !WITH_MINUTES.includes(s) && code(s) !== '' && code(s).toUpperCase() === v);
        if (simple) return simple;
        const m = v.match(/^(\p{L}+)([0-9]{1,3})$/u);
        return m ? WITH_MINUTES.find((s) => code(s) === m[1]) ?? null : null;
    }

    /** "18 Present · 2 Late" for a held session's { status: count } summary. */
    const summaryText = (summary) => statuses.value.filter((s) => summary?.[s]).map((s) => `${summary[s]} ${label(s)}`).join(' · ');

    return { statuses, activeStatuses, codes, code, label, color, chipStyle, tint, takesMinutes, takesReason, statusOf, summaryText };
}
