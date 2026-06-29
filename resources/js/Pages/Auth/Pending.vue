<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage();
const appName = computed(() => {
    const name = page.props.appName;
    const loc = page.props.locale || 'en';
    return typeof name === 'object' ? (name[loc] || name.en || 'IRNB') : (name || 'IRNB');
});
</script>

<template>
    <Head :title="t('account_pending_title')" />

    <div class="flex min-h-screen items-center justify-center bg-slate-50 dark:bg-slate-950 p-4">
        <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-8 text-center shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 text-2xl">⏳</div>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('account_pending_title') }}</h1>
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ t('account_pending_message') }}</p>
            <p class="mt-1 text-sm font-medium text-slate-500 dark:text-slate-400">{{ appName }}</p>

            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="mt-6 inline-flex items-center justify-center rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-5 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800"
            >
                {{ t('logout') }}
            </Link>
        </div>
    </div>
</template>
