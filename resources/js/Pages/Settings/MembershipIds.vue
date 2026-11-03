<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    branches: { type: Array, default: () => [] },
    count: Number,
    samples: { type: Array, default: () => [] },
    yearChanges: { type: Array, default: () => [] },
    skipped: { type: Array, default: () => [] },
    canUndo: Boolean,
});

const confirming = ref(null);
function run() {
    const action = confirming.value;
    confirming.value = null;
    router.post(route(`membership-ids.${action}`), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('membership_ids_convert_title')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('membership_ids_convert_title') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ t('membership_ids_codes') }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('membership_ids_codes_hint') }}</p>
                <ul class="mt-3 flex flex-wrap gap-2">
                    <li v-for="branch in branches" :key="branch.id" class="rounded-lg bg-slate-100 px-3 py-1 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {{ branch.localized_name || branch.name }} · <span class="font-mono" dir="ltr">{{ branch.code }}</span>
                    </li>
                </ul>
                <Link :href="route('branches.index')" class="mt-3 inline-block text-sm text-primary-600 hover:underline dark:text-primary-300">{{ t('branches') }}</Link>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ t('membership_ids_preview', { count }) }}</h2>
                <ul v-if="samples.length" class="mt-3 space-y-1 text-sm text-slate-600 dark:text-slate-300">
                    <li v-for="sample in samples" :key="sample.registration_id" class="flex flex-wrap gap-2">
                        <span class="min-w-0 break-words">{{ sample.player_name }}</span>
                        <span class="font-mono" dir="ltr">{{ sample.from || '—' }} → {{ sample.to }}</span>
                    </li>
                </ul>
                <div class="mt-4 flex flex-wrap gap-3">
                    <PrimaryButton :disabled="!count" @click="confirming = 'convert'">{{ t('membership_ids_convert') }}</PrimaryButton>
                    <SecondaryButton v-if="canUndo" @click="confirming = 'undo'">{{ t('membership_ids_undo') }}</SecondaryButton>
                </div>
            </section>

            <section v-if="yearChanges.length" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-amber-200 dark:bg-slate-900 dark:ring-amber-900">
                <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ t('membership_ids_year_changes', { count: yearChanges.length }) }}</h2>
                <ul class="mt-3 space-y-1 text-sm text-slate-600 dark:text-slate-300">
                    <li v-for="change in yearChanges" :key="change.registration_id" class="flex flex-wrap gap-2">
                        <span class="min-w-0 break-words">{{ change.player_name }}</span>
                        <span class="font-mono" dir="ltr">{{ change.from }} → {{ change.to }}</span>
                    </li>
                </ul>
            </section>

            <section v-if="skipped.length" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ t('membership_ids_skipped', { count: skipped.length }) }}</h2>
                <ul class="mt-3 space-y-1 text-sm text-slate-600 dark:text-slate-300">
                    <li v-for="row in skipped" :key="row.registration_id" class="break-words">{{ row.player_name }}</li>
                </ul>
            </section>
        </div>

        <ConfirmModal :show="!!confirming" :message="confirming === 'undo' ? t('membership_ids_undo_confirm') : t('membership_ids_convert_confirm', { count })" @confirm="run" @cancel="confirming = null" />
    </AuthenticatedLayout>
</template>
