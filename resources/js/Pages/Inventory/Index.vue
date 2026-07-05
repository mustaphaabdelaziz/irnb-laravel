<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    sessions: { type: Array, default: () => [] },
    itemCount: { type: Number, default: 0 },
});
const { t, locale } = useI18n();

const fType = ref('');
const fStatus = ref('');
const filteredSessions = computed(() => props.sessions.filter((s) =>
    (!fType.value || s.type === fType.value) && (!fStatus.value || s.status === fStatus.value)));

const showForm = ref(false);
const today = new Date().toISOString().slice(0, 10);
const form = useForm({ type: 'yearly', session_date: today, notes: '' });
function start() {
    form.post(route('inventory.store'));
}
function remove(s) {
    if (window.confirm(t('confirm_delete'))) router.delete(route('inventory.destroy', s.id), { preserveScroll: true });
}
function fmtDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { day: '2-digit', month: 'short', year: 'numeric' });
}
const statusChip = {
    in_progress: 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300',
    completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    cancelled: 'bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-400',
};
const lastDate = props.sessions[0]?.session_date;
</script>

<template>
    <Head :title="t('inventory')" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('inventory') }}</h1>
        </template>

        <div class="space-y-6">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <StatCard :label="t('equipments')" :value="itemCount" icon="equipment" color="primary" :suffix="t('items_to_count')" />
                <StatCard :label="t('last_inventory')" :value="lastDate ? fmtDate(lastDate) : '—'" icon="clipboard" color="slate" />
                <StatCard :label="t('inventory')" :value="sessions.length" icon="check" color="emerald" />
            </div>

            <div class="flex justify-end">
                <button @click="showForm = !showForm" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700"><Icon name="plus" /> {{ t('start_inventory') }}</button>
            </div>

            <form v-if="showForm" @submit.prevent="start" class="card flex flex-wrap items-end gap-3 p-5">
                <label class="text-sm"><span class="mb-1 block text-xs font-medium text-slate-500">{{ t('type') }}</span>
                    <select v-model="form.type" class="rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="monthly">{{ t('monthly') }}</option><option value="quarterly">{{ t('quarterly') }}</option>
                        <option value="yearly">{{ t('yearly') }}</option><option value="ad_hoc">{{ t('ad_hoc') }}</option>
                    </select></label>
                <label class="text-sm"><span class="mb-1 block text-xs font-medium text-slate-500">{{ t('date') }}</span>
                    <input v-model="form.session_date" type="date" class="rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                <label class="flex-1 text-sm"><span class="mb-1 block text-xs font-medium text-slate-500">{{ t('note') }}</span>
                    <input v-model="form.notes" class="w-full rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                <button :disabled="form.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('start_inventory') }}</button>
            </form>

            <div v-if="sessions.length" class="flex flex-wrap items-center gap-2">
                <select v-model="fType" class="rounded-xl border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="">{{ t('type') }}</option>
                    <option value="monthly">{{ t('monthly') }}</option><option value="quarterly">{{ t('quarterly') }}</option>
                    <option value="yearly">{{ t('yearly') }}</option><option value="ad_hoc">{{ t('ad_hoc') }}</option>
                </select>
                <select v-model="fStatus" class="rounded-xl border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="">{{ t('status') }}</option>
                    <option value="in_progress">{{ t('in_progress') }}</option><option value="completed">{{ t('completed') }}</option>
                </select>
            </div>

            <div class="space-y-3">
                <div v-for="s in filteredSessions" :key="s.id" class="card flex items-center justify-between gap-4 p-4">
                    <Link :href="route('inventory.show', s.id)" class="flex min-w-0 flex-1 items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300"><Icon name="clipboard" /></span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-slate-900 dark:text-slate-100">{{ s.reference }} <span class="text-xs font-normal text-slate-400">· {{ t(s.type) }}</span></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ fmtDate(s.session_date) }} · {{ s.items_count }} {{ t('item') }}<span v-if="s.conducted_by"> · {{ s.conducted_by.name }}</span></p>
                        </div>
                    </Link>
                    <div class="flex shrink-0 items-center gap-3 text-xs">
                        <template v-if="s.status === 'completed'">
                            <span class="font-bold text-emerald-600">{{ s.total_found }} {{ t('found') }}</span>
                            <span v-if="s.total_missing" class="font-bold text-rose-500">{{ s.total_missing }} {{ t('missing') }}</span>
                        </template>
                        <span class="inline-flex rounded-full px-2.5 py-0.5 font-bold" :class="statusChip[s.status]">{{ t(s.status) }}</span>
                        <button @click="remove(s)" class="text-slate-300 hover:text-rose-500"><Icon name="xcircle" /></button>
                    </div>
                </div>
                <p v-if="!sessions.length" class="py-10 text-center text-sm text-slate-400">{{ t('no_sessions') }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
