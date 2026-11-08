<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import { normalize } from '@/lib/navigation';
import { readJson } from '@/lib/safeStorage';

const props = defineProps({
    topics: { type: Array, required: true },
    startHere: { type: Array, required: true },
    fallback: { type: Boolean, default: false },
});

const { t } = useI18n();
const query = ref('');
const recent = readJson('help.recent', []);
const topicLabel = (slug) => t(`help.topic.${slug.replaceAll('-', '_')}`);

const all = computed(() => props.topics.flatMap((topic) => topic.articles.map((a) => ({
    ...a,
    haystack: normalize(`${a.title} ${a.summary} ${topicLabel(topic.slug)}`),
}))));
const results = computed(() => {
    const q = normalize(query.value);
    return q === '' ? [] : all.value.filter((a) => a.haystack.includes(q));
});
</script>

<template>
    <Head :title="t('help.center')" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('help.center') }}</h1>
        </template>

        <div class="mx-auto max-w-4xl">
            <p v-if="fallback" class="mb-4 rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('help.arabic_only') }}</p>

            <div class="mb-6 text-center">
                <p class="mb-4 text-2xl font-bold text-slate-900 dark:text-slate-100 sm:text-3xl">{{ t('help.welcome') }}</p>
                <label class="relative block">
                    <Icon name="search" class="pointer-events-none absolute start-4 top-1/2 -translate-y-1/2 text-xl text-slate-400" />
                    <input
                        v-model="query"
                        type="search"
                        data-help="help-search"
                        :placeholder="t('help.search_placeholder')"
                        class="w-full rounded-2xl border-2 border-primary-500 bg-white py-4 pe-4 ps-12 text-base shadow-sm focus:border-primary-600 focus:ring-primary-500 dark:border-primary-400 dark:bg-slate-900 dark:text-slate-100"
                    />
                </label>
            </div>

            <div v-if="query.trim() !== ''" class="mb-8 space-y-2">
                <Link v-for="a in results" :key="a.key" :href="a.href" class="block rounded-xl border border-slate-200 bg-white px-4 py-3 hover:border-primary-400 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-primary-500">
                    <p class="font-semibold text-slate-900 dark:text-slate-100">{{ a.title }}</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ a.summary }}</p>
                </Link>
                <p v-if="results.length === 0" class="py-6 text-center text-slate-500">{{ t('help.no_results') }}</p>
            </div>

            <template v-else>
                <section v-if="startHere.length" class="mb-8 rounded-2xl bg-gradient-to-br from-primary-600 to-primary-500 p-5 text-white shadow-glow sm:p-6">
                    <p class="text-xl font-bold"><span aria-hidden="true">🚀</span> {{ t('help.start_here') }}</p>
                    <p class="mb-4 text-sm text-white/90">{{ t('help.start_here_hint') }}</p>
                    <ol class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <li v-for="(a, i) in startHere" :key="a.key">
                            <Link :href="a.href" class="flex items-center gap-3 rounded-xl bg-white/15 px-3 py-2.5 font-semibold hover:bg-white/25">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white text-sm font-bold text-primary-700">{{ i + 1 }}</span>
                                <span>{{ a.title }}</span>
                            </Link>
                        </li>
                    </ol>
                </section>

                <section v-if="recent.length" class="mb-8">
                    <h2 class="mb-2 text-sm font-bold text-slate-500 dark:text-slate-400">{{ t('help.recent') }}</h2>
                    <div class="flex flex-wrap gap-2">
                        <Link v-for="r in recent" :key="r.key" :href="r.href" class="rounded-full border border-slate-200 bg-white px-3 py-1 text-sm text-slate-700 hover:border-primary-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">{{ r.title }}</Link>
                    </div>
                </section>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <section v-for="topic in topics" :key="topic.slug" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="mb-3 flex items-center gap-3 text-lg font-bold text-slate-900 dark:text-slate-100">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-50 text-xl text-primary-600 dark:bg-primary-500/15 dark:text-primary-300"><Icon :name="topic.icon" /></span>
                            {{ topicLabel(topic.slug) }}
                        </h2>
                        <ul class="space-y-1">
                            <li v-for="a in topic.articles" :key="a.key">
                                <Link :href="a.href" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-slate-700 hover:bg-slate-50 hover:text-primary-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-primary-300">
                                    <Icon name="document" class="shrink-0 text-slate-400" />
                                    <span>{{ a.title }}</span>
                                </Link>
                            </li>
                        </ul>
                    </section>
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
