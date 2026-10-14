<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import CaisseFormModal from '@/Components/Finance/CaisseFormModal.vue';
import MoveMoneyModal from '@/Components/Finance/MoveMoneyModal.vue';
import { useCan } from '@/Composables/useCan';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';

const props = defineProps({
    coffres: { type: Array, default: () => [] },
    clubLevel: { type: Array, default: () => [] },
    openingFund: { type: Object, default: null },
    totals: { type: Object, default: () => ({ cash: 0, active: 0 }) },
    branches: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    transfers: { type: Object, required: true },
});

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useFormatMoney();
const { accountLabel, branchLabel, caisseName, isLegacyAutoName } = useFinanceAccountLabel();

// Every caisse inside a coffre, so the form can grey out categories already taken in a branch.
const caisseLinks = computed(() => props.coffres.flatMap((g) => g.caisses.map((c) => ({ id: c.id, branch_id: c.branch_id, category_id: c.category_id }))));

// Every active account money can move between, labelled for the pickers.
const movable = computed(() => {
    const list = [];
    if (props.openingFund) list.push(props.openingFund);
    props.coffres.forEach((g) => { if (g.coffre) list.push({ ...g.coffre, current_balance: g.own_balance }); list.push(...g.caisses); });
    list.push(...props.clubLevel);
    return list.filter((a) => a.is_active).map((a) => ({ id: a.id, label: accountLabel(a), balance: Number(a.current_balance), is_opening_fund: !!a.is_opening_fund }));
});

const formOpen = ref(false);
const formAccount = ref(null);
const presetBranchId = ref(null);
function addCaisse(branchId = null) { formAccount.value = null; presetBranchId.value = branchId; formOpen.value = true; }
function editAccount(account) { formAccount.value = account; presetBranchId.value = null; formOpen.value = true; }

const moveOpen = ref(false);
const moveFrom = ref(null);
function moveMoney(accountId = null) { moveFrom.value = accountId; moveOpen.value = true; }

const deleting = ref(null);
function confirmDelete() {
    router.delete(route('finance.accounts.destroy', deleting.value.id), { preserveScroll: true, onFinish: () => { deleting.value = null; } });
}

// Only an account that was never used (no transaction, no move) and holds no money can be deleted.
const deletable = (account) => account.transactions_count === 0 && !account.transfers_count
    && Math.abs(Number(account.current_balance || 0)) < 0.005;

const balanceClass = (value) => (Number(value) >= 0 ? 'text-slate-900 dark:text-white' : 'text-rose-600 dark:text-rose-400');
const categoryLabel = (category) => category?.localized_name || category?.name;
const formatDate = (value) => (value ? new Date(`${value}T00:00:00`).toLocaleDateString(document.documentElement.lang || undefined) : '');
</script>

