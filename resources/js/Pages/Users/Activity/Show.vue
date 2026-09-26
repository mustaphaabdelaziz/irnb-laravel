<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PeriodFilter from '@/Components/Activity/PeriodFilter.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';

const { t, locale } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    user: { type: Object, required: true },
    mine: { type: Boolean, default: false },
    period: { type: Object, required: true },
    summary: { type: Array, default: () => [] },
    areas: { type: Object, default: () => ({}) },
    action: { type: String, default: null },
    area: { type: String, default: null },
    entries: { type: Object, default: null },
});

const title = computed(() => (props.mine ? t('activity.my_title') : `${t('activity.title')} — ${props.user.name}`));

// My activity never carries a user id: the server always reads the signed-in user.
const baseUrl = computed(() => (props.mine ? route('profile.activity') : route('users.activity.show', props.user.id)));

function periodQuery() {
    return props.period.period === 'custom'
        ? { period: 'custom', from: props.period.from, to: props.period.to }
        : { period: props.period.period };
}

function withQuery(url, query) {
    const params = new URLSearchParams(Object.entries(query).filter(([, v]) => v !== null && v !== undefined && v !== ''));
    const qs = params.toString();
    return qs ? `${url}?${qs}` : url;
}

// Kept across period changes; the page number is dropped by the filter.
const keep = computed(() => Object.fromEntries(Object.entries({ action: props.action, area: props.area }).filter(([, v]) => v)));

function actionUrl(item) {
    return withQuery(baseUrl.value, { ...periodQuery(), area: item.area, action: item.action });
}

const comparisonUrl = computed(() => withQuery(route('users.activity.index'), periodQuery()));

// The summary grouped by area, in the areas' order, keeping only areas with activity.
const groups = computed(() => Object.keys(props.areas)
    .map((area) => ({ area, items: props.summary.filter((s) => s.area === area) }))
    .filter((g) => g.items.length > 0));

function formatDate(iso) {
    return new Date(iso).toLocaleString(locale.value, {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

// The entry's recorded context as short, localized chips. Only known keys are
// shown; `backfilled` gets its own marker.
function chips(entry) {
    const p = entry.properties || {};
    const out = [];
    if (p.amount !== undefined && p.amount !== null) out.push(formatMoney(p.amount));
    if (p.type === 'income' || p.type === 'expense') out.push(t(p.type));
    if (p.kind === 'annual' || p.kind === 'exceptional') out.push(t(`subscription_kind_${p.kind}`));
    // An import's count is already its subject label ("{count} rows").
    const hasSubject = entry.subject.url || entry.subject.deleted || entry.subject.neutral;
    if (p.count !== undefined && hasSubject) out.push(`${t('count')}: ${p.count}`);
    if (p.quantity !== undefined) out.push(`${t('quantity')}: ${p.quantity}`);
    if (p.found !== undefined) out.push(`${t('found')}: ${p.found}`);
    if (p.missing !== undefined) out.push(`${t('missing')}: ${p.missing}`);
    return out;
}

const cardClass = 'rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="truncate text-xl font-bold text-slate-900 dark:text-slate-100">{{ title }}</h1>
                <Link v-if="!mine" :href="comparisonUrl" class="shrink-0 text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">{{ t('activity.all_users') }}</Link>
            </div>
        </template>

        <div class="space-y-4">
            <PeriodFilter :period="period" :href="baseUrl" :keep="keep" />

            <!-- Summary, one card per area -->
            <div v-if="groups.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <section
                    v-for="group in groups"
                    :key="group.area"
                    :class="[cardClass, group.area === area ? '!ring-2 !ring-primary-500' : '']"
                    class="p-4"
                >
                    <h2 class="mb-2 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t(`activity.area.${group.area}`) }}</h2>
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        <li v-for="item in group.items" :key="item.action" class="flex items-start justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <p class="text-sm text-slate-700 dark:text-slate-200">{{ t(`activity.action.${item.action}`) }}</p>
                                <p v-if="item.amount !== null" class="text-xs text-slate-500 dark:text-slate-400">{{ formatMoney(item.amount) }}</p>
                                <p v-if="item.quality && item.quality.count > 0" class="text-xs text-amber-700 dark:text-amber-400">
                                    {{ item.quality.count }} {{ t(item.quality.kind === 'archived' ? 'activity.later_archived' : 'activity.later_cancelled') }}
                                </p>
                            </div>
                            <Link
                                :href="actionUrl(item)"
                                preserve-scroll
                                class="shrink-0 rounded-lg px-2.5 py-1 text-sm font-semibold tabular-nums transition-colors"
                                :class="item.action === action
                                    ? 'bg-primary-600 text-white'
                                    : 'bg-slate-100 text-slate-800 hover:bg-primary-50 hover:text-primary-700 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-primary-500/10 dark:hover:text-primary-300'"
                                :aria-current="item.action === action ? 'true' : undefined"
                            >{{ item.count }}</Link>
                        </li>
                    </ul>
                </section>
            </div>
            <div v-else :class="cardClass" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('activity.no_activity') }}</div>

            <!-- Entries of the chosen action -->
            <section v-if="entries" :class="cardClass" class="overflow-hidden">
                <h2 class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900 dark:border-slate-800 dark:text-slate-100">
                    {{ t(`activity.action.${action}`) }}
                    <span class="ms-1 font-normal text-slate-500 dark:text-slate-400">({{ entries.total }})</span>
                </h2>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="entry in entries.data" :key="entry.id" class="flex flex-col gap-1.5 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
                        <time :datetime="entry.occurred_at" class="shrink-0 text-xs text-slate-500 tabular-nums dark:text-slate-400 sm:w-40">{{ formatDate(entry.occurred_at) }}</time>
                        <div class="min-w-0 flex-1 text-sm">
                            <Link v-if="entry.subject.url" :href="entry.subject.url" class="break-words font-medium text-primary-700 hover:underline dark:text-primary-300">{{ entry.subject.label }}</Link>
                            <span v-else-if="entry.subject.deleted" class="italic text-slate-400 dark:text-slate-500">{{ entry.subject.label }}</span>
                            <span v-else class="text-slate-700 dark:text-slate-200">{{ entry.subject.label }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span v-for="(chip, i) in chips(entry)" :key="i" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ chip }}</span>
                            <span
                                v-if="entry.properties?.backfilled"
                                class="rounded-full border border-dashed border-slate-300 px-2 py-0.5 text-xs text-slate-400 dark:border-slate-700 dark:text-slate-500"
                                :title="t('activity.from_history_hint')"
                            >{{ t('activity.from_history') }}</span>
                        </div>
                    </li>
                    <li v-if="!entries.data.length" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('activity.no_activity') }}</li>
                </ul>
                <div class="px-4">
                    <Pagination :links="entries" />
                </div>
            </section>
            <p v-else-if="groups.length" class="text-sm text-slate-500 dark:text-slate-400">{{ t('activity.pick_action') }}</p>

            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('activity.no_author_note') }}</p>
        </div>
    </AuthenticatedLayout>
</template>
