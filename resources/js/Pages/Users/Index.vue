<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref, watch } from 'vue';

const { t } = useI18n();

const props = defineProps({
    users: Object,
    filters: Object,
    pendingCount: Number,
});

const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');
const roleFilter = ref(props.filters?.role || '');
const deleteId = ref(null);

function applyFilters() {
    router.get(route('users.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        role: roleFilter.value || undefined,
    }, { preserveState: true, replace: true });
}

watch(search, applyFilters);
watch(statusFilter, applyFilters);
watch(roleFilter, applyFilters);

function approve(id) {
    router.post(route('users.approve', id), {}, { preserveScroll: true });
}

function destroy() {
    router.delete(route('users.destroy', deleteId.value), {
        onSuccess: () => { deleteId.value = null; },
        onError: () => { deleteId.value = null; },
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
                    <SearchInput v-model="search" :placeholder="t('search')" />
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
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
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
                                        <button v-if="!user.is_superadmin" @click="deleteId = user.id" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
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
    </AuthenticatedLayout>
</template>
