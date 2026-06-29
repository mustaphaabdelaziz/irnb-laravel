<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    categories: Array,
    subscription: Object,
});

const form = useForm({
    name: props.subscription.name || '',
    year: props.subscription.year || new Date().getFullYear(),
    amount_student: props.subscription.amount_student || '',
    amount_worker: props.subscription.amount_worker || '',
    category_ids: props.subscription.categories?.map(c => c.id) || [],
});

function submit() {
    form.put(route('subscriptions.update', props.subscription.id));
}
</script>

<template>
    <Head :title="t('edit')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('subscriptions.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('edit') }} — {{ subscription.name }}</h1>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-2xl space-y-6">
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="space-y-4">
                    <div>
                        <InputLabel :value="t('name')" />
                        <TextInput v-model="form.name" class="mt-1 w-full" required />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('year')" />
                        <TextInput v-model="form.year" type="number" min="2020" max="2050" class="mt-1 w-full" required />
                        <InputError :message="form.errors.year" class="mt-1" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('student_pricing')" />
                            <TextInput v-model="form.amount_student" type="number" step="0.01" min="0" class="mt-1 w-full" required />
                            <InputError :message="form.errors.amount_student" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel :value="t('worker_pricing')" />
                            <TextInput v-model="form.amount_worker" type="number" step="0.01" min="0" class="mt-1 w-full" required />
                            <InputError :message="form.errors.amount_worker" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel :value="t('categories')" />
                        <div class="mt-2 flex flex-wrap gap-3">
                            <label v-for="cat in categories" :key="cat.id" class="flex items-center gap-2">
                                <input type="checkbox" :value="cat.id" v-model="form.category_ids"
                                    class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                                <span class="text-sm">{{ cat.name }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <Link :href="route('subscriptions.index')">
                    <SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton>
                </Link>
                <PrimaryButton :disabled="form.processing">{{ t('save_changes') }}</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
