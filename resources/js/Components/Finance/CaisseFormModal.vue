<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';

// account: the account being edited, or null to add a caisse.
// presetBranchId: branch pre-selected when adding from a coffre card.
// caisses: every caisse inside a coffre ({ id, branch_id, category_id }), to
// grey out categories that already have a caisse in the chosen branch.
const props = defineProps({
    show: { type: Boolean, default: false },
    account: { type: Object, default: null },
    presetBranchId: { type: [Number, null], default: null },
    branches: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    caisses: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);
const { t } = useI18n();
const { formatMoney } = useFormatMoney();
const { caisseName, isLegacyAutoName } = useFinanceAccountLabel();

const form = useForm({
    name: '', type: 'cash', branch_id: '', category_id: '', opening_balance: 0,
    account_number: '', currency: 'DZD', is_active: true, notes: '',
});

const structural = computed(() => !!(props.account?.is_treasury || props.account?.is_opening_fund));
const label = (x) => x?.localized_name || x?.name;
const title = computed(() => {
    if (!props.account) return t('caisses.add');
    if (props.account.is_treasury) return t('caisses.edit_coffre');
    // A caisse sits in a branch; club-level accounts (bank…) get a neutral word.
    return props.account.branch_id ? t('caisses.edit') : t('caisses.edit_account');
});

// Categories that already have another caisse in the selected branch.
const takenCategoryIds = computed(() => new Set(
    props.caisses
        .filter((c) => c.category_id && String(c.branch_id) === String(form.branch_id) && c.id !== props.account?.id)
        .map((c) => c.category_id),
));

// Switching a caisse off while it still holds money leaves that money out of reach.
const holdsMoney = computed(() => Math.abs(Number(props.account?.current_balance || 0)) >= 0.005);

watch(() => props.show, (open) => {
    if (!open) return;
    form.clearErrors();
    const a = props.account;
    // An old auto-created caisse ("Senior Cash Register") is shown under its
    // category's name; saving stores that name.
    form.name = a ? (isLegacyAutoName(a) ? caisseName(a) : (a.name ?? '')) : '';
    form.type = a?.type ?? 'cash';
    form.branch_id = a ? (a.branch_id ?? '') : (props.presetBranchId ?? '');
    form.category_id = a?.category_id ?? '';
    form.opening_balance = a?.opening_balance ?? 0;
    form.account_number = a?.account_number ?? '';
    form.currency = a?.currency ?? 'DZD';
    form.is_active = a ? !!a.is_active : true;
    form.notes = a?.notes ?? '';
});

watch(() => form.branch_id, (branch) => { if (!branch) form.category_id = ''; });

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    const payload = (data) => ({ ...data, branch_id: data.branch_id || null, category_id: data.category_id || null });
    if (props.account) form.transform(payload).put(route('finance.accounts.update', props.account.id), options);
    else form.transform(payload).post(route('finance.accounts.store'), options);
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form class="space-y-4 p-6" @submit.prevent="submit">
            <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">
                {{ title }}
            </h2>

            <!-- A coffre is always labelled by its branch, so its name is not asked. -->
            <label v-if="!account?.is_treasury" class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('name') }}</span>
                <input v-model="form.name" type="text" required maxlength="120" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                <InputError :message="form.errors.name" class="mt-1" />
            </label>

            <template v-if="!structural">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('caisses.place') }}</span>
                    <select v-model="form.branch_id" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t('caisses.club_level') }}</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ t('caisses.coffre_of', { branch: label(b) }) }}</option>
                    </select>
                    <InputError :message="form.errors.branch_id" class="mt-1" />
                </label>

                <label v-if="form.branch_id" class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('caisses.linked_category') }}</span>
                    <select v-model="form.category_id" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t('caisses.no_category') }}</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id" :disabled="takenCategoryIds.has(c.id)">{{ takenCategoryIds.has(c.id) ? `${label(c)} ${t('caisses.category_taken_suffix')}` : label(c) }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ t('caisses.linked_category_hint') }}</p>
                    <InputError :message="form.errors.category_id" class="mt-1" />
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('account_type') }}</span>
                    <select v-model="form.type" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="cash">{{ t('cash') }}</option>
                        <option value="bank">{{ t('bank') }}</option>
                        <option value="other">{{ t('other') }}</option>
                    </select>
                </label>

                <label v-if="form.type === 'bank'" class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('account_number') }}</span>
                    <input v-model="form.account_number" type="text" maxlength="120" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                </label>
            </template>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('finance.opening_balance') }}</span>
                <input v-model="form.opening_balance" type="number" step="0.01" class="w-full rounded-lg border-slate-200 bg-white text-sm tabular-nums dark:border-slate-700 dark:bg-slate-800" />
                <InputError :message="form.errors.opening_balance" class="mt-1" />
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('notes') }}</span>
                <textarea v-model="form.notes" rows="2" maxlength="1000" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>

            <label v-if="account && !structural" class="flex items-center gap-2 text-sm">
                <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                <span>{{ t('active') }}</span>
            </label>
            <p v-if="account && !structural && !form.is_active && holdsMoney" role="status" class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 ring-1 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-200 dark:ring-amber-900">
                {{ t('caisses.inactive_money_warning', { amount: formatMoney(account.current_balance) }) }}
            </p>

            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="emit('close')">{{ t('cancel') }}</button>
                <button type="submit" :disabled="form.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('save') }}</button>
            </div>
        </form>
    </Modal>
</template>
