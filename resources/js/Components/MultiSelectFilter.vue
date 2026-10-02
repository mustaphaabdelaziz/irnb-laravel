<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * A list filter that takes several values: a button summarising the choice
 * and a dropdown of checkboxes. Nothing checked = no filter.
 *
 * v-model is a list of strings (option values are compared as strings), kept
 * in option order so the query string stays stable. No dependency: the app
 * runs offline, and native checkboxes give keyboard + screen reader support.
 *
 * Long selections make long URLs (and the server refuses more than 500
 * values), so: with `collapseAll`, checking every option emits [] (= no
 * filter, the same rows), and at most `maxSelected` values can be checked.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    // [{ value, label, group? }] — consecutive options sharing `group` are
    // shown under that heading (e.g. "Missing document type").
    options: { type: Array, default: () => [] },
    // What the filter is about ("Category"); prefixes the "n selected" summary.
    label: { type: String, default: '' },
    // Shown when nothing is checked ("All categories").
    placeholder: { type: String, default: '' },
    // A search box appears once the list is longer than this.
    searchThreshold: { type: Number, default: 8 },
    // Every option checked means "no filter": emit []. Leave off where all
    // options together do NOT cover every row (e.g. document problems, or a
    // status filter whose empty value has its own default).
    collapseAll: { type: Boolean, default: false },
    // Upper bound on checked values (keeps the URL short; server cap is 500).
    maxSelected: { type: Number, default: 200 },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();
const id = useId();

const open = ref(false);
const query = ref('');
const root = ref(null);
const button = ref(null);
const panel = ref(null);
const searchBox = ref(null);
// Flip the panel to the other edge when it would leave the viewport.
const alignEnd = ref(false);
// Shown when a click would go past maxSelected.
const limitHit = ref(false);

const selected = computed(() => new Set((props.modelValue || []).map(String)));

const searchable = computed(() => props.options.length > props.searchThreshold);

const visible = computed(() => {
    const tokens = query.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (!tokens.length) return props.options;
    return props.options.filter((o) => {
        const haystack = `${o.label} ${o.group || ''}`.toLowerCase();
        return tokens.every((token) => haystack.includes(token));
    });
});

// Rows to render: a heading row before each run of options in a new group.
const rows = computed(() => {
    const out = [];
    let group;
    for (const option of visible.value) {
        if ((option.group || null) !== (group ?? null)) {
            group = option.group || null;
            if (group) out.push({ heading: group, key: `h:${group}` });
        }
        out.push({ option, key: `o:${option.value}` });
    }
    return out;
});

const summary = computed(() => {
    const count = selected.value.size;
    if (!count) return props.placeholder || props.label;
    if (count === 1) {
        const only = props.options.find((o) => selected.value.has(String(o.value)));
        if (only) return props.label ? `${props.label}: ${only.label}` : only.label;
    }
    const n = t('selected_count', { count });
    return props.label ? `${props.label}: ${n}` : n;
});

// Option order first; values with no option (stale ids from an old link)
// are kept at the end so a click elsewhere does not silently drop them.
// Returns false (and emits nothing) when the set is over the limit.
function emitSet(set) {
    const known = props.options.map((o) => String(o.value)).filter((v) => set.has(v));
    const unknown = [...set].filter((v) => !props.options.some((o) => String(o.value) === v));
    if (props.collapseAll && known.length === props.options.length && !unknown.length) {
        limitHit.value = false;
        emit('update:modelValue', []);
        return true;
    }
    if (known.length + unknown.length > props.maxSelected) {
        limitHit.value = true;
        return false;
    }
    limitHit.value = false;
    emit('update:modelValue', [...known, ...unknown]);
    return true;
}

function toggle(option, event) {
    const next = new Set(selected.value);
    const value = String(option.value);
    next.has(value) ? next.delete(value) : next.add(value);
    // Refused: put the native checkbox back to what the model says.
    if (!emitSet(next) && event?.target) event.target.checked = selected.value.has(value);
}

function selectAllVisible() {
    const next = new Set(selected.value);
    for (const o of visible.value) next.add(String(o.value));
    emitSet(next);
}

function clear() {
    limitHit.value = false;
    emit('update:modelValue', []);
}

function show() {
    if (props.disabled) return;
    alignEnd.value = false;
    open.value = true;
    nextTick(() => {
        fitPanel();
        (searchable.value ? searchBox.value : firstCheckbox())?.focus();
    });
}

function fitPanel() {
    const rect = panel.value?.getBoundingClientRect();
    if (!rect) return;
    const margin = 8;
    if (rect.right > window.innerWidth - margin || rect.left < margin) alignEnd.value = true;
}

function close(refocus = false) {
    open.value = false;
    query.value = '';
    limitHit.value = false;
    if (refocus) button.value?.focus();
}

