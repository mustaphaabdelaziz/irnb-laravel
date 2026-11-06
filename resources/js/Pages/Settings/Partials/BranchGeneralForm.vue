<script setup>
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useCan } from '@/Composables/useCan';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({ branch: { type: Object, required: true } });

// Files only travel in multipart POSTs, so the update is a POST spoofing PUT.
const form = useForm({
    _method: 'put',
    name: props.branch.name,
    code: props.branch.code || '',
    name_ar: props.branch.name_ar || '',
    name_fr: props.branch.name_fr || '',
    name_en: props.branch.name_en || '',
    description: props.branch.description || '',
    logo: null,
    remove_logo: false,
});

function save() {
    form.post(route('branches.update', props.branch.id), { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <form @submit.prevent="save" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} <span class="text-xs text-slate-400">({{ t('default') }})</span></label>
                <TextInput v-model="form.name" class="mt-1 w-full" :placeholder="t('name')" required />
                <InputError :message="form.errors.name" class="mt-1" />
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('branch_code') }}</label>
                <TextInput v-model="form.code" class="mt-1 w-full uppercase" maxlength="6" dir="ltr" />
                <InputError :message="form.errors.code" class="mt-1" />
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (العربية)</label>
                <TextInput v-model="form.name_ar" class="mt-1 w-full" placeholder="الاسم" />
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (Français)</label>
                <TextInput v-model="form.name_fr" class="mt-1 w-full" placeholder="Nom" />
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (English)</label>
                <TextInput v-model="form.name_en" class="mt-1 w-full" placeholder="Name" />
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('description') }}</label>
                <TextInput v-model="form.description" class="mt-1 w-full" :placeholder="t('description')" />
            </div>
            <div class="sm:col-span-2">
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('logo') }}</label>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <img v-if="branch.logo_url && !form.remove_logo && !form.logo" :src="branch.logo_url" alt="" class="h-8 w-8 rounded object-contain" />
                    <input type="file" accept="image/*" class="block min-w-0 flex-1 text-xs" @change="form.logo = $event.target.files[0] || null; form.remove_logo = false" />
                    <label v-if="branch.logo_url && !form.logo" class="inline-flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                        <input v-model="form.remove_logo" type="checkbox" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500 dark:border-slate-700" />
                        {{ t('remove') }}
                    </label>
                </div>
                <InputError :message="form.errors.logo" class="mt-1" />
            </div>
        </div>
        <div v-if="can('branches', 'edit')" class="flex justify-end">
            <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
        </div>
    </form>
</template>
