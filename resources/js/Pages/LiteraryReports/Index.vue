<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({ reports: Array, defaults: Object });

const deleteId = ref(null);
const form = useForm({ title: '', period_from: props.defaults.period_from, period_to: props.defaults.period_to });

function add() {
    form.post(route('literary-reports.store'));
}

function destroy() {
    router.delete(route('literary-reports.destroy', deleteId.value), { onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('literary_reports')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('literary_reports') }}</h1>
        </template>

        <div class="mx-auto max-w-4xl space-y-6">
            <form v-if="can('literary_reports', 'add')" @submit.prevent="add" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="min-w-56 flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.report_title') }}</label>
                    <TextInput v-model="form.title" class="mt-1 w-full" required />
                    <InputError :message="form.errors.title" class="mt-1" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.period_from') }}</label>
                    <TextInput v-model="form.period_from" type="date" class="mt-1" required />
                    <InputError :message="form.errors.period_from" class="mt-1" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.period_to') }}</label>
                    <TextInput v-model="form.period_to" type="date" class="mt-1" required />
                    <InputError :message="form.errors.period_to" class="mt-1" />
                </div>
                <PrimaryButton :disabled="form.processing">{{ t('literary.new') }}</PrimaryButton>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('literary.report_title') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('literary.period') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="report in reports" :key="report.id">
                            <td class="px-4 py-3 text-sm font-medium text-slate-900 dark:text-slate-100">
                                <Link v-if="can('literary_reports', 'edit')" :href="route('literary-reports.edit', report.id)" class="hover:text-primary-700">{{ report.title }}</Link>
                                <span v-else>{{ report.title }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300"><bdi dir="ltr">{{ report.period_from }} → {{ report.period_to }}</bdi></td>
                            <td class="px-4 py-3 text-end">
                                <div class="flex justify-end gap-2">
                                    <a :href="route('literary-reports.pdf', report.id)" target="_blank" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm text-primary-700 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-slate-800">
                                        <Icon name="download" class="h-4 w-4" /> {{ t('literary.export_pdf') }}
                                    </a>
                                    <IconButton v-if="can('literary_reports', 'edit')" icon="pencil" :label="t('edit')" plain size="sm" @click="router.visit(route('literary-reports.edit', report.id))" />
                                    <IconButton v-if="can('literary_reports', 'delete')" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = report.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!reports.length">
                            <td colspan="3" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('literary.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
