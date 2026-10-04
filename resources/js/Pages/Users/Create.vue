<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    roles: { type: Array, default: () => [] },
    canManageAccess: { type: Boolean, default: false },
});

const lng = usePage().props.auth?.user?.preferred_lng || 'ar';

const form = useForm({
    username: '',
    password: '',
    password_confirmation: '',
    name: '',
    firstname: '',
    lastname: '',
    email: '',
    phone: '',
    gender: 'Male',
    role_id: null,
    preferred_lng: 'ar',
});

function roleLabel(role) {
    return role.name?.[lng] || role.name?.en || role.key;
}

function submit() {
    form.transform((data) => {
        const { phone, ...rest } = data;
        return { ...rest, phones: phone ? [phone] : [] };
    }).post(route('users.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head :title="t('new_user')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('users.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('new_user') }}</h1>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-6">
            <!-- Sign-in -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('sign_in_details') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel for="username" :value="t('username')" />
                        <TextInput id="username" v-model="form.username" class="mt-1 w-full" required autofocus autocomplete="off" autocapitalize="none" spellcheck="false" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('username_hint') }}</p>
                        <InputError :message="form.errors.username" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="password" :value="t('password')" />
                        <TextInput id="password" v-model="form.password" type="password" class="mt-1 w-full" required autocomplete="new-password" />
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="password_confirmation" :value="t('confirm_password')" />
                        <TextInput id="password_confirmation" v-model="form.password_confirmation" type="password" class="mt-1 w-full" required autocomplete="new-password" />
                    </div>
                </div>
            </div>

            <!-- Identity -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('basic_info') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel for="name" :value="t('name')" />
                        <TextInput id="name" v-model="form.name" class="mt-1 w-full" required />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="email" :value="t('email')" />
                        <TextInput id="email" v-model="form.email" type="email" class="mt-1 w-full" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('firstname')" />
                        <TextInput v-model="form.firstname" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('lastname')" />
                        <TextInput v-model="form.lastname" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('phone')" />
                        <TextInput v-model="form.phone" type="tel" class="mt-1 w-full" />
                        <InputError :message="form.errors['phones.0']" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('gender')" />
                        <select v-model="form.gender" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="Male">{{ t('male') }}</option>
                            <option value="Female">{{ t('female') }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Access -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('account_access') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-if="canManageAccess">
                        <InputLabel :value="t('role')" />
                        <select v-model="form.role_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option :value="null">{{ t('no_role') }}</option>
                            <option v-for="r in props.roles" :key="r.id" :value="r.id">{{ roleLabel(r) }}</option>
                        </select>
                        <InputError :message="form.errors.role_id" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('language')" />
                        <select v-model="form.preferred_lng" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="ar">العربية</option>
                            <option value="fr">Français</option>
                            <option value="en">English</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <Link :href="route('users.index')">
                    <SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton>
                </Link>
                <PrimaryButton :disabled="form.processing">{{ t('create') }}</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
