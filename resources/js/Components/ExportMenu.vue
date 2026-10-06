<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Dropdown from '@/Components/Dropdown.vue';
import IconButton from '@/Components/IconButton.vue';

// A download button that opens a menu with one link per file format. `href` is
// the export (or template) URL without `format`; each item adds its own. The
// first format is the server's default and is listed first.
const props = defineProps({
    href: { type: String, required: true },
    label: { type: String, required: true },
    formats: { type: Array, default: () => ['xlsx', 'csv'] },
    align: { type: String, default: 'right' },
    // Icon-only trigger; the label slides open on hover (see IconButton).
    icon: { type: String, default: 'download' },
    collapse: { type: Boolean, default: false }, // legacy, trigger is always compact now
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
            <IconButton :icon="icon" :label="label">
                <svg class="ms-0.5 h-3.5 w-3.5 opacity-60" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </IconButton>
        </template>
        <template #content>
            <div>
                <a v-for="item in items" :key="item.format" :href="item.url"
                   :target="item.format === 'pdf' ? '_blank' : null"
                   :download="item.format === 'pdf' ? null : ''"
                   class="block w-full px-4 py-2 text-start text-sm text-slate-700 hover:bg-slate-100 focus:bg-slate-100 focus:outline-none dark:text-slate-200 dark:hover:bg-slate-700 dark:focus:bg-slate-700">
                    {{ item.label }}
                </a>
            </div>
        </template>
    </Dropdown>
</template>
