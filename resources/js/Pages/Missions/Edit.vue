<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MissionOrderForm from './Partials/MissionOrderForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ order: Object, users: Array, boardMembers: Array, players: Array, teams: Array });
</script>

<template>
    <Head :title="t('mis.title') + ' — ' + (order.destination || '')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mis.title') }} — {{ order.destination }}</h1></template>
        <MissionOrderForm :order="order" :users="users" :board-members="boardMembers" :players="players" :teams="teams" />
    </AuthenticatedLayout>
</template>
