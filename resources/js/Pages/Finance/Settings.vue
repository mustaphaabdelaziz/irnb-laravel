<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import { useFormatMoney } from '@/Composables/useFormatMoney';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
    budgets: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const income = computed(() => props.categories.filter((c) => c.type === 'income'));
const expense = computed(() => props.categories.filter((c) => c.type === 'expense'));

/* ----- Fiscal years ----- */
const yearForm = useForm({ year: new Date().getFullYear(), opening_balance: 0, label: '' });
function addYear() {
    yearForm.post(route('finance.years.store'), { preserveScroll: true, onSuccess: () => yearForm.reset() });
}
const openingEdits = reactive({});
function saveOpening(y) {
    router.put(route('finance.years.update', y.id), { opening_balance: openingEdits[y.id] ?? y.opening_balance, label: y.label }, { preserveScroll: true });
}
function closeYear(y) { if (window.confirm(t('close_year_confirm'))) router.post(route('finance.years.close', y.id), {}, { preserveScroll: true }); }
function reopenYear(y) { if (window.confirm(t('reopen_year_confirm'))) router.post(route('finance.years.reopen', y.id), {}, { preserveScroll: true }); }

/* ----- Budgets ----- */
const budgetYearId = ref(props.years[0]?.id ?? null);
const budgetDraft = reactive({});
function plannedFor(catId) {
    if (budgetDraft[catId] !== undefined) return budgetDraft[catId];
    return props.budgets?.[budgetYearId.value]?.[catId] ?? 0;
}
function saveBudget() {
    const rows = props.categories.map((c) => ({ finance_category_id: c.id, planned_amount: Number(plannedFor(c.id)) || 0 }));
    router.put(route('finance.budget.update', budgetYearId.value), { budgets: rows }, { preserveScroll: true });
}

/* ----- Categories ----- */
const catForm = useForm({ type: 'income', name: '', code: '', color: '#10b981' });
function addCategory() {
    catForm.post(route('finance.categories.store'), { preserveScroll: true, onSuccess: () => catForm.reset('name', 'code') });
}
function deleteCategory(c) {
    if (window.confirm(t('confirm_delete'))) router.delete(route('finance.categories.destroy', c.id), { preserveScroll: true });
}

