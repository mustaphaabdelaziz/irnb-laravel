import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const isEmpty = (v) => v === '' || v === null || v === undefined || v === false;

/**
 * Server-side list filtering without a full page visit.
 *
 * Each filter change reloads only the props that depend on the filters, keeps
 * the scroll position and component state (open modals, focused search box),
 * replaces the history entry instead of stacking one per keystroke, and swaps
 * the global progress bar for a local `loading` flag. Filters stay in the
 * query string so a filtered view survives a refresh and can be bookmarked
 * or shared.
 *
 * Inertia interrupts a still-running visit when a new one starts, so a slow
 * response can never overwrite the results of a newer query.
 *
 * @param {string} routeName  the index route to reload
 * @param {() => Record<string, unknown>} getParams  current filter values; empty ones are dropped
 * @param {{ only?: string[] }} [options]  props that change with the filters
 */
export function useListFilters(routeName, getParams, { only = [] } = {}) {
    const loading = ref(false);

    const params = computed(() => Object.fromEntries(
        Object.entries(getParams()).filter(([, v]) => !isEmpty(v)),
    ));

    function apply() {
        router.get(route(routeName), params.value, {
            only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            showProgress: false,
            onStart: () => { loading.value = true; },
            onFinish: () => { loading.value = false; },
        });
    }

    // `params` is a fresh object whenever any source ref changes; compare the
    // serialized query so e.g. '' -> undefined doesn't fire a request.
    watch(() => JSON.stringify(params.value), apply);

    return { params, loading };
}
