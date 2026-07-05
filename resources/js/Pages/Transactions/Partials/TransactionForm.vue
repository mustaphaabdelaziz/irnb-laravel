<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps({
    transaction: { type: Object, default: null },
    financeCategories: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    clubCcp: { type: Object, default: null },
});

const isEdit = !!props.transaction;

const form = useForm({
    transaction_type: props.transaction?.transaction_type || 'income',
    finance_category_id: props.transaction?.finance_category_id || '',
    amount: props.transaction?.amount || '',
    transaction_date: props.transaction?.transaction_date ? String(props.transaction.transaction_date).slice(0, 10) : new Date().toISOString().slice(0, 10),
    description: props.transaction?.description || '',
    payment_method: props.transaction?.payment_method || 'cash',
    payment_account: props.transaction?.payment_account || '',
    payment_ccp_key: props.transaction?.payment_ccp_key || '',
    payment_bank_name: props.transaction?.payment_bank_name || '',
    payment_holder: props.transaction?.payment_holder || '',
    payment_reference: props.transaction?.payment_reference || '',
    status: props.transaction?.status || 'Paid',
    related_entity_id: props.transaction?.related_entity_id || '',
    receipt: null,
});

const categoryOptions = computed(() => props.financeCategories.filter((c) => c.type === form.transaction_type));
const playerOptions = computed(() => props.players.map((p) => ({ value: p.id, label: p.fullname || `${p.firstname} ${p.lastname}` })));

// reset category when the type flips and the selection no longer matches
function onTypeChange() {
    const stillValid = categoryOptions.value.some((c) => String(c.id) === String(form.finance_category_id));
    if (!stillValid) form.finance_category_id = '';
}

function useClubCcp() {
    if (!props.clubCcp) return;
    form.payment_account = props.clubCcp.accountNumber || props.clubCcp.account_number || '';
    form.payment_ccp_key = props.clubCcp.key || '';
    form.payment_holder = props.clubCcp.holder || '';
}

function submit() {
    form.transform((data) => ({
        ...data,
        related_entity_id: data.related_entity_id || null,
        related_entity_type: data.related_entity_id ? 'Player' : null,
        ...(isEdit ? { _method: 'put' } : {}),
    })).post(isEdit ? route('transactions.update', props.transaction.id) : route('transactions.store'), { forceFormData: true });
}
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-2xl space-y-6">
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel :value="t('type')" />
                    <select v-model="form.transaction_type" @change="onTypeChange" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm" required>
                        <option value="income">{{ t('income') }}</option>
                        <option value="expense">{{ t('expense') }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('category')" />
                    <select v-model="form.finance_category_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm" required>
                        <option value="" disabled>-</option>
                        <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <InputError :message="form.errors.finance_category_id" class="mt-1" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel :value="t('amount')" />
                    <TextInput v-model="form.amount" type="number" step="0.01" min="0" class="mt-1 w-full" required />
                    <InputError :message="form.errors.amount" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('date')" />
                    <TextInput v-model="form.transaction_date" type="date" class="mt-1 w-full" required />
                    <InputError :message="form.errors.transaction_date" class="mt-1" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel :value="t('payment_method')" />
                    <select v-model="form.payment_method" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm">
                        <option value="cash">{{ t('cash') }}</option>
                        <option value="bank">{{ t('bank_transfer') }}</option>
                        <option value="ccp">CCP</option>
                        <option value="baridimob">BaridiMob</option>
                        <option value="other">{{ t('other') }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('status')" />
                    <select v-model="form.status" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm">
                        <option value="Paid">{{ t('paid') }}</option>
                        <option value="Partial">{{ t('partial') }}</option>
                        <option value="Unpaid">{{ t('unpaid') }}</option>
                        <option value="Exempt">{{ t('exempt') }}</option>
                    </select>
                </div>
            </div>

            <!-- CCP details -->
            <div v-if="form.payment_method === 'ccp'" class="rounded-xl bg-slate-50 dark:bg-slate-950 p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ t('ccp_details') }}</p>
                    <button v-if="clubCcp" type="button" @click="useClubCcp" class="text-xs font-medium text-primary-600 hover:underline">{{ t('use_club_ccp') }}</button>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div><InputLabel :value="t('ccp_number')" /><TextInput v-model="form.payment_account" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('ccp_key')" /><TextInput v-model="form.payment_ccp_key" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('holder')" /><TextInput v-model="form.payment_holder" class="mt-1 w-full" /></div>
                </div>
            </div>

            <!-- Bank details -->
            <div v-if="form.payment_method === 'bank'" class="rounded-xl bg-slate-50 dark:bg-slate-950 p-4 space-y-3">
                <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ t('bank_details') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><InputLabel :value="t('bank_name')" /><TextInput v-model="form.payment_bank_name" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('account_number')" /><TextInput v-model="form.payment_account" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('holder')" /><TextInput v-model="form.payment_holder" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('reference')" /><TextInput v-model="form.payment_reference" class="mt-1 w-full" /></div>
                </div>
            </div>

            <div v-if="players.length">
                <InputLabel :value="t('player')" />
                <SearchableSelect v-model="form.related_entity_id" :options="playerOptions" :placeholder="t('search_player')" />
            </div>

            <div>
                <InputLabel :value="t('description')" />
                <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm" />
            </div>

            <div>
                <InputLabel :value="t('receipt')" />
                <div v-if="transaction?.receipt_url" class="mb-2">
                    <a :href="transaction.receipt_url" target="_blank" class="text-sm font-medium text-primary-600 hover:underline">{{ t('view') }} ↗</a>
                </div>
                <input type="file" accept="image/*,application/pdf" @change="form.receipt = $event.target.files[0]"
                    class="text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100" />
                <InputError :message="form.errors.receipt" class="mt-1" />
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="route('transactions.index')"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('save') }}</PrimaryButton>
        </div>
    </form>
</template>