/* ----- Accounts ----- */
const accForm = useForm({ name: '', type: 'cash', opening_balance: 0, account_number: '' });
function addAccount() {
    accForm.post(route('finance.accounts.store'), { preserveScroll: true, onSuccess: () => accForm.reset() });
}
function deleteAccount(a) {
    if (window.confirm(t('confirm_delete'))) router.delete(route('finance.accounts.destroy', a.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('finance_settings')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('finance.index')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"><Icon name="back" /></Link>
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('finance_settings') }}</h1>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Fiscal years -->
            <section class="card overflow-hidden">
                <p class="border-b border-slate-100 px-5 py-3 text-sm font-bold text-slate-700 dark:border-slate-800 dark:text-slate-200">{{ t('fiscal_year') }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wide text-slate-400">
                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                <th class="px-5 py-2.5 text-start font-semibold">{{ t('fiscal_year') }}</th>
                                <th class="px-5 py-2.5 text-end font-semibold">{{ t('opening_balance') }}</th>
                                <th class="px-5 py-2.5 text-end font-semibold">{{ t('net') }}</th>
                                <th class="px-5 py-2.5 text-center font-semibold">{{ t('status') }}</th>
                                <th class="px-5 py-2.5 text-end font-semibold">{{ t('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="y in years" :key="y.id" class="border-b border-slate-50 dark:border-slate-800/50">
                                <td class="px-5 py-2.5 font-bold text-slate-900 dark:text-slate-100">{{ y.year }}</td>
                                <td class="px-5 py-2.5 text-end">
                                    <input
                                        v-if="y.status !== 'closed'" type="number" step="0.01"
                                        :value="openingEdits[y.id] ?? y.opening_balance"
                                        @input="openingEdits[y.id] = $event.target.value"
                                        @blur="saveOpening(y)"
                                        class="w-32 rounded-lg border-slate-200 bg-slate-50 py-1 text-end text-sm dark:border-slate-700 dark:bg-slate-800"
                                    />
                                    <span v-else>{{ formatMoney(y.opening_balance) }}</span>
                                </td>
                                <td class="px-5 py-2.5 text-end text-slate-600 dark:text-slate-300">{{ formatMoney((Number(y.total_income) || 0) - (Number(y.total_expense) || 0)) }}</td>
                                <td class="px-5 py-2.5 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-bold" :class="y.status === 'closed' ? 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'">{{ y.status === 'closed' ? t('closed') : t('open') }}</span>
                                </td>
                                <td class="px-5 py-2.5 text-end">
                                    <button v-if="y.status !== 'closed'" @click="closeYear(y)" class="text-xs font-bold text-slate-600 hover:text-slate-900 dark:text-slate-300">{{ t('close_year') }}</button>
                                    <button v-else @click="reopenYear(y)" class="text-xs font-bold text-primary-600 hover:text-primary-700">{{ t('reopen_year') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <form @submit.prevent="addYear" class="flex flex-wrap items-end gap-3 border-t border-slate-100 px-5 py-4 dark:border-slate-800">
                    <label class="text-sm"><span class="mb-1 block text-xs font-medium text-slate-500">{{ t('fiscal_year') }}</span>
                        <input v-model="yearForm.year" type="number" class="w-28 rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs font-medium text-slate-500">{{ t('opening_balance') }}</span>
                        <input v-model="yearForm.opening_balance" type="number" step="0.01" class="w-36 rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <button class="inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-primary-700"><Icon name="plus" /> {{ t('add_year') }}</button>
                </form>
            </section>

            <!-- Budgets -->
            <section class="card p-5">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('budget') }}</p>
                    <div class="flex items-center gap-2">
                        <select v-model="budgetYearId" class="rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="y in years" :key="y.id" :value="y.id">{{ y.year }}</option>
                        </select>
                        <button @click="saveBudget" class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-sm font-bold text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900">{{ t('save_budget') }}</button>
                    </div>
                </div>
                <div class="grid gap-x-8 gap-y-2 sm:grid-cols-2">
                    <div v-for="c in categories" :key="c.id" class="flex items-center justify-between gap-2">
                        <span class="flex min-w-0 items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: c.color || '#94a3b8' }"></span>
                            <span class="truncate">{{ c.name }}</span>
                            <span class="text-xs text-slate-400">({{ c.type === 'income' ? t('income') : t('expense') }})</span>
                        </span>
                        <input
                            type="number" step="0.01" min="0"
                            :value="plannedFor(c.id)" @input="budgetDraft[c.id] = $event.target.value"
                            class="w-28 rounded-lg border-slate-200 bg-slate-50 py-1 text-end text-sm dark:border-slate-700 dark:bg-slate-800"
                        />
                    </div>
                </div>
            </section>

            <!-- Chart of accounts -->
            <section class="card p-5">
                <p class="mb-4 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('chart_of_accounts') }}</p>
                <div class="grid gap-6 lg:grid-cols-2">
                    <div>
                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-emerald-600">{{ t('income_categories') }}</p>
                        <ul class="space-y-1.5">
                            <li v-for="c in income" :key="c.id" class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                                <span class="flex min-w-0 items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                    <span class="h-3 w-3 shrink-0 rounded-full" :style="{ background: c.color || '#94a3b8' }"></span>
                                    <span class="truncate">{{ c.name }}</span>
                                    <span v-if="c.transactions_count" class="text-xs text-slate-400">· {{ c.transactions_count }}</span>
                                </span>
                                <button @click="deleteCategory(c)" class="shrink-0 text-slate-300 hover:text-rose-500" :title="t('delete')"><Icon name="xcircle" /></button>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-rose-600">{{ t('expense_categories') }}</p>
                        <ul class="space-y-1.5">
                            <li v-for="c in expense" :key="c.id" class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                                <span class="flex min-w-0 items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                    <span class="h-3 w-3 shrink-0 rounded-full" :style="{ background: c.color || '#94a3b8' }"></span>
                                    <span class="truncate">{{ c.name }}</span>
                                    <span v-if="c.transactions_count" class="text-xs text-slate-400">· {{ c.transactions_count }}</span>
                                </span>
                                <button @click="deleteCategory(c)" class="shrink-0 text-slate-300 hover:text-rose-500" :title="t('delete')"><Icon name="xcircle" /></button>
                            </li>
                        </ul>
                    </div>
                </div>
                <form @submit.prevent="addCategory" class="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <select v-model="catForm.type" class="rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="income">{{ t('income') }}</option><option value="expense">{{ t('expense') }}</option>
                    </select>
                    <input v-model="catForm.name" :placeholder="t('category_name')" required class="rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="catForm.color" type="color" class="h-9 w-12 rounded-lg border-slate-200 dark:border-slate-700" />
                    <button class="inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-primary-700"><Icon name="plus" /> {{ t('add_category') }}</button>
                </form>
            </section>

            <!-- Accounts -->
            <section class="card p-5">
                <p class="mb-4 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('accounts') }}</p>
                <ul class="space-y-1.5">
                    <li v-for="a in accounts" :key="a.id" class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                        <span class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200"><Icon name="money" class="text-slate-400" /> {{ a.name }} <span class="text-xs text-slate-400">({{ t(a.type) }})</span></span>
                        <span class="flex items-center gap-3">
                            <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ formatMoney(a.current_balance) }}</span>
                            <button @click="deleteAccount(a)" class="text-slate-300 hover:text-rose-500" :title="t('delete')"><Icon name="xcircle" /></button>
                        </span>
                    </li>
                </ul>
                <form @submit.prevent="addAccount" class="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <input v-model="accForm.name" :placeholder="t('account')" required class="rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <select v-model="accForm.type" class="rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="cash">{{ t('cash') }}</option><option value="bank">{{ t('account') }}</option><option value="other">{{ t('other') }}</option>
                    </select>
                    <label class="text-sm"><span class="mb-1 block text-xs font-medium text-slate-500">{{ t('opening_balance') }}</span>
                        <input v-model="accForm.opening_balance" type="number" step="0.01" class="w-32 rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <button class="inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-primary-700"><Icon name="plus" /> {{ t('add_account') }}</button>
                </form>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
