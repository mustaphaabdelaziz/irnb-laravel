<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import Pagination from '@/Components/Pagination.vue';
import { useCan } from '@/Composables/useCan';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';

const props = defineProps({
    registers: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    openingFund: { type: Object, default: () => null },
    transfers: { type: Object, required: true },
});

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useFormatMoney();

const activeRegisters = computed(() => props.registers.filter((register) => register.is_active));
const treasuries = computed(() => props.registers.filter((register) => register.is_treasury));
const independentAccounts = computed(() => props.registers.filter((register) => !register.branch_id && !register.parent_account_id));
const totalBalance = computed(() => [...treasuries.value, ...independentAccounts.value]
    .reduce((sum, register) => sum + Number(register.current_balance || 0), 0));
const openingFundBalance = computed(() => props.openingFund ? Number(props.openingFund.current_balance || 0) : 0);
const { accountLabel: registerLabel, branchLabel } = useFinanceAccountLabel();
const categoryLabel = (category) => category?.localized_name || category?.name || t('uncategorized');
const branchTreasuries = computed(() => props.branches.map((branch) => {
    const treasury = treasuries.value.find((account) => Number(account.branch_id) === Number(branch.id));
    return {
        branch,
        treasury,
        categoryRegisters: treasury
            ? props.registers.filter((account) => Number(account.parent_account_id) === Number(treasury.id) && account.category_id)
            : [],
        otherRegisters: treasury
            ? props.registers.filter((account) => Number(account.parent_account_id) === Number(treasury.id) && !account.category_id)
            : [],
    };
}));

const editingId = ref(null);
const editingRegister = computed(() => props.registers.find((register) => register.id === editingId.value) || null);
const editingSystemRegister = computed(() => !!(editingRegister.value?.is_treasury || editingRegister.value?.category_id));
const registerForm = useForm({
    name: '',
    type: 'cash',
    branch_id: '',
    opening_balance: 0,
    account_number: '',
    currency: 'DZD',
    is_active: true,
    notes: '',
});

function resetRegisterForm() {
    editingId.value = null;
    registerForm.reset();
    registerForm.clearErrors();
}

