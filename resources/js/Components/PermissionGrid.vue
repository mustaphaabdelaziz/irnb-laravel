<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * The module × action checkbox grid, grouped by sidebar section so it reads
 * like the menu (each App Configuration list is its own row).
 * v-model: { module: ['view', 'add', …] }.
 */
const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    // Role::MODULE_GROUPS: { section: [module, …] }
    groups: { type: Object, default: () => ({}) },
    actions: { type: Array, default: () => [] },
    // Role::EXTRA_ACTIONS: { module: ['approve'] } — rights only some modules have.
    extraActions: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();

const hasExtras = computed(() => Object.keys(props.extraActions).length > 0);
const columnCount = computed(() => props.actions.length + 1 + (hasExtras.value ? 1 : 0));

// Section keys → the sidebar's own labels.
const SECTION_LABELS = {
    members: 'nav_members',
    competitions: 'nav.competitions',
    finance: 'nav_finance',
    equipment: 'nav_equipment',
    board: 'nav.board_of_directors',
    social: 'nav.social',
    config: 'nav.config',
    system: 'nav.system',
};

function has(module, action) {
    return (props.modelValue[module] ?? []).includes(action);
}

function toggle(module, action) {
    const next = { ...props.modelValue };
    const list = new Set(next[module] ?? []);
    list.has(action) ? list.delete(action) : list.add(action);
    if (list.size) next[module] = [...list];
    else delete next[module];
    emit('update:modelValue', next);
}
</script>

<template>
    <div class="overflow-x-auto rounded-lg ring-1 ring-slate-200 dark:ring-slate-800">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-start text-slate-500 dark:text-slate-400">
                    <th class="p-2 text-start">{{ t('module') }}</th>
                    <th v-for="a in actions" :key="a" class="p-2 text-center">{{ t(a) }}</th>
                    <th v-if="hasExtras" class="p-2 text-center">{{ t('perm_other') }}</th>
                </tr>
            </thead>
            <tbody v-for="(modules, section) in groups" :key="section">
                <tr class="bg-slate-50 dark:bg-slate-800/60">
                    <th :colspan="columnCount" class="px-2 py-1.5 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        {{ t(SECTION_LABELS[section] ?? section) }}
                    </th>
                </tr>
                <tr v-for="m in modules" :key="m" :data-help="'perm-row-' + m" class="border-t border-slate-100 dark:border-slate-800">
                    <td class="p-2 ps-4 font-medium">{{ t(m) }}</td>
                    <td v-for="a in actions" :key="a" class="p-2 text-center">
                        <input type="checkbox" :checked="has(m, a)" :aria-label="`${t(m)} — ${t(a)}`" @change="toggle(m, a)"
                            class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                    </td>
                    <td v-if="hasExtras" class="p-2 text-center">
                        <label v-for="a in (extraActions[m] ?? [])" :key="a" class="inline-flex items-center gap-1 text-xs">
                            <input type="checkbox" :checked="has(m, a)" :aria-label="`${t(m)} — ${t('perm_' + a)}`" @change="toggle(m, a)"
                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                            {{ t('perm_' + a) }}
                        </label>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
