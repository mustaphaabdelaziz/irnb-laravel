<script setup>
import MultiSelectFilter from '@/Components/MultiSelectFilter.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { useI18n } from 'vue-i18n';
import { reactive, watch } from 'vue';

// The filter form of a report, drawn from the definitions the server sends
// (select, multi, date, number). Nothing applies until Generate: the parent
// then reloads the report with these values in the query string, where the
// server validates them again.
const { t } = useI18n();
const props = defineProps({
    defs: { type: Array, required: true },
    values: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['generate', 'reset']);

const draft = reactive({});
function load() {
    for (const def of props.defs) {
        const v = props.values[def.key];
        draft[def.key] = def.type === 'multi' ? [...(v ?? [])] : (v ?? '');
    }
}
load();
watch(() => props.values, load);

function generate() {
    // Empty controls are left out, so the server applies its own defaults.
    const query = {};
    for (const def of props.defs) {
        const v = draft[def.key];
        if (Array.isArray(v) ? v.length : v !== '' && v !== null) query[def.key] = v;
    }
    emit('generate', query);
}
</script>

<template>
    <form class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 print:hidden dark:bg-slate-900 dark:ring-slate-800" @submit.prevent="generate">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label v-for="def in defs" :key="def.key" class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                <span class="mb-1 block">{{ t(def.label) }}</span>
                <MultiSelectFilter v-if="def.type === 'multi'" v-model="draft[def.key]" :options="def.options" :label="t(def.label)" :placeholder="t('reports.all')" />
                <SearchableSelect v-else-if="def.type === 'select'" v-model="draft[def.key]" :options="def.options" :placeholder="t('reports.all')" />
                <input v-else-if="def.type === 'date'" v-model="draft[def.key]" type="date"
                       class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950">
                <input v-else v-model="draft[def.key]" type="number" :min="def.min" :max="def.max" inputmode="numeric"
                       class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950">
                <span v-if="errors[def.key]" class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ errors[def.key] }}</span>
            </label>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">{{ t('reports.generate') }}</button>
            <button type="button" class="rounded-lg px-4 py-2 text-sm text-slate-600 ring-1 ring-slate-300 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800" @click="emit('reset')">{{ t('reports.reset') }}</button>
        </div>
    </form>
</template>
