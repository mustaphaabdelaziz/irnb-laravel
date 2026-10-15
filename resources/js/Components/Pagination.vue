<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';

/**
 * Page links under a list: a Laravel paginator, or just its `links` array.
 * The first and last links are "previous" and "next". They show as arrows
 * pointing the way the page reads (mirrored in Arabic), with the server's
 * translated word (lang/<locale>/pagination.php) beside them from sm up.
 * Everything wraps instead of overflowing a phone.
 */
const props = defineProps({
    links: { type: [Object, Array], required: true },
});

const { t } = useI18n();

const all = computed(() => (Array.isArray(props.links) ? props.links : props.links?.links ?? []));
const meta = computed(() => (Array.isArray(props.links) ? null : props.links));
// Laravel always sends [previous, …pages, next]: three entries = one page.
const visible = computed(() => (meta.value ? meta.value.last_page > 1 : all.value.length > 3));
const previous = computed(() => all.value[0] ?? null);
const next = computed(() => all.value[all.value.length - 1] ?? null);
const pages = computed(() => all.value.slice(1, -1));
// "&laquo; Précédent" -> "Précédent": the arrow is drawn, the word is kept.
const word = (link, fallback) => String(link?.label ?? '').replace(/&laquo;|&raquo;|[«»]/g, '').trim() || fallback;

const base = 'inline-flex h-8 min-w-8 items-center justify-center gap-1 rounded-md px-2.5 text-sm font-medium transition-colors';
const idle = 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800';
const off = 'text-slate-300 dark:text-slate-600';
</script>

<template>
    <nav v-if="visible" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-slate-200 px-1 py-3 dark:border-slate-800">
        <p v-if="meta" class="text-sm text-slate-500 dark:text-slate-400">
            {{ t('showing') }} <span class="tabular-nums">{{ meta.from }}–{{ meta.to }}</span>
            {{ t('of') }} <span class="tabular-nums">{{ meta.total }}</span> {{ t('results') }}
        </p>
        <div class="flex flex-wrap items-center gap-1">
            <Link v-if="previous?.url" :href="previous.url" preserve-scroll :class="[base, idle]" :aria-label="word(previous, t('previous'))">
                <Icon name="back" class="rtl:rotate-180" />
                <span class="hidden sm:inline">{{ word(previous, t('previous')) }}</span>
            </Link>
            <span v-else :class="[base, off]" aria-hidden="true"><Icon name="back" class="rtl:rotate-180" /></span>

            <template v-for="(link, i) in pages" :key="`${i}-${link.label}`">
                <Link v-if="link.url" :href="link.url" preserve-scroll :aria-current="link.active ? 'page' : undefined"
                    :class="[base, 'tabular-nums', link.active ? 'bg-primary-600 text-white hover:bg-primary-600' : idle]">{{ link.label }}</Link>
                <span v-else :class="[base, off]">{{ link.label }}</span>
            </template>

            <Link v-if="next?.url" :href="next.url" preserve-scroll :class="[base, idle]" :aria-label="word(next, t('next'))">
                <span class="hidden sm:inline">{{ word(next, t('next')) }}</span>
                <Icon name="back" class="rotate-180 rtl:rotate-0" />
            </Link>
            <span v-else :class="[base, off]" aria-hidden="true"><Icon name="back" class="rotate-180 rtl:rotate-0" /></span>
        </div>
    </nav>
</template>
