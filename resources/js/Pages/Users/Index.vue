<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { useListFilters } from '@/Composables/useListFilters';

const { t } = useI18n();

const props = defineProps({
    users: Object,
    filters: Object,
    pendingCount: Number,
    canManageAccess: { type: Boolean, default: false },
    currentUserId: { type: Number, default: 0 },
});

const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');
const roleFilter = ref(props.filters?.role || '');
const deleteId = ref(null);

const { loading: filtering } = useListFilters('users.index', () => ({
    search: search.value,
    status: statusFilter.value,
    role: roleFilter.value,
}), { only: ['users', 'filters'] });

function approve(id) {
    router.post(route('users.approve', id), {}, { preserveScroll: true });
}

function destroy() {
    router.delete(route('users.destroy', deleteId.value), {
        onSuccess: () => { deleteId.value = null; },
        onError: () => { deleteId.value = null; },
    });
}

function toggleActive(id) {
    router.post(route('users.toggleActive', id), {}, { preserveScroll: true });
}

// Reset-password modal (superadmin only).
const pwUser = ref(null);
const pwForm = useForm({ password: '', password_confirmation: '' });

function openReset(user) {
    pwUser.value = user;
    pwForm.reset();
    pwForm.clearErrors();
}

function submitReset() {
    pwForm.post(route('users.password', pwUser.value.id), {
        preserveScroll: true,
        onSuccess: () => { pwUser.value = null; },
    });
}

function initial(user) {
    return (user.fullname || user.name || '?').charAt(0).toUpperCase();
}
</script>

<template>
    <Head :title="t('members')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('members') }}</h1>
                <Badge v-if="pendingCount" :label="`${pendingCount} ${t('pending_approval')}`" color="amber" />
            </div>
        </template>

        <div class="space-y-4">
            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="w-full sm:w-64">
                    <SearchInput v-model="search" :loading="filtering" :placeholder="t('search')" />
                </div>
                <select v-model="statusFilter" class="rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <option value="">{{ t('all') }}</option>
                    <option value="pending">{{ t('pending_approval') }}</option>
                    <option value="approved">{{ t('approved') }}</option>
                    <option value="active">{{ t('active') }}</option>
                    <option value="inactive">{{ t('inactive') }}</option>
                </select>
                <select v-model="roleFilter" class="rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <option value="">{{ t('all') }}</option>
                    <option value="admin">{{ t('administrator') }}</option>
                    <option value="user">{{ t('user') }}</option>
                </select>
            </div>

            <!-- Table -->
            <div :class="{ 'opacity-60': filtering }" :aria-busy="filtering" class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 transition-opacity dark:ring-slate-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('phone') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('role') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="user in users.data" :key="user.id" class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <img v-if="user.picture_url" :src="user.picture_url" :alt="user.name" class="h-9 w-9 rounded-full object-cover" />
                                        <span v-else class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-100 text-sm font-bold text-primary-700">{{ initial(user) }}</span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ user.fullname || user.name }}</p>
                                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ user.email || '-' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ user.phones?.[0] || '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge v-if="user.is_superadmin" :label="t('super_admin')" color="primary" />
                                        <Badge v-else-if="user.privileges?.includes('admin')" :label="t('administrator')" color="blue" />
                                        <Badge v-else :label="t('user')" color="slate" />
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge v-if="user.approved" :label="t('approved')" color="emerald" />
                                        <Badge v-else :label="t('pending')" color="amber" />
                                        <Badge v-if="!user.is_active" :label="t('inactive')" color="rose" />
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end">
                                    <div class="flex items-center justify-end gap-3">
                                        <button v-if="!user.approved" @click="approve(user.id)" class="text-sm font-medium text-emerald-600 hover:text-emerald-800">{{ t('approve') }}</button>
                                        <Link :href="route('users.edit', user.id)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('edit') }}</Link>
                                        <!-- Reset password: superadmin only, never on yourself (use your profile). -->
                                        <button v-if="canManageAccess && user.id !== currentUserId" @click="openReset(user)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-primary-600">{{ t('reset_password') }}</button>
                                        <!-- Disable / enable sign-in. -->
                                        <button v-if="user.id !== currentUserId" @click="toggleActive(user.id)"
                                            :class="user.is_active ? 'text-amber-600 hover:text-amber-800' : 'text-emerald-600 hover:text-emerald-800'"
                                            class="text-sm">{{ user.is_active ? t('disable') : t('enable') }}</button>
                                        <!-- Delete: the backend refuses the last superadmin and self. -->
                                        <button v-if="user.id !== currentUserId" @click="deleteId = user.id" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!users.data.length">
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4">
                    <Pagination :links="users" />
                </div>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />

        <!-- Reset password -->
        <Teleport to="body">
            <div v-if="pwUser" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="pwUser = null">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('reset_password') }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ pwUser.fullname || pwUser.name }} — {{ pwUser.email }}</p>
                    <form @submit.prevent="submitReset" class="mt-4 space-y-3">
                        <div>
                            <InputLabel :value="t('new_password')" />
                            <TextInput v-model="pwForm.password" type="password" class="mt-1 w-full" autocomplete="new-password" required />
                            <InputError :message="pwForm.errors.password" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel :value="t('confirm_password')" />
                            <TextInput v-model="pwForm.password_confirmation" type="password" class="mt-1 w-full" autocomplete="new-password" required />
                        </div>
                        <p class="rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">{{ t('reset_password_hint') }}</p>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="pwUser = null" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <PrimaryButton :disabled="pwForm.processing">{{ t('save') }}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
