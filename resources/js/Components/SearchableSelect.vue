<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] }, // [{ value, label }]
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const queryText = ref('');
const active = ref(0);
const root = ref(null);

const selected = computed(() => props.options.find((o) => String(o.value) === String(props.modelValue)) || null);

const filtered = computed(() => {
    const q = queryText.value.trim().toLowerCase();
    if (!q) return props.options;
    return props.options.filter((o) => o.label.toLowerCase().includes(q));
});

function choose(option) {
    emit('update:modelValue', option ? option.value : '');
    open.value = false;
    queryText.value = '';
}

function onKey(e) {
    if (!open.value && (e.key === 'ArrowDown' || e.key === 'Enter')) { open.value = true; return; }
    if (e.key === 'ArrowDown') { active.value = Math.min(active.value + 1, filtered.value.length - 1); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { active.value = Math.max(active.value - 1, 0); e.preventDefault(); }
    else if (e.key === 'Enter') { if (filtered.value[active.value]) choose(filtered.value[active.value]); e.preventDefault(); }
    else if (e.key === 'Escape') { open.value = false; }
}

function onBlur(e) {
    if (root.value && !root.value.contains(e.relatedTarget)) open.value = false;
}
</script>

<template>
    <div ref="root" class="relative" @focusout="onBlur">
        <button type="button" :disabled="disabled" @click="open = !open" @keydown="onKey"
            class="mt-1 flex w-full items-center justify-between rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-start text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 disabled:opacity-50">
            <span :class="selected ? 'text-slate-900 dark:text-slate-100' : 'text-slate-400'">{{ selected ? selected.label : (placeholder || '-') }}</span>
            <span class="flex items-center gap-1">
                <span v-if="selected" class="text-slate-400 hover:text-rose-500" @click.stop="choose(null)">&times;</span>
                <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </span>
        </button>

        <div v-if="open" class="absolute z-20 mt-1 w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-lg">
            <input v-model="queryText" @keydown="onKey" autofocus
                class="w-full rounded-t-lg border-0 border-b border-slate-200 dark:border-slate-700 bg-transparent px-3 py-2 text-sm focus:ring-0" :placeholder="placeholder" />
            <ul class="max-h-56 overflow-y-auto py-1">
                <li v-for="(o, i) in filtered" :key="o.value" @mousedown.prevent="choose(o)"
                    class="cursor-pointer px-3 py-2 text-sm"
                    :class="i === active ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800'">
                    {{ o.label }}
                </li>
                <li v-if="!filtered.length" class="px-3 py-2 text-sm text-slate-400">-</li>
            </ul>
        </div>
    </div>
</template>
