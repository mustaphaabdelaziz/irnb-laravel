<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import IconButton from '@/Components/IconButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PermissionGrid from '@/Components/PermissionGrid.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t, locale } = useI18n();
const props = defineProps({
    roles: { type: Array, default: () => [] },
    moduleGroups: { type: Object, default: () => ({}) },
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
                <IconButton icon="plus" :label="t('add')" variant="primary" @click="openCreate" />
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
                    <p class="text-xs text-slate-400">{{ role.users_count }} {{ t('users') }}</p>
                </div>
                <div class="flex gap-1">
                    <IconButton icon="pencil" :label="t('edit')" plain size="sm" @click="openEdit(role)" />
                    <IconButton v-if="!role.is_system" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="destroy(role)" />
                </div>
            </div>
        </div>

        <!-- Editor modal. Teleported to <body>: <main> animates with a transform,
             which would make `fixed` relative to it and slide the form's top
             (the name fields) under the sticky header. -->
        <Teleport to="body">
        <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="editing = null">
            <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('role') }}</h2>
                <InputLabel :value="t('name')" required />
                <div class="mt-1 grid gap-3 sm:grid-cols-3">
                    <input v-model="form.name.en" placeholder="English" class="rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="form.name.fr" placeholder="Français" class="rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="form.name.ar" placeholder="العربية" dir="rtl" class="rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <InputError :message="form.errors.name || form.errors['name.en'] || form.errors['name.fr'] || form.errors['name.ar']" class="mt-1" />
                <InputError :message="form.errors.permissions" class="mt-1" />

                <PermissionGrid v-model="form.permissions" :groups="moduleGroups" :actions="actions" class="mt-4" />

                <div class="mt-4 flex justify-end gap-2">
                    <SecondaryButton @click="editing = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="save">{{ t('save_changes') }}</PrimaryButton>
                </div>
            </div>
        </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
