<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    debounce: { type: Number, default: 300 },
    // Swaps the search icon for a spinner while results load.
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const input = ref(null);
const query = ref(props.modelValue ?? '');
let timer = null;

// Surrounding whitespace never changes the results, so the emitted value is
// trimmed — typing the space between two words doesn't fire a request.
function commit() {
    clearTimeout(timer);
    timer = null;
    const val = query.value.trim();
    if (val !== (props.modelValue ?? '')) emit('update:modelValue', val);
}

watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(commit, props.debounce);
});

// Follow external resets without clobbering what is being typed: the parent
// only ever holds the trimmed value.
watch(() => props.modelValue, (val) => {
    if ((val ?? '') !== query.value.trim()) query.value = val ?? '';
});

function clear() {
    query.value = '';
    commit();
    input.value?.focus();
}

function onEscape(e) {
    if (!query.value) return;
    e.preventDefault();
    clear();
}

// A pending debounce must not fire after the page is gone — it would
// navigate straight back to the list the user just left.
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="relative">
        <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-slate-400">
            <svg v-if="loading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z" />
            </svg>
            <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
        </div>
        <input
            ref="input"
            v-model="query"
            type="search"
            :placeholder="placeholder"
            :aria-label="placeholder || t('search')"
            :aria-busy="loading"
            autocomplete="off"
            spellcheck="false"
            enterkeyhint="search"
            @keydown.enter.prevent="commit"
            @keydown.esc="onEscape"
            class="w-full rounded-lg border-slate-300 py-2 ps-10 pe-9 text-sm shadow-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 [&::-webkit-search-cancel-button]:hidden"
        />
        <button
            v-if="query"
            type="button"
            @click="clear"
            :aria-label="t('clear_search')"
            :title="t('clear_search')"
            class="absolute inset-y-0 end-0 flex items-center rounded-e-lg px-2.5 text-base text-slate-400 transition-colors hover:text-slate-600 focus:outline-none focus-visible:text-primary-600 dark:hover:text-slate-200"
        >
            <Icon name="xcircle" />
        </button>
    </div>
</template>
