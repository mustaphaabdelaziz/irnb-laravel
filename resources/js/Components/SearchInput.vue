<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    debounce: { type: Number, default: 300 },
});

const emit = defineEmits(['update:modelValue']);

const query = ref(props.modelValue);
let timer = null;

watch(query, (val) => {
    clearTimeout(timer);
    timer = setTimeout(() => emit('update:modelValue', val), props.debounce);
});

watch(() => props.modelValue, (val) => {
    if (val !== query.value) query.value = val;
});
</script>

<template>
    <div class="relative">
        <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
            <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
        </div>
        <input
            v-model="query"
            type="search"
            :placeholder="placeholder"
            class="w-full rounded-lg border-slate-300 py-2 ps-10 pe-4 text-sm shadow-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500"
        />
    </div>
</template>
