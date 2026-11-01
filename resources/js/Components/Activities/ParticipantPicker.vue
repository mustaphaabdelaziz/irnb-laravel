<script setup>
import { computed, ref, watch } from 'vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import IconButton from '@/Components/IconButton.vue';
import { useI18n } from 'vue-i18n';

/** Several people from a long list: pick one at a time, chips below. */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const pick = ref('');
const chosen = computed(() => new Set(props.modelValue.map(Number)));
const remaining = computed(() => props.options.filter((o) => !chosen.value.has(Number(o.value))));
const selected = computed(() => props.options.filter((o) => chosen.value.has(Number(o.value))));

watch(pick, (value) => {
    if (value === '' || value === null) return;
    emit('update:modelValue', [...props.modelValue.map(Number), Number(value)]);
    pick.value = '';
});

function remove(value) {
    emit('update:modelValue', props.modelValue.map(Number).filter((v) => v !== Number(value)));
}
</script>

<template>
    <div class="space-y-2">
        <SearchableSelect v-model="pick" :options="remaining" :placeholder="placeholder" />
        <ul v-if="selected.length" class="flex flex-wrap gap-2">
            <li v-for="o in selected" :key="o.value" class="flex items-center gap-1 rounded-full bg-slate-100 py-0.5 ps-3 pe-1 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                {{ o.label }}
                <IconButton icon="close" :label="t('remove')" plain size="sm" icon-only @click="remove(o.value)" />
            </li>
        </ul>
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('activities.selected_count', { n: selected.length }) }}</p>
    </div>
</template>
