<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
import { localToday } from '@/lib/localDate';

const { t, locale } = useI18n();
const props = defineProps({
    order: { type: Object, default: null },
    financeCategories: { type: Array, default: () => [] },
    financeAccounts: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    boardMembers: { type: Array, default: () => [] },
    boardMeetings: { type: Array, default: () => [] },
});
const { accountLabel } = useFinanceAccountLabel();
const isEdit = !!props.order;
const day = (value) => (value ? String(value).slice(0, 10) : '');

const form = useForm({
    order_date: day(props.order?.order_date) || localToday(),
    subject: props.order?.subject || '',
    amount: props.order?.amount || '',
    finance_category_id: props.order?.finance_category_id || '',
    finance_account_id: props.order?.finance_account_id || '',
    payment_method: props.order?.payment_method || 'bank',
    beneficiary_type: props.order?.beneficiary_type || 'supplier',
    beneficiary_id: props.order?.beneficiary_id || '',
    beneficiary_bank: props.order?.beneficiary_bank || '',
    beneficiary_rib: props.order?.beneficiary_rib || '',
    payment_reference: props.order?.payment_reference || '',
    payment_date: day(props.order?.payment_date),
    invoice_number: props.order?.invoice_number || '',
    invoice_date: day(props.order?.invoice_date),
    board_meeting_id: props.order?.board_meeting_id || '',
    observation: props.order?.observation || '',
});

const categoryName = (c) => c[`name_${locale.value}`] || c.name;
const beneficiaryOptions = computed(() => {
    const list = { supplier: props.suppliers, user: props.users, board_member: props.boardMembers }[form.beneficiary_type] || [];
    return list.map((b) => ({ value: b.id, label: b.name, description: b.rib || b.role || '' }));
});
const meetingLabel = (m) => [m.pv_number, m.title, day(m.meeting_date)].filter(Boolean).join(' · ');

// Picking a supplier fills the bank details once; they stay editable.
watch(() => form.beneficiary_id, (id) => {
    if (form.beneficiary_type !== 'supplier') return;
    const supplier = props.suppliers.find((s) => String(s.id) === String(id));
    if (supplier) {
        form.beneficiary_bank = supplier.bank_name || '';
        form.beneficiary_rib = supplier.rib || '';
    }
});
watch(() => form.beneficiary_type, () => { form.beneficiary_id = ''; });

function submit() {
    form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
    isEdit ? form.put(route('payment-orders.update', props.order.id)) : form.post(route('payment-orders.store'));
}
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-6">
        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2"><InputLabel :value="t('po.subject')" /><TextInput v-model="form.subject" maxlength="150" required class="mt-1 w-full" /><InputError :message="form.errors.subject" class="mt-1" /></div>
                <div><InputLabel :value="t('date')" /><TextInput v-model="form.order_date" type="date" required class="mt-1 w-full" /><InputError :message="form.errors.order_date" class="mt-1" /></div>
                <div><InputLabel :value="t('amount')" /><TextInput v-model="form.amount" type="number" step="0.01" min="0.01" required class="mt-1 w-full" /><InputError :message="form.errors.amount" class="mt-1" /></div>
                <div>
                    <InputLabel :value="t('category')" />
                    <select v-model="form.finance_category_id" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <option value="">—</option>
                        <option v-for="c in financeCategories" :key="c.id" :value="c.id">{{ categoryName(c) }}</option>
                    </select>
                    <InputError :message="form.errors.finance_category_id" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('source_register')" />
                    <select v-model="form.finance_account_id" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <option value="">—</option>
                        <option v-for="a in financeAccounts" :key="a.id" :value="a.id">{{ accountLabel(a) }}</option>
                    </select>
                    <InputError :message="form.errors.finance_account_id" class="mt-1" />
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('po.finance_hint') }}</p>
        </div>

        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('po.beneficiary') }}</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <select v-model="form.beneficiary_type" class="w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <option value="supplier">{{ t('po.beneficiary_type.supplier') }}</option>
                        <option value="user">{{ t('po.beneficiary_type.user') }}</option>
                        <option value="board_member">{{ t('po.beneficiary_type.board_member') }}</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <SearchableSelect v-model="form.beneficiary_id" :options="beneficiaryOptions" :placeholder="t('po.choose_beneficiary')" />
                    <InputError :message="form.errors.beneficiary_id" class="mt-1" />
                </div>
                <div><InputLabel :value="t('supplier.field.bank_name')" /><TextInput v-model="form.beneficiary_bank" class="mt-1 w-full" /></div>
                <div class="sm:col-span-2"><InputLabel :value="t('supplier.field.rib')" /><TextInput v-model="form.beneficiary_rib" dir="ltr" class="mt-1 w-full font-mono" /><InputError :message="form.errors.beneficiary_rib" class="mt-1" /></div>
            </div>
        </div>

        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('po.payment') }}</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel :value="t('payment_method')" />
                    <select v-model="form.payment_method" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <option value="bank">{{ t('bank_transfer') }}</option>
                        <option value="ccp">CCP</option>
                        <option value="cheque">{{ t('cheque') }}</option>
                        <option value="cash">{{ t('cash') }}</option>
                        <option value="baridimob">BaridiMob</option>
                        <option value="other">{{ t('other') }}</option>
                    </select>
                </div>
                <div><InputLabel :value="form.payment_method === 'cheque' ? t('tx.cheque_number') : t('po.payment_reference')" /><TextInput v-model="form.payment_reference" dir="ltr" class="mt-1 w-full" /></div>
                <div><InputLabel :value="t('po.payment_date')" /><TextInput v-model="form.payment_date" type="date" class="mt-1 w-full" /></div>
                <div><InputLabel :value="t('tx.invoice_number')" /><TextInput v-model="form.invoice_number" dir="ltr" class="mt-1 w-full" /></div>
                <div><InputLabel :value="t('tx.invoice_date')" /><TextInput v-model="form.invoice_date" type="date" class="mt-1 w-full" /></div>
                <div>
                    <InputLabel :value="t('tx.board_meeting')" />
                    <select v-model="form.board_meeting_id" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <option value="">—</option>
                        <option v-for="m in boardMeetings" :key="m.id" :value="m.id">{{ meetingLabel(m) }}</option>
                    </select>
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('po.observation')" />
                    <textarea v-model="form.observation" rows="2" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900"></textarea>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="isEdit ? route('payment-orders.show', order.id) : route('payment-orders.index')"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('po.save_draft') }}</PrimaryButton>
        </div>
    </form>
</template>
