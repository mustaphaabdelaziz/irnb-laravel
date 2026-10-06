<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';

const { t } = useI18n();

const props = defineProps({
    user: Object,
    roles: { type: Array, default: () => [] },
    modules: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
    canManageAccess: { type: Boolean, default: false },
    canManagePrivileges: { type: Boolean, default: false },
});

// The admin privilege: full access to everything, whatever the role says.
const canToggleFullAccess = computed(() => props.canManagePrivileges && !props.user.is_superadmin);

const preferredLng = props.user.preferred_lng || 'ar';
const showAdvanced = ref(false);

const form = useForm({
    username: props.user.username || '',
    firstname: props.user.firstname || '',
    lastname: props.user.lastname || '',
    email: props.user.email || '',
    phone: props.user.phones?.[0] || '',
    gender: props.user.gender || 'Male',
    role_id: props.user.role_id ?? null,
    full_access: (props.user.privileges ?? []).includes('admin'),
    permission_overrides: {
        grant: props.user.permission_overrides?.grant ?? {},
        revoke: props.user.permission_overrides?.revoke ?? {},
    },
    approved: props.user.approved ?? false,
    is_active: props.user.is_active ?? true,
    preferred_lng: preferredLng,
    picture: null,
});

// Searchable by its name in every language and its key.
const roleOptions = computed(() => props.roles.map((r) => ({
    value: r.id,
    label: r.name?.[preferredLng] || r.name?.en || r.key,
    keywords: [r.key, ...Object.values(r.name ?? {})].join(' '),
})));

function toggleOverride(bucket, module, action) {
    const set = form.permission_overrides[bucket];
    const list = new Set(set[module] ?? []);
    list.has(action) ? list.delete(action) : list.add(action);
    if (list.size) set[module] = [...list];
    else delete set[module];
}

function hasOverride(bucket, module, action) {
    return (form.permission_overrides[bucket][module] ?? []).includes(action);
}

function submit() {
    form.transform(({ full_access, ...data }) => ({
        ...data,
        phones: data.phone ? [data.phone] : [],
        permission_overrides: JSON.stringify(data.permission_overrides),
        // Sent only by someone allowed to change it; the server checks again.
        ...(canToggleFullAccess.value ? { privileges: [full_access ? 'admin' : 'user'] } : {}),
        _method: 'put',
    })).post(route('users.update', props.user.id), {
        forceFormData: true,
        // A refused save must be visible even when the faulty field is off-screen.
        onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }),
    });
}

const hasErrors = computed(() => Object.keys(form.errors).length > 0);
</script>

<template>
    <Head :title="t('edit')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('users.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('edit') }} — {{ user.fullname || user.name }}</h1>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-6">
            <div v-if="hasErrors" role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
                <p class="font-semibold">{{ t('user_save_failed') }}</p>
                <ul class="mt-1 list-disc ps-5">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
            </div>

            <!-- Identity -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('basic_info') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel :value="t('username')" required />
                        <TextInput v-model="form.username" class="mt-1 w-full" required autocomplete="off" autocapitalize="none" spellcheck="false" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('username_hint') }}</p>
                        <InputError :message="form.errors.username" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('firstname')" required />
                        <TextInput v-model="form.firstname" class="mt-1 w-full" required />
                        <InputError :message="form.errors.firstname" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('lastname')" required />
                        <TextInput v-model="form.lastname" class="mt-1 w-full" required />
                        <InputError :message="form.errors.lastname" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('email')" />
                        <TextInput v-model="form.email" type="email" class="mt-1 w-full" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('phone')" />
                        <TextInput v-model="form.phone" type="tel" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('gender')" />
                        <select v-model="form.gender" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="Male">{{ t('male') }}</option>
                            <option value="Female">{{ t('female') }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Access & status -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('account_access') }}</h2>

                <div v-if="user.is_superadmin" class="mb-4 rounded-lg bg-primary-50 px-4 py-2 text-sm text-primary-700">
                    {{ t('super_admin') }} — {{ t('role') }}
                </div>

                <label v-if="canToggleFullAccess" class="mb-4 flex items-start gap-3 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                    <input type="checkbox" v-model="form.full_access" class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                    <span>
                        <span class="block text-sm font-medium text-slate-800 dark:text-slate-100">{{ t('user_full_access') }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('user_full_access_hint') }}</span>
                    </span>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-if="canManageAccess">
                        <InputLabel :value="t('role')" />
                        <SearchableSelect v-model="form.role_id" :options="roleOptions" :placeholder="t('no_role')" class="mt-1" />
                        <InputError :message="form.errors.role_id" class="mt-1" />
                        <p v-if="form.full_access || user.is_superadmin" class="mt-1 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ t('user_role_ignored') }}</p>
                        <button type="button" class="mt-2 text-sm font-medium text-primary-600 hover:text-primary-700" @click="showAdvanced = !showAdvanced">
                            {{ showAdvanced ? t('hide_advanced') : t('advanced_overrides') }}
                        </button>

                        <div v-if="showAdvanced" class="mt-3 overflow-x-auto rounded-lg ring-1 ring-slate-200 dark:ring-slate-800">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-slate-500">
                                        <th class="p-2">{{ t('module') }}</th>
                                        <th v-for="a in actions" :key="a" class="p-2 text-center">{{ t(a) }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="m in modules" :key="m" class="border-t border-slate-100 dark:border-slate-800">
                                        <td class="p-2 font-medium">{{ t(m) }}</td>
                                        <td v-for="a in actions" :key="a" class="p-2 text-center">
                                            <input type="checkbox" :checked="hasOverride('grant', m, a)" @change="toggleOverride('grant', m, a)" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="p-2 text-xs text-slate-400">{{ t('overrides_hint') }}</p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                            <input type="checkbox" v-model="form.approved" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                            {{ t('approved') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                            <input type="checkbox" v-model="form.is_active" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                            {{ t('active') }}
                        </label>
                        <div>
                            <InputLabel :value="t('language')" />
                            <select v-model="form.preferred_lng" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="ar">العربية</option>
                                <option value="fr">Français</option>
                                <option value="en">English</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Picture -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('picture') }}</h2>
                <div v-if="user.picture_url" class="mb-3">
                    <img :src="user.picture_url" :alt="user.fullname" class="h-24 w-24 rounded-full object-cover" />
                </div>
                <input type="file" accept="image/*" @change="form.picture = $event.target.files[0]"
                    class="text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100" />
                <InputError :message="form.errors.picture" class="mt-1" />
            </div>

            <div class="flex items-center justify-end gap-3">
                <Link :href="route('users.index')">
                    <SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton>
                </Link>
                <PrimaryButton :disabled="form.processing">{{ t('save_changes') }}</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
