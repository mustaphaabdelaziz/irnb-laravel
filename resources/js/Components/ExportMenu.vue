<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Dropdown from '@/Components/Dropdown.vue';

// A download button that opens a menu with one link per file format. `href` is
// the export (or template) URL without `format`; each item adds its own. The
// first format is the server's default and is listed first.
const props = defineProps({
    href: { type: String, required: true },
    label: { type: String, required: true },
    formats: { type: Array, default: () => ['xlsx', 'csv'] },
    align: { type: String, default: 'right' },
    // Hide the label text below `sm` (icon + chevron only) in crowded headers.
    collapse: { type: Boolean, default: false },
});

const { t } = useI18n();

const items = computed(() => props.formats.map((format) => {
    const url = new URL(props.href, window.location.origin);
    url.searchParams.set('format', format);
    return { format, url: url.pathname + url.search, label: t(`export.format.${format}`) };
}));
</script>

<template>
    <Dropdown :align="align" width="48">
        <template #trigger>
            <button type="button" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800" aria-haspopup="menu" :title="label">
                <slot name="icon" />
                <span :class="collapse ? 'sr-only sm:not-sr-only' : null">{{ label }}</span>
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </button>
        </template>
        <template #content>
            <div role="menu">
                <a v-for="item in items" :key="item.format" :href="item.url" role="menuitem"
                   :target="item.format === 'pdf' ? '_blank' : null"
                   :download="item.format === 'pdf' ? null : ''"
                   class="block w-full px-4 py-2 text-start text-sm text-slate-700 hover:bg-slate-100 focus:bg-slate-100 focus:outline-none dark:text-slate-200 dark:hover:bg-slate-700 dark:focus:bg-slate-700">
                    {{ item.label }}
                </a>
            </div>
        </template>
    </Dropdown>
</template>
