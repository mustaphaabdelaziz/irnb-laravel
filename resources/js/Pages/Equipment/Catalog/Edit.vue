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

const props = defineProps({ catalog: Object });

const equipmentCategories = [
    'Balls', 'Goals & Nets', 'Training Equipment', 'Apparel',
    'Protective Gear', 'Accessories', 'Maintenance', 'Other',
];

const form = useForm({
    name: props.catalog.name || '',
    category: props.catalog.category || '',
    brand: props.catalog.brand || '',
    description: props.catalog.description || '',
    purchase_price: props.catalog.purchase_price || '',
    picture: null,
});

function submit() {
    form.post(route('equipment.catalogs.update', props.catalog.id), {
        forceFormData: true,
        _method: 'put',
    });
}
</script>

<template>
    <Head :title="t('edit')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('equipment.catalogs.show', catalog.id)" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('edit') }} — {{ catalog.name }}</h1>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-2xl space-y-6">
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('name')" />
                            <TextInput v-model="form.name" class="mt-1 w-full" required />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel :value="t('category')" />
                            <select v-model="form.category" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" required>
                                <option value="">{{ t('select_category') }}</option>
                                <option v-for="cat in equipmentCategories" :key="cat" :value="cat">{{ cat }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('brand')" />
                            <TextInput v-model="form.brand" class="mt-1 w-full" />
                        </div>
                        <div>
                            <InputLabel :value="t('price')" />
                            <TextInput v-model="form.purchase_price" type="number" step="0.01" min="0" class="mt-1 w-full" />
                        </div>
                    </div>
                    <div>
                        <InputLabel :value="t('description')" />
                        <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                    </div>
                    <div>
                        <InputLabel :value="t('picture')" />
                        <div v-if="catalog.picture_url" class="mb-2">
                            <img :src="catalog.picture_url" :alt="catalog.name" class="h-20 w-20 rounded-lg object-contain" />
                        </div>
                        <input type="file" accept="image/*" @change="form.picture = $event.target.files[0]"
                            class="text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100" />
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-end gap-3">
                <Link :href="route('equipment.catalogs.show', catalog.id)">
                    <SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton>
                </Link>
                <PrimaryButton :disabled="form.processing">{{ t('save_changes') }}</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
