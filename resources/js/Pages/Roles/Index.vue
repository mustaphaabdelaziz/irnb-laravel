<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t, locale } = useI18n();
const props = defineProps({
    roles: { type: Array, default: () => [] },
    modules: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
});

const editing = ref(null);
const form = useForm({ name: { en: '', fr: '', ar: '' }, permissions: {} });

function openCreate() {
    editing.value = 'new';
    form.reset();
    form.name = { en: '', fr: '', ar: '' };
    form.permissions = {};
}
function openEdit(role) {
    editing.value = role;
    form.name = { en: role.name?.en ?? '', fr: role.name?.fr ?? '', ar: role.name?.ar ?? '' };
    form.permissions = JSON.parse(JSON.stringify(role.permissions ?? {}));
}
function toggle(module, action) {
    const list = new Set(form.permissions[module] ?? []);
    list.has(action) ? list.delete(action) : list.add(action);
    if (list.size) form.permissions[module] = [...list];
    else delete form.permissions[module];
}
function has(module, action) {
    return (form.permissions[module] ?? []).includes(action);
}
function save() {
    if (editing.value === 'new') {
        form.post(route('roles.store'), { onSuccess: () => (editing.value = null) });
    } else {
        form.put(route('roles.update', editing.value.id), { onSuccess: () => (editing.value = null) });
    }
}
function destroy(role) {
    if (confirm(t('confirm_delete'))) router.delete(route('roles.destroy', role.id));
}
function roleName(role) {
    return role.name?.[locale.value] || role.name?.en || role.key;
}
</script>

<template>
    <Head :title="t('roles')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('roles') }}</h1>
                <PrimaryButton @click="openCreate">{{ t('add') }}</PrimaryButton>
            </div>
        </template>

        <div class="space-y-3">
            <div v-for="role in roles" :key="role.id"
                class="flex items-center justify-between rounded-2xl bg-white dark:bg-slate-900 p-4 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div>
                    <p class="font-semibold text-slate-900 dark:text-slate-100">
                        {{ roleName(role) }}
                        <span v-if="role.is_system" class="ms-2 rounded bg-primary-50 px-2 py-0.5 text-xs text-primary-700">{{ t('system') }}</span>
                    </p>
                    <p class="text-xs text-slate-400">{{ role.users_count }} {{ t('members') }}</p>
                </div>
                <div class="flex gap-2">
                    <SecondaryButton @click="openEdit(role)">{{ t('edit') }}</SecondaryButton>
                    <SecondaryButton v-if="!role.is_system" @click="destroy(role)">{{ t('delete') }}</SecondaryButton>
                </div>
            </div>
        </div>

        <!-- Editor modal -->
        <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="editing = null">
            <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('role') }}</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <input v-model="form.name.en" placeholder="English" class="rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="form.name.fr" placeholder="Français" class="rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="form.name.ar" placeholder="العربية" class="rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" />
                </div>

                <div class="mt-4 overflow-x-auto rounded-lg ring-1 ring-slate-200 dark:ring-slate-800">
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
                                    <input type="checkbox" :checked="has(m, a)" @change="toggle(m, a)"
                                        class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <SecondaryButton @click="editing = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="save">{{ t('save_changes') }}</PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
