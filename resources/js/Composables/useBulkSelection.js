import { computed, ref, watch } from 'vue';

/**
 * Row selection for an index page.
 *
 * Extracted from Players/Index.vue so any list can opt in — there is no
 * shared table component in this app, and rewriting 55 hand-rolled tables
 * to introduce one is not worth it.
 *
 * @param {import('vue').Ref<Array<{id: number}>>} rows  the rows currently on screen
 * @param {import('vue').Ref<*>} [resetSignal]  anything that, when it changes,
 *        means the rows have been replaced (a filter object, a page number)
 */
export function useBulkSelection(rows, resetSignal = null) {
    const selected = ref([]);

    const pageIds = computed(() => (rows.value ?? []).map((r) => r.id));

    const allSelected = computed(
        () => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)),
    );

    const count = computed(() => selected.value.length);
    const hasSelection = computed(() => selected.value.length > 0);

    function toggleAll() {
        selected.value = allSelected.value ? [] : [...pageIds.value];
    }

    function toggleOne(id) {
        const at = selected.value.indexOf(id);
        if (at === -1) selected.value.push(id);
        else selected.value.splice(at, 1);
    }

    function clear() {
        selected.value = [];
    }

    // A filter or page change replaces the rows. Keeping stale ids would let a
    // bulk action hit records the user can no longer see — so the selection is
    // dropped rather than silently carried over.
    if (resetSignal) {
        watch(resetSignal, clear, { deep: true });
    }

    return { selected, pageIds, allSelected, count, hasSelection, toggleAll, toggleOne, clear };
}