function checkboxes() {
    return panel.value ? [...panel.value.querySelectorAll('input[type="checkbox"]')] : [];
}
function firstCheckbox() { return checkboxes()[0] || null; }

// Arrow keys walk the checkboxes (and up from the first one back to the search).
function onPanelKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(true); return; }
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    const boxes = checkboxes();
    const at = boxes.indexOf(document.activeElement);
    e.preventDefault();
    if (e.key === 'ArrowDown') (boxes[at + 1] || boxes[at] || boxes[0])?.focus();
    else if (at > 0) boxes[at - 1].focus();
    else if (searchable.value) searchBox.value?.focus();
}

function onButtonKey(e) {
    if (e.key === 'ArrowDown' && !open.value) { e.preventDefault(); show(); }
    else if (e.key === 'Escape' && open.value) { e.preventDefault(); close(true); }
}

// Click outside closes; so does tabbing out of the whole widget.
function onPointerDown(e) {
    if (root.value && !root.value.contains(e.target)) close();
}
function onFocusOut(e) {
    if (e.relatedTarget && root.value && !root.value.contains(e.relatedTarget)) close();
}

watch(open, (isOpen) => {
    if (isOpen) document.addEventListener('pointerdown', onPointerDown, true);
    else document.removeEventListener('pointerdown', onPointerDown, true);
});
onBeforeUnmount(() => document.removeEventListener('pointerdown', onPointerDown, true));
</script>

<template>
    <div ref="root" class="relative min-w-0" @focusout="onFocusOut">
        <button
            ref="button"
            type="button"
            :disabled="disabled"
            :aria-expanded="open"
            aria-haspopup="true"
            :aria-controls="`${id}-panel`"
            class="flex w-full items-center justify-between gap-2 rounded-lg border bg-white px-3 py-2 text-start text-sm shadow-sm transition-colors focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:opacity-50 dark:bg-slate-900"
            :class="selected.size
                ? 'border-primary-400 text-primary-800 dark:border-primary-600 dark:text-primary-200'
                : 'border-slate-300 text-slate-700 dark:border-slate-700 dark:text-slate-200'"
            @click="open ? close() : show()"
            @keydown="onButtonKey"
        >
            <span class="truncate">{{ summary }}</span>
            <span class="flex shrink-0 items-center gap-1">
                <span v-if="selected.size > 1" class="rounded-full bg-primary-600 px-1.5 text-xs font-bold text-white">{{ selected.size }}</span>
                <svg class="h-4 w-4 text-slate-400 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
            </span>
        </button>

        <div
            v-if="open"
            :id="`${id}-panel`"
            ref="panel"
            role="group"
            :aria-label="label || placeholder"
            class="absolute z-30 mt-1 w-max min-w-full max-w-[min(22rem,calc(100vw-2rem))] rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900"
            :class="alignEnd ? 'end-0' : 'start-0'"
            @keydown="onPanelKey"
        >
            <input
                v-if="searchable"
                ref="searchBox"
                v-model="query"
                type="search"
                :aria-label="t('search')"
                :placeholder="t('search')"
                class="w-full rounded-t-lg border-0 border-b border-slate-200 bg-transparent px-3 py-2 text-sm focus:ring-0 dark:border-slate-700"
            />

            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-3 py-1.5 text-xs dark:border-slate-800">
                <button type="button" class="font-semibold text-primary-700 hover:underline disabled:opacity-40 dark:text-primary-300"
                    :disabled="!visible.length" @click="selectAllVisible">{{ t('select_all') }}</button>
                <button type="button" class="font-semibold text-slate-500 hover:text-rose-600 hover:underline disabled:opacity-40 dark:text-slate-400"
                    :disabled="!selected.size" @click="clear">{{ t('filter.clear') }}</button>
            </div>

            <p v-if="limitHit" role="status" class="border-b border-amber-100 bg-amber-50 px-3 py-1.5 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
                {{ t('filter.too_many', { max: maxSelected }) }}
            </p>

            <ul class="max-h-64 overflow-y-auto py-1">
                <template v-for="row in rows" :key="row.key">
                    <li v-if="row.heading" class="px-3 pb-1 pt-2 text-[0.7rem] font-bold uppercase tracking-wide text-slate-400">{{ row.heading }}</li>
                    <li v-else>
                        <label class="flex cursor-pointer items-center gap-2.5 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800"
                            :class="{ 'ps-5': row.option.group }">
                            <input
                                type="checkbox"
                                class="h-4 w-4 shrink-0 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800"
                                :checked="selected.has(String(row.option.value))"
                                @change="toggle(row.option, $event)"
                            />
                            <span class="min-w-0 flex-1 truncate">{{ row.option.label }}</span>
                        </label>
                    </li>
                </template>
                <li v-if="!rows.length" class="px-3 py-2 text-sm text-slate-400">{{ t('no_results') }}</li>
            </ul>
        </div>
    </div>
</template>
