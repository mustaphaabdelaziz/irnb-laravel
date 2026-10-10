import { computed } from 'vue';

/**
 * What the distribution cards (StatDoughnut, StatBars) share: totals, shares,
 * and the click-to-filter selection behind their v-model.
 *
 * `props.stats` is [{ key, label, count, static? }] — key is what the filter
 * uses; a static item (e.g. "others") is shown but can't filter.
 * `props.modelValue` is the selected key ('' = no filter), or a list of keys
 * for a multi-select filter: a click then toggles that key in the list.
 *
 * @param {{ stats: Array, modelValue: string|number|Array }} props
 * @param {(event: 'update:modelValue', value: unknown) => void} emit
 */
export function useStatSelection(props, emit) {
    const total = computed(() => props.stats.reduce((s, c) => s + c.count, 0));
    const pct = (count) => (total.value ? Math.round((count / total.value) * 100) : 0);
    const multiple = computed(() => Array.isArray(props.modelValue));

    // In a list an empty key (e.g. "uncategorized") has no filter value to add.
    const clickable = (stat) => !stat.static
        && !(multiple.value && (stat.key === '' || stat.key === null || stat.key === undefined));
    const isActive = (stat) => clickable(stat) && (multiple.value
        ? props.modelValue.map(String).includes(String(stat.key))
        : String(props.modelValue) === String(stat.key));

    function toggle(stat) {
        if (!clickable(stat)) return;
        if (!multiple.value) {
            emit('update:modelValue', isActive(stat) ? '' : stat.key);
            return;
        }
        const key = String(stat.key);
        const list = props.modelValue.map(String);
        emit('update:modelValue', list.includes(key) ? list.filter((k) => k !== key) : [...list, key]);
    }

    const hasSelection = computed(() => props.stats.some((s) => isActive(s)));
    // The card keeps every item so the others stay clickable, but the headline
    // number follows the selection: it is what the list below is showing.
    const selectedTotal = computed(() => props.stats.reduce((s, c) => s + (isActive(c) ? c.count : 0), 0));
    const shownTotal = computed(() => (hasSelection.value ? selectedTotal.value : total.value));

    // The filter may hold values this card has no item for (a family outside
    // the top few): it is still filtered, so it can still be cleared.
    const filtered = computed(() => (multiple.value
        ? props.modelValue.length > 0
        : props.modelValue !== '' && props.modelValue !== null && props.modelValue !== undefined));

    function clear() {
        emit('update:modelValue', multiple.value ? [] : '');
    }

    return { total, pct, clickable, isActive, toggle, hasSelection, selectedTotal, shownTotal, filtered, clear };
}
