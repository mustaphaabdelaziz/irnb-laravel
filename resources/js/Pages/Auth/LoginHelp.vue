<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import HelpArticleBody from '@/Components/Help/HelpArticleBody.vue';

// The login article for people who can't sign in yet (no AppShell: Auth/ pages are outside it).
defineProps({ article: { type: Object, required: true } });
const { t } = useI18n();
</script>

<template>
    <Head :title="article.title" />
    <div class="min-h-screen bg-slate-50 px-4 py-8 dark:bg-slate-950">
        <article class="mx-auto max-w-3xl">
            <Link :href="route('login')" class="mb-4 inline-block text-sm font-semibold text-primary-700 hover:underline dark:text-primary-300">← {{ t('help.back_to_login') }}</Link>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 sm:text-3xl">{{ article.title }}</h1>
            <p class="mb-6 mt-2 text-lg text-slate-600 dark:text-slate-300">{{ article.summary }}</p>
            <HelpArticleBody :blocks="article.blocks" />
        </article>
    </div>
</template>