<template>
    <Head :title="t('cash_registers')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div class="flex min-w-0 items-center gap-2">
                    <Link :href="route('finance.index')" :aria-label="t('finance')" class="shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800">
                        <Icon name="back" class="rtl:rotate-180" />
                    </Link>
                    <div class="min-w-0">
                        <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100 sm:text-xl">{{ t('cash_registers') }}</h1>
                        <p class="hidden text-xs text-slate-500 dark:text-slate-400 sm:block">{{ t('caisses.subtitle') }}</p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <IconButton v-if="can('finance', 'add') && movable.length > 1" icon="arrow" icon-class="rtl:rotate-180" :label="t('caisses.move_money')" @click="moveMoney()" />
                    <IconButton v-if="can('finance', 'add')" icon="plus" :label="t('caisses.add')" variant="primary" @click="addCaisse()" />
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Totals -->
            <section class="grid gap-4 sm:grid-cols-2">
                <div class="card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('total_cash') }}</p>
                    <p class="mt-2 break-words text-2xl font-extrabold tabular-nums sm:text-3xl" :class="balanceClass(totals.cash)">{{ formatMoney(totals.cash) }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('active_registers') }}</p>
                    <p class="mt-2 break-words text-2xl font-extrabold tabular-nums text-primary-700 dark:text-primary-400 sm:text-3xl">{{ totals.active }}</p>
                </div>
            </section>

            <!-- Money from before the app, still waiting to be placed -->
            <section v-if="openingFund" class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl bg-amber-50 px-5 py-4 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:ring-amber-900">
                <Icon name="alert" class="shrink-0 text-lg text-amber-600 dark:text-amber-400" />
                <p class="min-w-0 flex-1 text-sm font-medium text-amber-900 dark:text-amber-200">{{ t('caisses.opening_fund_left') }}</p>
                <p class="break-words text-lg font-extrabold tabular-nums" :class="balanceClass(openingFund.current_balance)">{{ formatMoney(openingFund.current_balance) }}</p>
                <IconButton v-if="can('finance', 'add')" icon="arrow" icon-class="rtl:rotate-180" :label="t('caisses.move_money')" variant="primary" size="sm" @click="moveMoney(openingFund.id)" />
            </section>

            <!-- One card per branch: its coffre and the caisses inside it -->
            <article v-for="g in coffres" :key="g.branch.id" class="card overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 bg-slate-50/70 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-100 text-xl text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">
                            <Icon name="money" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="break-words text-base font-extrabold text-slate-900 dark:text-slate-100">{{ t('caisses.coffre_of', { branch: branchLabel(g.branch) }) }}</h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ t('caisses.coffre_own_cash') }}:
                                <span class="font-semibold tabular-nums" :class="balanceClass(g.own_balance)">{{ formatMoney(g.own_balance) }}</span>
                            </p>
                        </div>
                    </div>
                    <div class="ms-auto text-end">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ t('caisses.branch_total') }}</p>
                        <p class="break-words text-2xl font-extrabold tabular-nums" :class="balanceClass(g.coffre?.current_balance ?? 0)">{{ formatMoney(g.coffre?.current_balance ?? 0) }}</p>
                        <div class="mt-1 flex items-center justify-end gap-1">
                            <IconButton v-if="g.coffre && g.coffre.is_active && can('finance', 'add')" icon="arrow" icon-class="rtl:rotate-180" :label="t('caisses.move_money')" plain size="sm" @click="moveMoney(g.coffre.id)" />
                            <IconButton v-if="can('finance', 'add')" icon="plus" :label="t('caisses.add')" variant="primary" plain size="sm" @click="addCaisse(g.branch.id)" />
                            <IconButton v-if="g.coffre && can('finance', 'edit')" icon="pencil" :label="t('caisses.edit_coffre')" plain size="sm" @click="editAccount(g.coffre)" />
                        </div>
                    </div>
                </div>

                <ul v-if="g.caisses.length" class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="c in g.caisses" :key="c.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="break-words text-sm font-bold text-slate-800 dark:text-slate-100">{{ caisseName(c) }}</p>
                                <span v-if="c.category && !isLegacyAutoName(c)" class="rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">{{ categoryLabel(c.category) }}</span>
                                <span v-if="!c.is_active" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('caisses.inactive') }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ c.transactions_count }} {{ t('transactions').toLowerCase() }}</p>
                        </div>
                        <p class="ms-auto break-words text-end text-base font-extrabold tabular-nums" :class="balanceClass(c.current_balance)">{{ formatMoney(c.current_balance) }}</p>
                        <div class="flex items-center gap-1">
                            <IconButton v-if="c.is_active && can('finance', 'add')" icon="arrow" icon-class="rtl:rotate-180" :label="t('caisses.move_money')" plain size="sm" @click="moveMoney(c.id)" />
                            <IconButton v-if="can('finance', 'edit')" icon="pencil" :label="t('caisses.edit')" plain size="sm" @click="editAccount(c)" />
                            <IconButton v-if="deletable(c) && can('finance', 'delete')" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleting = c" />
                        </div>
                    </li>
                </ul>
                <p v-else class="px-5 py-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('caisses.no_caisses') }}</p>
            </article>

            <!-- Accounts of the whole club (bank account…) -->
            <section v-if="clubLevel.length" class="card overflow-hidden">
                <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800">
                    <h2 class="text-base font-extrabold text-slate-900 dark:text-slate-100">{{ t('caisses.club_level') }}</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('caisses.club_level_hint') }}</p>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="a in clubLevel" :key="a.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="break-words text-sm font-bold text-slate-800 dark:text-slate-100">{{ accountLabel(a) }}</p>
                                <span v-if="a.type === 'bank'" class="rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">{{ t('bank') }}</span>
                                <span v-if="!a.is_active" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('caisses.inactive') }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ a.transactions_count }} {{ t('transactions').toLowerCase() }}</p>
                        </div>
                        <p class="ms-auto break-words text-end text-base font-extrabold tabular-nums" :class="balanceClass(a.current_balance)">{{ formatMoney(a.current_balance) }}</p>
                        <div class="flex items-center gap-1">
                            <IconButton v-if="a.is_active && can('finance', 'add')" icon="arrow" icon-class="rtl:rotate-180" :label="t('caisses.move_money')" plain size="sm" @click="moveMoney(a.id)" />
                            <IconButton v-if="can('finance', 'edit')" icon="pencil" :label="t('caisses.edit_account')" plain size="sm" @click="editAccount(a)" />
                            <IconButton v-if="deletable(a) && can('finance', 'delete')" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleting = a" />
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Money moves between caisses -->
            <section class="card overflow-hidden">
                <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800">
                    <h2 class="text-base font-extrabold text-slate-900 dark:text-slate-100">{{ t('caisses.history') }}</h2>
                </div>
                <div v-if="transfers.data.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                            <tr>
                                <th class="px-5 py-3 text-start font-semibold">{{ t('date') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">
                                    <span class="inline-flex items-center gap-1">{{ t('caisses.from') }} <Icon name="arrow" class="rtl:rotate-180" /> {{ t('caisses.to') }}</span>
                                </th>
                                <th class="px-5 py-3 text-end font-semibold">{{ t('amount') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ t('notes') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ t('recorded_by') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="tr in transfers.data" :key="tr.id">
                                <td class="whitespace-nowrap px-5 py-3 text-slate-600 dark:text-slate-300">{{ formatDate(tr.transfer_date) }}</td>
                                <td class="px-5 py-3 font-medium text-slate-800 dark:text-slate-100">
                                    <span class="inline-flex flex-wrap items-center gap-1">
                                        <span>{{ accountLabel(tr.from_account) }}</span>
                                        <Icon name="arrow" class="text-slate-400 rtl:rotate-180" />
                                        <span>{{ accountLabel(tr.to_account) }}</span>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-end font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ formatMoney(tr.amount) }}</td>
                                <td class="px-5 py-3 text-xs text-slate-500 dark:text-slate-400">
                                    <p v-if="tr.reference">{{ tr.reference }}</p>
                                    <p v-if="tr.notes" class="max-w-xs break-words">{{ tr.notes }}</p>
                                    <span v-if="!tr.reference && !tr.notes">-</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-slate-500 dark:text-slate-400">{{ tr.created_by?.name || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('caisses.no_moves') }}</p>
                <div class="px-4"><Pagination :links="transfers" /></div>
            </section>
        </div>

        <CaisseFormModal :show="formOpen" :account="formAccount" :preset-branch-id="presetBranchId" :branches="branches" :categories="categories" :caisses="caisseLinks" @close="formOpen = false" />
        <MoveMoneyModal :show="moveOpen" :accounts="movable" :from-id="moveFrom" @close="moveOpen = false" />
        <ConfirmModal :show="!!deleting" :title="deleting?.branch_id ? t('caisses.delete_title') : t('caisses.delete_account_title')" :message="t('caisses.delete_message')" :confirm-label="t('delete')" @confirm="confirmDelete" @cancel="deleting = null" />
    </AuthenticatedLayout>
</template>
