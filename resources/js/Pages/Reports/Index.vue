<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { pinnedReports, recentReports, togglePinned } from '@/lib/reportPrefs';

// The Reports hub (category = null) and one category's page share this view:
// category tiles, the viewer's pinned and recent reports, then every card the
// server says this user may open — new reports and links to existing ones.
const { t } = useI18n();
const props = defineProps({
    category: { type: String, default: null },
    categories: { type: Array, default: () => [] },
    cards: { type: Array, default: () => [] },
});

const pinned = ref(pinnedReports());
const recent = ref(recentReports());

const byId = computed(() => Object.fromEntries(props.cards.map((c) => [c.id, c])));
const pick = (ids) => ids.map((id) => byId.value[id]).filter(Boolean);
const shortcuts = computed(() => (props.category ? [] : [
    { key: 'pinned', cards: pick(pinned.value) },
    { key: 'recent', cards: pick(recent.value).filter((c) => !pinned.value.includes(c.id)) },
].filter((block) => block.cards.length)));

const groups = computed(() => props.categories
    .filter((c) => !props.category || c.key === props.category)
    .map((c) => ({ ...c, cards: props.cards.filter((card) => card.category === c.key) }))
    .filter((g) => g.cards.length));

const title = computed(() => (props.category ? t(`reports.category.${props.category}.title`) : t('reports.title')));

function pin(card) {
    pinned.value = togglePinned(card.id);
}
</script>

<template>
    <Head :title="title" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-2">
                <template v-if="category">
                    <Link :href="route('reports.hub')" class="text-sm text-slate-500 hover:text-emerald-600">{{ t('reports.title') }}</Link>
                    <span class="text-slate-300">/</span>
                </template>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ title }}</h1>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-8">
            <p v-if="category" class="text-sm text-slate-500">{{ t(`reports.category.${category}.description`) }}</p>

            <!-- Category tiles (hub only) -->
            <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <Link v-for="c in categories" :key="c.key" :href="c.href"
                      class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-emerald-400 dark:bg-slate-900 dark:ring-slate-800">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                            <Icon :name="c.icon" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-900 dark:text-slate-100">{{ t(`reports.category.${c.key}.title`) }}</div>
                            <div class="text-xs text-slate-500">{{ t('reports.count', { n: c.count }) }}</div>
                        </div>
                    </div>
                    <p class="mt-2 line-clamp-2 text-xs text-slate-500">{{ t(`reports.category.${c.key}.description`) }}</p>
                </Link>
            </div>

            <!-- This viewer's pinned and recently opened reports -->
            <section v-for="block in shortcuts" :key="block.key">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t(`reports.${block.key}`) }}</h2>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <component :is="card.external ? 'a' : Link" v-for="card in block.cards" :key="card.id"
                               :href="card.href" :target="card.external ? '_blank' : undefined"
                               class="flex items-center gap-3 rounded-xl bg-white px-4 py-3 text-sm shadow-sm ring-1 ring-slate-200 hover:ring-emerald-400 dark:bg-slate-900 dark:ring-slate-800">
                        <Icon :name="card.icon" class="h-4 w-4 shrink-0 text-emerald-600" />
                        <span class="truncate font-medium">{{ t(card.title) }}</span>
                    </component>
                </div>
            </section>

            <!-- Every report the user may open, by category -->
            <section v-for="g in groups" :key="g.key">
                <h2 v-if="!category" class="mb-3 flex items-center gap-2 text-base font-semibold text-slate-800 dark:text-slate-200">
                    <Icon :name="g.icon" class="h-4 w-4 text-emerald-600" />
                    <Link :href="g.href" class="hover:text-emerald-600">{{ t(`reports.category.${g.key}.title`) }}</Link>
                </h2>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="card in g.cards" :key="card.id"
                         class="relative flex flex-col rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-emerald-400 dark:bg-slate-900 dark:ring-slate-800">
                        <component :is="card.external ? 'a' : Link" :href="card.href" :target="card.external ? '_blank' : undefined" class="flex-1">
                            <div class="flex items-start gap-3 pe-8">
                                <Icon :name="card.icon" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                                <div>
                                    <div class="font-semibold text-slate-900 dark:text-slate-100">{{ t(card.title) }}</div>
                                    <p class="mt-1 text-xs text-slate-500">{{ t(card.description) }}</p>
                                </div>
                            </div>
                            <span v-if="card.kind === 'link'" class="mt-3 inline-block rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-slate-800">
                                {{ card.external ? t('reports.opens_pdf') : t('reports.on_its_page') }}
                            </span>
                        </component>
                        <button type="button" class="absolute end-3 top-3 rounded-lg p-1 hover:text-amber-500"
                                :class="pinned.includes(card.id) ? 'text-amber-500' : 'text-slate-300'"
                                :aria-pressed="pinned.includes(card.id)"
                                :aria-label="pinned.includes(card.id) ? t('reports.unpin') : t('reports.pin')"
                                :title="pinned.includes(card.id) ? t('reports.unpin') : t('reports.pin')"
                                @click="pin(card)">
                            <Icon name="star" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </section>

            <p v-if="!groups.length" class="py-12 text-center text-slate-500">{{ t('no_results') }}</p>
        </div>
    </AuthenticatedLayout>
</template>
