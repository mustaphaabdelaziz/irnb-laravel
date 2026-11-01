<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Icon from '@/Components/Icon.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({ report: Object, sections: Array });

const form = useForm({
    title: props.report.title,
    period_from: props.report.period_from,
    period_to: props.report.period_to,
    sections: [...(props.report.sections ?? [])],
    president_word: props.report.president_word ?? '',
    introduction: props.report.introduction ?? '',
    conclusion: props.report.conclusion ?? '',
});

const texts = ['president_word', 'introduction', 'conclusion'];

function save() {
    form.put(route('literary-reports.update', props.report.id), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <Head :title="form.title || t('literary_reports')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">
                    <Link :href="route('literary-reports.index')" class="text-slate-500 hover:text-slate-700 dark:text-slate-400">{{ t('literary_reports') }}</Link>
                    <span class="mx-2 text-slate-400">/</span>{{ report.title }}
                </h1>
                <a v-if="!form.isDirty" :href="route('literary-reports.pdf', report.id)" target="_blank" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    <Icon name="download" class="h-4 w-4" /> {{ t('literary.export_pdf') }}
                </a>
                <span v-else class="text-sm text-amber-700 dark:text-amber-400">{{ t('literary.save_first') }}</span>
            </div>
        </template>

        <form @submit.prevent="save" class="mx-auto max-w-4xl space-y-6">
            <section class="grid gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800 sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.report_title') }}</label>
                    <TextInput v-model="form.title" class="mt-1 w-full" required />
                    <InputError :message="form.errors.title" class="mt-1" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.period_from') }}</label>
                    <TextInput v-model="form.period_from" type="date" class="mt-1 w-full" required />
                    <InputError :message="form.errors.period_from" class="mt-1" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.period_to') }}</label>
                    <TextInput v-model="form.period_to" type="date" class="mt-1 w-full" required />
                    <InputError :message="form.errors.period_to" class="mt-1" />
                </div>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('literary.sections') }}</h2>
                <ul class="grid gap-2 sm:grid-cols-2">
                    <li v-for="section in sections" :key="section.key">
                        <label class="flex items-start gap-2 text-sm" :class="section.allowed ? 'text-slate-700 dark:text-slate-200' : 'text-slate-400 dark:text-slate-500'">
                            <input v-model="form.sections" type="checkbox" :value="section.key" :disabled="!section.allowed" class="mt-0.5 rounded border-slate-300 text-primary-600" />
                            <span>
                                {{ t(`literary.section.${section.key}`) }}
                                <span v-if="!section.allowed" class="block text-xs">{{ t('literary.no_right') }}</span>
                            </span>
                        </label>
                    </li>
                </ul>
                <InputError :message="form.errors.sections" class="mt-2" />
            </section>

            <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('literary.texts') }}</h2>
                <div v-for="field in texts" :key="field">
                    <label :for="field" class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t(`literary.${field}`) }}</label>
                    <textarea :id="field" v-model="form[field]" rows="8" class="mt-1 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"></textarea>
                    <InputError :message="form.errors[field]" class="mt-1" />
                </div>
            </section>

            <div class="flex justify-end">
                <PrimaryButton :disabled="form.processing || !form.isDirty">{{ t('save') }}</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
