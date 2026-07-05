<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({ meetings: { type: Array, default: () => [] } });
const { t, locale } = useI18n();

const showForm = ref(false);
const form = useForm({ title: '', type: 'ordinary', meeting_date: '', location: '', status: 'scheduled', quorum_required: null, agenda: [''] });

function addAgenda() { form.agenda.push(''); }
function removeAgenda(i) { form.agenda.splice(i, 1); }
function submit() {
    form.transform((d) => ({ ...d, agenda: d.agenda.filter((x) => x && x.trim()) }))
        .post(route('board.meetings.store'), { onSuccess: () => { form.reset(); showForm.value = false; } });
}
function fmtDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
}
const statusChip = {
    scheduled: 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300',
    held: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    cancelled: 'bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-400',
};
</script>

<template>
    <Head :title="t('meetings')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('board.index')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"><Icon name="back" /></Link>
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('meetings') }}</h1>
            </div>
        </template>

        <div class="space-y-5">
            <div class="flex justify-end">
                <button @click="showForm = !showForm" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700"><Icon name="plus" /> {{ t('add_meeting') }}</button>
            </div>

            <form v-if="showForm" @submit.prevent="submit" class="card space-y-4 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm sm:col-span-2"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('title') }}</span>
                        <input v-model="form.title" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('meeting_type') }}</span>
                        <select v-model="form.type" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="ordinary">{{ t('ordinary') }}</option><option value="extraordinary">{{ t('extraordinary') }}</option><option value="general_assembly">{{ t('general_assembly') }}</option>
                        </select></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('date') }}</span>
                        <input v-model="form.meeting_date" type="datetime-local" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('location') }}</span>
                        <input v-model="form.location" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('quorum') }}</span>
                        <input v-model="form.quorum_required" type="number" min="0" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                </div>
                <div>
                    <span class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">{{ t('agenda') }}</span>
                    <div class="space-y-2">
                        <div v-for="(item, i) in form.agenda" :key="i" class="flex items-center gap-2">
                            <input v-model="form.agenda[i]" :placeholder="`${i + 1}.`" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <button type="button" @click="removeAgenda(i)" class="text-slate-300 hover:text-rose-500"><Icon name="xcircle" /></button>
                        </div>
                    </div>
                    <button type="button" @click="addAgenda" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-700"><Icon name="plus" /> {{ t('add_item') }}</button>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="showForm = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                    <button :disabled="form.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('create') }}</button>
                </div>
            </form>

            <div class="space-y-3">
                <Link v-for="mt in meetings" :key="mt.id" :href="route('board.meetings.show', mt.id)" class="card flex items-center justify-between gap-4 p-4 transition-shadow hover:shadow-md">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 ring-1 ring-primary-100 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-500/20"><Icon name="calendar" /></span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-slate-900 dark:text-slate-100">{{ mt.title }}</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ fmtDate(mt.meeting_date) }} · {{ t(mt.type) }}<span v-if="mt.location"> · {{ mt.location }}</span></p>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-3 text-xs">
                        <span v-if="mt.present_count" class="hidden items-center gap-1 text-slate-400 sm:inline-flex"><Icon name="board" /> {{ mt.present_count }}</span>
                        <span v-if="mt.tasks_count" class="hidden items-center gap-1 text-slate-400 sm:inline-flex"><Icon name="task" /> {{ mt.tasks_count }}</span>
                        <span class="inline-flex rounded-full px-2.5 py-0.5 font-bold" :class="statusChip[mt.status]">{{ t(mt.status) }}</span>
                    </div>
                </Link>
                <p v-if="!meetings.length" class="py-10 text-center text-sm text-slate-400">{{ t('no_meetings') }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
