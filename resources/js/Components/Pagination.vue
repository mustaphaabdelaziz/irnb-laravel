<script setup>
import { useI18n } from 'vue-i18n';
import { Link } from '@inertiajs/vue3';

const { t } = useI18n();

const props = defineProps({
    links: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <nav v-if="links.last_page > 1" class="flex items-center justify-between border-t border-slate-200 px-1 py-3 dark:border-slate-800">
        <div class="hidden text-sm text-slate-500 dark:text-slate-400 sm:block">
            {{ t('showing') }} {{ links.from }}–{{ links.to }} {{ t('of') }} {{ links.total }} {{ t('results') }}
        </div>
        <div class="flex gap-1">
            <template v-for="link in links.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="link.active
                        ? 'bg-primary-600 text-white'
                        : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                    v-html="link.label"
                    preserve-scroll
                />
                <span
                    v-else
                    class="rounded-md px-3 py-1.5 text-sm text-slate-300 dark:text-slate-600"
                    v-html="link.label"
                />
            </template>
        </div>
    </nav>
</template>
