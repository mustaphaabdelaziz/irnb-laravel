<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import Icon from '@/Components/Icon.vue';
import { flattenForSearch, searchItems } from '@/lib/navigation';

const props = defineProps({
    open: { type: Boolean, default: false },
    // Already permission-filtered: the palette never lists a page the user can't open.
    sections: { type: Array, required: true },
});

const emit = defineEmits(['update:open']);
const { t } = useI18n();

const query = ref('');
const highlighted = ref(0);
const input = ref(null);
let returnFocusTo = null;
let prefetchTimer = null;

const entries = computed(() => flattenForSearch(props.sections));
const results = computed(() => searchItems(entries.value, query.value));

watch(query, () => { highlighted.value = 0; });

watch(() => props.open, async (isOpen) => {
    if (isOpen) {
        returnFocusTo = document.activeElement;
        query.value = '';
        highlighted.value = 0;
        await nextTick();
        input.value?.focus();
    } else {
        returnFocusTo?.focus?.();
        returnFocusTo = null;
    }
});

// Same idea as hover prefetch: load the page the user is about to pick.
// Debounced so arrowing through the list doesn't fire a request per row.
// Watches the href itself so the top result is prefetched too, while
// `highlighted` stays 0 as the query changes.
watch(() => (props.open ? results.value[highlighted.value]?.href : null), (href) => {
    clearTimeout(prefetchTimer);
    if (!href) return;
    prefetchTimer = setTimeout(() => {
        if (!props.open) return;
        router.prefetch(href, { method: 'get' }, { cacheFor: '30s' });
    }, 75);
});

function close() {
    emit('update:open', false);
}

function move(step) {
    const count = results.value.length;
    if (count === 0) return;
    highlighted.value = (highlighted.value + step + count) % count;
    nextTick(() => document.getElementById(`palette-option-${highlighted.value}`)?.scrollIntoView({ block: 'nearest' }));
}

function visit(item) {
    if (!item) return;
    close();
    router.visit(item.href);
}

// `code` keeps the shortcut working on an Arabic keyboard layout, where
// `key` for the K key is "ن".
function onGlobalKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.code === 'KeyK' || e.key?.toLowerCase() === 'k')) {
        e.preventDefault();
        emit('update:open', !props.open);
    }
}

onMounted(() => document.addEventListener('keydown', onGlobalKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', onGlobalKeydown);
    clearTimeout(prefetchTimer);
});
</script>

<template>
    <Modal :show="open" max-width="lg" @close="close">
        <div class="flex items-center gap-3 border-b border-slate-200 px-4 dark:border-slate-800">
            <Icon name="search" class="shrink-0 text-lg text-slate-400" />
            <input
                ref="input"
                v-model="query"
                type="text"
                role="combobox"
                aria-expanded="true"
                aria-controls="palette-results"
                aria-autocomplete="list"
                :aria-label="t('nav.search')"
                :aria-activedescendant="results.length ? `palette-option-${highlighted}` : undefined"
                :placeholder="t('nav.search_placeholder')"
                class="h-12 w-full border-0 bg-transparent px-0 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0 dark:text-slate-100"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="visit(results[highlighted])"
            />
        </div>

        <ul id="palette-results" role="listbox" class="max-h-80 overflow-y-auto p-2">
            <li
                v-for="(item, i) in results"
                :id="`palette-option-${i}`"
                :key="item.href"
                role="option"
                :aria-selected="i === highlighted"
                class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm"
                :class="i === highlighted ? 'bg-primary-50 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300' : 'text-slate-700 dark:text-slate-300'"
                @mousemove="highlighted = i"
                @click="visit(item)"
            >
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-[1.05rem] text-slate-500 dark:bg-slate-800 dark:text-slate-400"><Icon :name="item.icon" /></span>
                <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                <span v-if="item.section" class="max-w-[45%] shrink-0 truncate text-xs text-slate-400">{{ item.section }}</span>
            </li>
            <li v-if="results.length === 0" class="px-3 py-6 text-center text-sm text-slate-400">{{ t('nav.no_results') }}</li>
        </ul>
    </Modal>
</template>
