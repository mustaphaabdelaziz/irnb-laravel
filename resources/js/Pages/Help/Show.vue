<script setup>
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import HelpArticleBody from '@/Components/Help/HelpArticleBody.vue';
import { readJson, writeJson } from '@/lib/safeStorage';

const props = defineProps({
    article: { type: Object, required: true },
    related: { type: Array, required: true },
    topic: { type: Object, required: true },
    fallback: { type: Boolean, default: false },
});

const { t } = useI18n();
const feedback = ref(readJson('help.feedback', {})[props.article.key] ?? null);

// `window` isn't reachable from the template.
function printPage() {
    window.print();
}

onMounted(() => {
    const entry = { key: props.article.key, title: props.article.title, href: props.article.href };
    writeJson('help.recent', [entry, ...readJson('help.recent', []).filter((r) => r.key !== entry.key)].slice(0, 5));
});

function rate(value) {
    feedback.value = value;
    writeJson('help.feedback', { ...readJson('help.feedback', {}), [props.article.key]: value });
}
</script>

<template>
    <Head :title="article.title" />
    <AuthenticatedLayout>
        <template #header>
            <nav class="flex min-w-0 items-center gap-2 text-sm">
                <Link :href="route('help.index')" class="shrink-0 font-semibold text-primary-700 hover:underline dark:text-primary-300">{{ t('help.center') }}</Link>
                <span class="text-slate-400" aria-hidden="true">/</span>
                <span class="truncate text-slate-600 dark:text-slate-300">{{ t(`help.topic.${topic.slug.replaceAll('-', '_')}`) }}</span>
            </nav>
        </template>

        <article class="mx-auto max-w-3xl">
            <p v-if="fallback" class="mb-4 rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300 print:hidden">{{ t('help.arabic_only') }}</p>

            <header class="mb-6">
                <h1 class="text-2xl font-bold leading-snug text-slate-900 dark:text-slate-100 sm:text-3xl">{{ article.title }}</h1>
                <p class="mt-2 text-lg text-slate-600 dark:text-slate-300">{{ article.summary }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-3 text-sm text-slate-500 dark:text-slate-400">
                    <span>⏱ {{ t('help.minutes', { n: article.minutes }) }}</span>
                    <button type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 hover:bg-slate-100 dark:hover:bg-slate-800 print:hidden" @click="printPage">
                        <Icon name="print" /> {{ t('help.print_page') }}
                    </button>
                </div>
            </header>

            <HelpArticleBody :blocks="article.blocks" />

            <section class="mt-8 flex flex-wrap items-center justify-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 print:hidden">
                <template v-if="feedback === null">
                    <span class="font-semibold text-slate-700 dark:text-slate-200">{{ t('help.helpful') }}</span>
                    <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-xl hover:border-primary-400 dark:border-slate-700" aria-label="👍" @click="rate('up')">👍</button>
                    <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-xl hover:border-primary-400 dark:border-slate-700" aria-label="👎" @click="rate('down')">👎</button>
                </template>
                <span v-else class="font-semibold text-primary-700 dark:text-primary-300">{{ t('help.thanks') }}</span>
            </section>

            <section v-if="related.length" class="mt-8 print:hidden">
                <h2 class="mb-3 text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('help.related') }}</h2>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Link v-for="r in related" :key="r.key" :href="r.href" class="rounded-xl border border-slate-200 bg-white p-4 hover:border-primary-400 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-primary-500">
                        <p class="font-semibold text-slate-900 dark:text-slate-100">{{ r.title }}</p>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ r.summary }}</p>
                    </Link>
                </div>
            </section>
        </article>
    </AuthenticatedLayout>
</template>
