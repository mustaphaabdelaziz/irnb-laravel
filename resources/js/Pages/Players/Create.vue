<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PlayerForm from '@/Pages/Players/Partials/PlayerForm.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    categories: Array,
    positions: Array,
    jobs: Array,
    wilayas: { type: Array, default: () => [] },
    communes: { type: Object, default: () => ({}) },
    nextSequenceByYear: { type: Object, default: () => ({}) },
    defaultJoinYear: { type: Number, default: () => new Date().getFullYear() },
});
</script>

<template>
    <Head :title="t('new_player')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('players.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('new_player') }}</h1>
            </div>
        </template>
        <PlayerForm :categories="categories" :positions="positions" :jobs="jobs" :wilayas="wilayas" :communes="communes" :next-sequence-by-year="nextSequenceByYear" :default-join-year="defaultJoinYear" />
    </AuthenticatedLayout>
</template>
