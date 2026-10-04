<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    status: {
        type: String,
    },
});
</script>

<template>
    <Head :title="t('profile')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2
                    class="text-xl font-semibold leading-tight text-gray-800"
                >
                    {{ t('profile') }}
                </h2>
                <Link :href="route('profile.activity')" class="shrink-0 text-sm font-medium text-primary-700 hover:underline dark:text-primary-300">{{ t('activity.my_title') }}</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    class="bg-white dark:bg-slate-900 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdateProfileInformationForm
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white dark:bg-slate-900 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div
                    class="bg-white dark:bg-slate-900 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
