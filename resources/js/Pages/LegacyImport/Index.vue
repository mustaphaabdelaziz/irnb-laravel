<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ result: { type: Object, default: null } });
const form = useForm({ file: null });
const submit = () => form.post(route('legacy-import.store'), { forceFormData: true, onSuccess: () => form.reset() });
</script>

<template>
    <Head :title="t('legacy_import.title')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('legacy_import.title') }}</h1></template>
        <div class="mx-auto max-w-2xl space-y-6">
            <form @submit.prevent="submit" class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('legacy_import.hint') }}</p>
                <input type="file" accept="application/json,.json" @change="form.file = $event.target.files[0]" class="w-full text-sm" />
                <InputError :message="form.errors.file" />
                <PrimaryButton :disabled="form.processing || !form.file">{{ t('legacy_import.run') }}</PrimaryButton>
            </form>
            <div v-if="result" class="rounded-2xl bg-white p-6 text-sm shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="mb-3 font-bold text-slate-900 dark:text-slate-100">{{ t('legacy_import.result') }}</h2>
                <ul class="space-y-1 text-slate-700 dark:text-slate-200">
                    <li v-for="(counts, section) in result" :key="section">
                        <b>{{ t('legacy_import.section.' + section) }}</b> —
                        {{ t('legacy_import.created') }} {{ counts.created }} · {{ t('legacy_import.skipped') }} {{ counts.skipped }}<template v-if="counts.merged !== undefined"> · {{ t('legacy_import.merged') }} {{ counts.merged }}</template>
                    </li>
                </ul>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
