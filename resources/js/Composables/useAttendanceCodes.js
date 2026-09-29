import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

/** The six statuses in their fixed order (App\Enums\AttendanceStatus). */
export const STATUSES = ['present', 'late', 'left_early', 'not_training', 'absent_excused', 'absent_unexcused'];
const WITH_MINUTES = ['late', 'left_early'];
const FALLBACK_COLOR = '#64748b';

/**
 * The configured status codes, names and colours (attendance settings), read
 * from the `attendanceCodes` prop every attendance page receives. A name left
 * empty falls back to the built-in translation.
 */
export function useAttendanceCodes() {
    const page = usePage();
    const { t, locale } = useI18n();
    const codes = computed(() => page.props.attendanceCodes ?? {});

    const code = (status) => codes.value[status]?.code ?? '';
    const label = (status) => codes.value[status]?.label?.[locale.value] || t(`att.status.${status}`);
    const color = (status) => codes.value[status]?.color || FALLBACK_COLOR;
    /** Solid chip: the configured colour behind white text. */
    const chipStyle = (status) => ({ backgroundColor: color(status), color: '#fff' });
    /** A light wash of the colour (hex alpha), for grid cells. */
    const tint = (status, alpha = '26') => ({ backgroundColor: `${color(status)}${alpha}` });

    /** Mirrors AttendanceCode::parse() to colour a typed code; the server still validates. */
    function statusOf(value) {
        const v = String(value ?? '').replace(/\s+/gu, '').toUpperCase();
        if (v === '') return null;
        const simple = STATUSES.find((s) => !WITH_MINUTES.includes(s) && code(s) === v);
        if (simple) return simple;
        const m = v.match(/^(\p{L}+)([0-9]{1,3})$/u);
        return m ? WITH_MINUTES.find((s) => code(s) === m[1]) ?? null : null;
    }

    /** "18 Present · 2 Late" for a held session's { status: count } summary. */
    const summaryText = (summary) => STATUSES.filter((s) => summary?.[s]).map((s) => `${summary[s]} ${label(s)}`).join(' · ');

    return { statuses: STATUSES, codes, code, label, color, chipStyle, tint, statusOf, summaryText };
}