function editRegister(register) {
    editingId.value = register.id;
    registerForm.name = register.name;
    registerForm.type = register.type;
    registerForm.branch_id = register.branch_id || '';
    registerForm.opening_balance = register.opening_balance;
    registerForm.account_number = register.account_number || '';
    registerForm.currency = register.currency || 'DZD';
    registerForm.is_active = !!register.is_active;
    registerForm.notes = register.notes || '';
    registerForm.clearErrors();
    document.getElementById('register-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function saveRegister() {
    const options = {
        preserveScroll: true,
        onSuccess: resetRegisterForm,
    };

    if (editingId.value) {
        registerForm.put(route('finance.accounts.update', editingId.value), options);
    } else {
        registerForm.post(route('finance.accounts.store'), options);
    }
}

function deleteRegister(register) {
    if (!window.confirm(t('confirm_delete_register'))) return;
    router.delete(route('finance.accounts.destroy', register.id), { preserveScroll: true });
}

const transferForm = useForm({
    from_account_id: '',
    to_account_id: '',
    amount: '',
    transfer_date: new Date().toISOString().slice(0, 10),
    reference: '',
    notes: '',
});

const sourceRegister = computed(() => {
    if (String(transferForm.from_account_id) === String(props.openingFund?.id)) {
        return props.openingFund;
    }
    return props.registers.find(
        (register) => String(register.id) === String(transferForm.from_account_id),
    );
});

function saveTransfer() {
    transferForm.post(route('finance.transfers.store'), {
        preserveScroll: true,
        onSuccess: () => transferForm.reset(
            'from_account_id',
            'to_account_id',
            'amount',
            'reference',
            'notes',
        ),
    });
}
</script>

<template>
    <Head :title="t('cash_registers')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <Link :href="route('finance.index')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800">
                        <Icon name="back" />
                    </Link>
                    <div>
                        <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('cash_registers') }}</h1>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('cash_registers_subtitle') }}</p>
                    </div>
                </div>
                <Link :href="route('transactions.create')" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700">
                    <Icon name="plus" /> {{ t('add_transaction') }}
                </Link>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-3">
                <div class="card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('total_cash') }}</p>
                    <p class="mt-2 text-3xl font-extrabold text-slate-900 dark:text-slate-100">{{ formatMoney(totalBalance) }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('active_registers') }}</p>
                    <p class="mt-2 text-3xl font-extrabold text-primary-700 dark:text-primary-400">{{ activeRegisters.length }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches') }}</p>
                    <p class="mt-2 text-3xl font-extrabold text-slate-900 dark:text-slate-100">{{ branches.length }}</p>
                </div>
            </section>

            <section v-if="openingFund" class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-amber-100 bg-amber-50/70 px-5 py-4 dark:border-amber-900 dark:bg-amber-950/40">
                    <div class="flex items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                            <Icon name="wallet" />
                        </span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wide text-amber-600 dark:text-amber-400">{{ t('opening_fund') }}</p>
                            <h3 class="text-base font-extrabold text-slate-900 dark:text-slate-100">{{ t('pre_register_money') }}</h3>
                        </div>
                    </div>
                    <div class="text-end">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ t('available_amount') }}</p>
                        <p class="text-2xl font-extrabold" :class="Number(openingFund.current_balance) >= 0 ? 'text-slate-900 dark:text-white' : 'text-rose-600'">
                            {{ formatMoney(openingFund.current_balance) }}
                        </p>
                        <button v-if="can('finance', 'edit')" type="button" class="mt-1 text-xs font-semibold text-primary-600 hover:text-primary-700" @click="editRegister(openingFund)">{{ t('adjust_opening_fund') }}</button>
                    </div>
                </div>
                <div class="bg-white px-5 py-3 dark:bg-slate-900">
                    <p class="mb-2 text-sm text-slate-600 dark:text-slate-300">{{ t('opening_fund_hint') }}</p>
                    <p v-if="openingFundBalance > 0" class="text-xs text-amber-600 dark:text-amber-400">
                        <strong>{{ t('tip') }}:</strong> {{ t('transfer_opening_fund_to_register') }}
                    </p>
                </div>
            </section>

            <section>
                <div class="mb-3">
                    <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ t('branch_treasuries') }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('treasury_rollup_hint') }}</p>
                </div>

                <div class="space-y-4">
                    <article v-for="group in branchTreasuries" :key="group.branch.id" class="card overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 bg-slate-50/70 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40">
                            <div class="flex items-center gap-3">
                                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-100 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">
                                    <Icon name="money" />
                                </span>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-primary-600 dark:text-primary-400">{{ t('branch_treasury') }}</p>
                                    <h3 class="text-base font-extrabold text-slate-900 dark:text-slate-100">{{ branchLabel(group.branch) }}</h3>
                                </div>
                            </div>
                            <div v-if="group.treasury" class="text-end">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ t('total_treasury_balance') }}</p>
                                <p class="text-2xl font-extrabold" :class="Number(group.treasury.current_balance) >= 0 ? 'text-slate-900 dark:text-white' : 'text-rose-600'">
                                    {{ formatMoney(group.treasury.current_balance) }}
                                </p>
                                <button v-if="can('finance', 'edit')" type="button" class="mt-1 text-xs font-semibold text-primary-600 hover:text-primary-700" @click="editRegister(group.treasury)">{{ t('edit_treasury') }}</button>
                            </div>
                        </div>

                        <div v-if="group.categoryRegisters.length" class="grid gap-px bg-slate-100 dark:bg-slate-800 sm:grid-cols-2 xl:grid-cols-3">
                            <div
                                v-for="register in group.categoryRegisters"
                                :key="register.id"
                                class="bg-white px-5 py-4 dark:bg-slate-900"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-slate-800 dark:text-slate-100">{{ categoryLabel(register.category) }}</p>
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ t('category_cash_register') }}</p>
                                    </div>
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">{{ t('active') }}</span>
                                </div>
                                <p class="mt-4 text-xl font-extrabold" :class="Number(register.current_balance) >= 0 ? 'text-slate-900 dark:text-white' : 'text-rose-600'">{{ formatMoney(register.current_balance) }}</p>
                                <div class="mt-2 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                    <span>{{ register.transactions_count }} {{ t('transactions').toLowerCase() }}</span>
                                    <button v-if="can('finance', 'edit')" type="button" class="font-semibold text-primary-600 hover:text-primary-700" @click="editRegister(register)">{{ t('edit') }}</button>
                                </div>
                            </div>
                        </div>
                        <p v-else class="px-5 py-7 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_category_registers') }}</p>

                        <div v-if="group.otherRegisters.length" class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">
                            <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">{{ t('other_accounts') }}</p>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="register in group.otherRegisters" :key="register.id" type="button" class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200" @click="editRegister(register)">
                                    {{ register.name }} · {{ formatMoney(register.current_balance) }}
                                </button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <div class="grid items-start gap-6 xl:grid-cols-2">
                <section v-if="can('finance', 'add')" class="card p-5">
                    <div class="mb-5 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ t('transfer_money') }}</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('transfer_money_hint') }}</p>
                        </div>
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <Icon name="arrow" />
                        </span>
                    </div>
                    <form class="space-y-4" @submit.prevent="saveTransfer">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="text-sm">
                                <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('from_register') }}</span>
                                <select v-model="transferForm.from_account_id" required class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700">
                                    <option value="" disabled>{{ t('select_cash_register') }}</option>
                                    <optgroup v-if="openingFund" :label="`${t('opening_fund')} (${formatMoney(openingFund.current_balance)})`">
                                        <option :value="openingFund.id">{{ t('opening_fund') }}</option>
                                    </optgroup>
                                    <optgroup :label="t('cash_registers')">
                                        <option v-for="register in activeRegisters" :key="register.id" :value="register.id">{{ registerLabel(register) }}</option>
                                    </optgroup>
                                </select>
                                <span v-if="sourceRegister" class="mt-1 block text-xs text-slate-500">{{ t('available') }}: {{ formatMoney(sourceRegister.current_balance) }}</span>
                                <InputError :message="transferForm.errors.from_account_id" class="mt-1" />
                            </label>
                            <label class="text-sm">
                                <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('to_register') }}</span>
                                <select v-model="transferForm.to_account_id" required class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700">
                                    <option value="" disabled>{{ t('select_cash_register') }}</option>
                                    <option v-for="register in activeRegisters.filter((item) => String(item.id) !== String(transferForm.from_account_id))" :key="register.id" :value="register.id">{{ registerLabel(register) }}</option>
                                </select>
                                <InputError :message="transferForm.errors.to_account_id" class="mt-1" />
                            </label>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="text-sm">
                                <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('amount') }}</span>
                                <input v-model="transferForm.amount" type="number" min="0.01" step="0.01" required class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700" />
                                <InputError :message="transferForm.errors.amount" class="mt-1" />
                            </label>
                            <label class="text-sm">
                                <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('date') }}</span>
                                <input v-model="transferForm.transfer_date" type="date" required class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700" />
                                <InputError :message="transferForm.errors.transfer_date" class="mt-1" />
                            </label>
                        </div>
                        <label class="block text-sm">
                            <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('reference') }}</span>
                            <input v-model="transferForm.reference" maxlength="120" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700" />
                            <InputError :message="transferForm.errors.reference" class="mt-1" />
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('notes') }}</span>
                            <textarea v-model="transferForm.notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700" />
                            <InputError :message="transferForm.errors.notes" class="mt-1" />
                        </label>
                        <div class="flex justify-end">
                            <button :disabled="transferForm.processing || activeRegisters.length < 2" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900">
                                {{ t('complete_transfer') }}
                            </button>
                        </div>
                    </form>
                </section>

                <section v-if="can('finance', editingId ? 'edit' : 'add')" id="register-form" class="card p-5">
                    <div class="mb-5 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ editingId ? t('edit_cash_register') : t('add_cash_register') }}</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ editingSystemRegister ? t('system_register_hint') : t('register_branch_hint') }}</p>
                        </div>
                        <button v-if="editingId" type="button" class="text-sm font-semibold text-slate-500 hover:text-slate-700" @click="resetRegisterForm">{{ t('cancel') }}</button>
                    </div>
                    <form class="space-y-4" @submit.prevent="saveRegister">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="text-sm">
                                <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('register_name') }}</span>
                                <input v-model="registerForm.name" required maxlength="120" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700" />
                                <InputError :message="registerForm.errors.name" class="mt-1" />
                            </label>
                            <label class="text-sm">
                                <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('branch') }}</span>
                                <select v-model="registerForm.branch_id" :disabled="editingSystemRegister" class="mt-1 w-full rounded-lg border-slate-300 disabled:opacity-60 dark:border-slate-700">
                                    <option value="">{{ t('club_wide') }}</option>
                                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branchLabel(branch) }}</option>
                                </select>
                                <InputError :message="registerForm.errors.branch_id" class="mt-1" />
                            </label>
                        </div>
                        <div v-if="editingRegister?.category" class="rounded-xl bg-primary-50 px-4 py-3 dark:bg-primary-500/10">
                            <p class="text-xs font-semibold uppercase tracking-wide text-primary-600 dark:text-primary-400">{{ t('member_category') }}</p>
                            <p class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">{{ categoryLabel(editingRegister.category) }}</p>
                        </div>
                        <p v-else class="text-xs text-slate-500 dark:text-slate-400">{{ t('category_registers_created_automatically') }}</p>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="text-sm">
                                <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('account_type') }}</span>
                                <select v-model="registerForm.type" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700">
                                    <option value="cash">{{ t('cash') }}</option>
                                    <option value="bank">{{ t('bank') }}</option>
                                    <option value="other">{{ t('other') }}</option>
                                </select>
                            </label>
                            <label class="text-sm">
                                <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('opening_balance') }}</span>
                                <input v-model="registerForm.opening_balance" type="number" step="0.01" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700" />
                                <InputError :message="registerForm.errors.opening_balance" class="mt-1" />
                            </label>
                            <label class="text-sm">
                                <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('currency') }}</span>
                                <input v-model="registerForm.currency" maxlength="8" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700" />
                            </label>
                        </div>
                        <label class="block text-sm">
                            <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('account_number') }}</span>
                            <input v-model="registerForm.account_number" maxlength="120" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700" />
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('notes') }}</span>
                            <textarea v-model="registerForm.notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700" />
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                            <input v-model="registerForm.is_active" type="checkbox" :disabled="editingSystemRegister" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 disabled:opacity-60" />
                            {{ t('active') }}
                        </label>
                        <div class="flex justify-end">
                            <button :disabled="registerForm.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">
                                {{ editingId ? t('save_changes') : t('add_cash_register') }}
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <section class="card overflow-hidden">
                <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800">
                    <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ t('transfer_history') }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('transfer_history_hint') }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                            <tr>
                                <th class="px-5 py-3 text-start font-semibold">{{ t('date') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ t('from_register') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ t('to_register') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ t('reference') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ t('recorded_by') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ t('amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="transfer in transfers.data" :key="transfer.id">
                                <td class="whitespace-nowrap px-5 py-3 text-slate-600 dark:text-slate-300">{{ transfer.transfer_date }}</td>
                                <td class="px-5 py-3 font-medium text-slate-800 dark:text-slate-100">{{ registerLabel(transfer.from_account) }}</td>
                                <td class="px-5 py-3 font-medium text-slate-800 dark:text-slate-100">{{ registerLabel(transfer.to_account) }}</td>
                                <td class="px-5 py-3 text-slate-500 dark:text-slate-400">
                                    <span>{{ transfer.reference || '-' }}</span>
                                    <p v-if="transfer.notes" class="max-w-xs truncate text-xs">{{ transfer.notes }}</p>
                                </td>
                                <td class="px-5 py-3 text-slate-500 dark:text-slate-400">{{ transfer.created_by?.name || '-' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-end font-bold text-slate-900 dark:text-slate-100">{{ formatMoney(transfer.amount) }}</td>
                            </tr>
                            <tr v-if="!transfers.data.length">
                                <td colspan="6" class="px-5 py-10 text-center text-slate-500">{{ t('no_transfers') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4"><Pagination :links="transfers" /></div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
