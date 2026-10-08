<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import IconButton from '@/Components/IconButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { ref, computed } from 'vue';
import { useStatusLabel } from '@/Composables/useStatusLabel';

const { t } = useI18n();
const { statusLabel } = useStatusLabel();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    subscription: Object,
    playerSubscriptions: Array,
    categories: Array,
    availablePlayers: Array,
    stats: Object,
});

// "2025/2026", or the kind for a one-off charge that has no season.
const periodLabel = computed(() => props.subscription.year_label || t('subscription_kind_exceptional'));

// What each category actually pays, shown only when one of them overrides the default.
const categoryPrices = computed(() => {
    const cats = props.subscription.categories ?? [];
    if (!cats.some((c) => c.pivot?.amount_student != null || c.pivot?.amount_worker != null)) return [];
    return cats.map((c) => ({
        id: c.id,
        name: c.localized_name || c.name,
        student: c.pivot?.amount_student ?? props.subscription.amount_student,
        worker: c.pivot?.amount_worker ?? props.subscription.amount_worker,
    }));
});

// ── Tabs ─────────────────────────────────────────────────────────────
const activeTab = ref('all'); // all | paid | unpaid | partial

const filteredList = computed(() => {
    if (activeTab.value === 'paid')    return props.playerSubscriptions.filter(ps => ps.payment_status === 'paid' || ps.payment_status === 'exempt');
    if (activeTab.value === 'unpaid')  return props.playerSubscriptions.filter(ps => ps.payment_status === 'unpaid');
    if (activeTab.value === 'partial') return props.playerSubscriptions.filter(ps => ps.payment_status === 'partial');
    return props.playerSubscriptions;
});

// ── Assign all (by category) ─────────────────────────────────────────
const showAssignModal = ref(false);
const assignForm = useForm({ category_id: '', assign_all: true });

// Only the subscription's own categories can be assigned (all when it has none).
const assignableCategories = computed(() =>
    props.subscription.categories?.length ? props.subscription.categories : props.categories
);

function assign() {
    assignForm.post(route('subscriptions.assign', props.subscription.id), {
        onSuccess: () => { showAssignModal.value = false; assignForm.reset(); },
    });
}

// ── Assign single player ──────────────────────────────────────────────
const showAddPlayerModal = ref(false);
const playerSearch = ref('');
const addPlayerForm = useForm({ player_id: '' });

const filteredAvailable = computed(() => {
    const q = playerSearch.value.trim().toLowerCase();
    if (!q) return props.availablePlayers ?? [];
    return (props.availablePlayers ?? []).filter(p =>
        `${p.fullname} ${p.membership_id}`.toLowerCase().includes(q)
    );
});

function addSinglePlayer() {
    addPlayerForm.post(route('subscriptions.assignOne', props.subscription.id), {
        onSuccess: () => {
            showAddPlayerModal.value = false;
            playerSearch.value = '';
            addPlayerForm.reset();
        },
    });
}

// ── Export ────────────────────────────────────────────────────────────
// The current tab's list; ExportMenu adds the format.
const exportHref = computed(() => route('subscriptions.export', { subscription: props.subscription.id, filter: activeTab.value }));

// ── Print ─────────────────────────────────────────────────────────────
function printList() {
    window.print();
}

// ── Helpers ───────────────────────────────────────────────────────────
const statusColor = (s) => {
    if (s === 'paid')    return 'emerald';
    if (s === 'partial') return 'amber';
    if (s === 'exempt')  return 'slate';
    return 'rose';
};

const tabCount = (tab) => {
    if (tab === 'paid')    return props.stats?.paid_count ?? 0;
    if (tab === 'unpaid')  return props.stats?.unpaid_count ?? 0;
    if (tab === 'partial') return props.stats?.partial_count ?? 0;
    return props.stats?.total ?? 0;
};
</script>

<template>
    <Head :title="subscription.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <Link :href="route('subscriptions.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </Link>
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ subscription.name }} ({{ periodLabel }})</h1>
                    <div v-if="subscription.branches?.length" class="flex flex-wrap gap-1">
                        <Badge v-for="b in subscription.branches" :key="b.id" :label="b.localized_name || b.name" color="emerald" />
                    </div>
                </div>
                <div class="no-print flex flex-wrap gap-2">
                    <!-- Add single player -->
                    <IconButton icon="plus" :label="t('add_player')" variant="success" @click="showAddPlayerModal = true" />
                    <!-- Assign all -->
                    <IconButton icon="players" :label="t('assign_subscription')" variant="primary" @click="showAssignModal = true" />
                    <!-- Export (current tab) -->
                    <ExportMenu :href="exportHref" :label="t('export')" />
                    <!-- Print -->
                    <IconButton icon="print" :label="t('print')" @click="printList" />
                    <IconButton :href="route('subscriptions.edit', subscription.id)" icon="pencil" :label="t('edit')" />
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Stats cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                <div class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('student_pricing') }}</p>
                    <p class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">{{ formatMoney(subscription.amount_student) }}</p>
                </div>
                <div class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('worker_pricing') }}</p>
                    <p class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">{{ formatMoney(subscription.amount_worker) }}</p>
                </div>
                <div class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('total_players') }}</p>
                    <p class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">{{ stats?.total ?? 0 }}</p>
                </div>
                <div class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-emerald-200">
                    <p class="text-xs text-emerald-600">{{ t('paid') }}</p>
                    <p class="mt-1 text-xl font-bold text-emerald-700">{{ stats?.paid_count ?? 0 }}</p>
                </div>
                <div class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-rose-200">
                    <p class="text-xs text-rose-600">{{ t('unpaid') }}</p>
                    <p class="mt-1 text-xl font-bold text-rose-700">{{ stats?.unpaid_count ?? 0 }}</p>
                </div>
                <div class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('collected') }}</p>
                    <p class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">{{ formatMoney(stats?.total_collected ?? 0) }}</p>
                </div>
            </div>

            <!-- Category prices (only when a category overrides the default) -->
            <div v-if="categoryPrices.length" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-900 dark:border-slate-800 dark:text-slate-100">{{ t('category_prices') }}</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                            <tr>
                                <th class="px-5 py-2 text-start font-semibold">{{ t('category') }}</th>
                                <th class="px-5 py-2 text-end font-semibold">{{ t('student_pricing') }}</th>
                                <th class="px-5 py-2 text-end font-semibold">{{ t('worker_pricing') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="row in categoryPrices" :key="row.id">
                                <td class="px-5 py-2 font-medium text-slate-700 dark:text-slate-200">{{ row.name }}</td>
                                <td class="px-5 py-2 text-end">{{ formatMoney(row.student) }}</td>
                                <td class="px-5 py-2 text-end">{{ formatMoney(row.worker) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Payment percentage bar -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('payment_rate') }}</span>
                    <span class="text-lg font-bold" :class="stats?.paid_percentage >= 80 ? 'text-emerald-600' : stats?.paid_percentage >= 50 ? 'text-amber-600' : 'text-rose-600'">
                        {{ stats?.paid_percentage ?? 0 }}%
                    </span>
                </div>
                <div class="h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full transition-all duration-500"
                        :class="stats?.paid_percentage >= 80 ? 'bg-emerald-500' : stats?.paid_percentage >= 50 ? 'bg-amber-500' : 'bg-rose-500'"
                        :style="{ width: (stats?.paid_percentage ?? 0) + '%' }">
                    </div>
                </div>
                <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ t('players_paid_of', { paid: stats?.paid_count ?? 0, total: stats?.total ?? 0 }) }}</p>
            </div>

            <!-- Tabs + Player subscriptions table -->
            <!-- No overflow-hidden here: it would clip the export menu when the list is short; the table wrapper rounds its own bottom corners. -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 print-area">
                <!-- Tab bar -->
                <div class="no-print flex border-b border-slate-100 dark:border-slate-800">
                    <button v-for="tab in ['all','paid','unpaid','partial']" :key="tab"
                        @click="activeTab = tab"
                        class="relative px-5 py-3 text-sm font-medium transition-colors"
                        :class="activeTab === tab
                            ? 'text-primary-600 after:absolute after:bottom-0 after:left-0 after:right-0 after:h-0.5 after:bg-primary-600'
                            : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'">
                        {{ t(tab) }}
                        <span class="ml-1.5 rounded-full px-1.5 py-0.5 text-xs"
                            :class="activeTab === tab ? 'bg-primary-100 text-primary-700' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'">
                            {{ tabCount(tab) }}
                        </span>
                    </button>
                    <!-- Export current tab -->
                    <div class="no-print ml-auto flex items-center pr-4 gap-2">
                        <ExportMenu :href="exportHref" :label="`${t('export')} ${t(activeTab)}`" />
                    </div>
                </div>

                <!-- Print header (only shown when printing) -->
                <div class="hidden px-5 py-4 print:block">
                    <h2 class="text-lg font-bold">{{ subscription.name }} — {{ periodLabel }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t(activeTab) }} — {{ new Date().toLocaleDateString() }}</p>
                </div>

                <div v-if="!filteredList.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">
                    {{ t('no_players_in_list') }}
                </div>
                <div v-else class="overflow-x-auto rounded-b-2xl">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">#</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('player') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('category') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('amount') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('amount_paid') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('remaining') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                                <th class="no-print px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="(ps, idx) in filteredList" :key="ps.id" class="hover:bg-slate-50/60 dark:hover:bg-slate-800">
                                <td class="px-4 py-3 text-sm text-slate-400 dark:text-slate-500">{{ idx + 1 }}</td>
                                <td class="px-4 py-3">
                                    <Link :href="route('players.show', ps.player_id)" class="text-sm font-medium text-primary-600 hover:text-primary-800">
                                        {{ ps.player?.fullname }}
                                    </Link>
                                    <p class="text-xs text-slate-400 dark:text-slate-500">{{ ps.player?.membership_id }}</p>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ ps.player?.category?.localized_name || ps.player?.category?.name || '-' }}</td>
                                <td class="px-4 py-3 text-end text-sm">{{ formatMoney(ps.amount_owed) }}</td>
                                <td class="px-4 py-3 text-end text-sm text-emerald-700">{{ formatMoney(ps.amount_paid) }}</td>
                                <td class="px-4 py-3 text-end text-sm font-semibold"
                                    :class="ps.remaining_amount > 0 ? 'text-rose-700' : 'text-slate-400 dark:text-slate-500'">
                                    {{ formatMoney(ps.remaining_amount) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge :label="statusLabel('payment', ps.payment_status)" :color="statusColor(ps.payment_status)" />
                                </td>
                                <td class="no-print px-4 py-3 text-end">
                                    <IconButton :href="route('players.show', ps.player_id)" icon="eye" :label="t('view')" plain size="sm" />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <td colspan="3" class="px-4 py-3 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    {{ t('total') }} ({{ t('players_count_paren', { count: filteredList.length }) }})
                                </td>
                                <td class="px-4 py-3 text-end text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    {{ formatMoney(filteredList.reduce((s, ps) => s + parseFloat(ps.amount_owed || 0), 0)) }}
                                </td>
                                <td class="px-4 py-3 text-end text-sm font-semibold text-emerald-700">
                                    {{ formatMoney(filteredList.reduce((s, ps) => s + parseFloat(ps.amount_paid || 0), 0)) }}
                                </td>
                                <td class="px-4 py-3 text-end text-sm font-semibold text-rose-700">
                                    {{ formatMoney(filteredList.reduce((s, ps) => s + parseFloat(ps.remaining_amount || 0), 0)) }}
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Assign all modal ─────────────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="showAssignModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" @click.self="showAssignModal = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('assign_subscription') }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ subscription.name }} ({{ periodLabel }})</p>
                    <form @submit.prevent="assign" class="mt-4 space-y-4">
                        <div>
                            <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('category') }}</label>
                            <select v-model="assignForm.category_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">{{ t('all_categories') }}</option>
                                <option v-for="cat in assignableCategories" :key="cat.id" :value="cat.id">{{ cat.localized_name || cat.name }}</option>
                            </select>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="showAssignModal = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <PrimaryButton :disabled="assignForm.processing">{{ t('assign') }}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- ── Add single player modal ─────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="showAddPlayerModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" @click.self="showAddPlayerModal = false">
                <div class="w-full max-w-lg rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('add_player_to_subscription') }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ subscription.name }} ({{ periodLabel }})</p>

                    <!-- Search -->
                    <div class="mt-4">
                        <input v-model="playerSearch" type="text" :placeholder="t('search_name_or_id')"
                            class="w-full rounded-lg border border-slate-300 dark:border-slate-700 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
                    </div>

                    <!-- Player list -->
                    <div class="mt-3 max-h-64 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-800">
                        <div v-if="!filteredAvailable.length" class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                            {{ t('no_available_players') }}
                        </div>
                        <label v-for="player in filteredAvailable" :key="player.id"
                            class="flex cursor-pointer items-center gap-3 px-4 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800"
                            :class="addPlayerForm.player_id === player.id ? 'bg-primary-50' : ''">
                            <input type="radio" :value="player.id" v-model="addPlayerForm.player_id" class="text-primary-600" />
                            <div>
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ player.fullname }}</p>
                                <p class="text-xs text-slate-400 dark:text-slate-500">
                                    {{ player.membership_id }}
                                    <span v-if="player.category" class="ml-2">· {{ player.category?.localized_name || player.category?.name }}</span>
                                    <span class="ml-2">· {{ player.is_student ? t('student') : t('worker') }}</span>
                                </p>
                            </div>
                        </label>
                    </div>

                    <div v-if="addPlayerForm.player_id" class="mt-3 rounded-lg bg-slate-50 dark:bg-slate-950 px-4 py-2 text-sm text-slate-600 dark:text-slate-300">
                        {{ t('amount_will_be') }}
                        <strong>{{ formatMoney(availablePlayers?.find(p => p.id === addPlayerForm.player_id)?.price ?? 0) }}</strong>
                        ({{ availablePlayers?.find(p => p.id === addPlayerForm.player_id)?.is_student ? t('student') : t('worker') }} {{ t('rate') }})
                    </div>

                    <div class="mt-4 flex justify-end gap-3">
                        <button type="button" @click="showAddPlayerModal = false; addPlayerForm.reset(); playerSearch = ''"
                            class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">
                            {{ t('cancel') }}
                        </button>
                        <PrimaryButton :disabled="!addPlayerForm.player_id || addPlayerForm.processing" @click="addSinglePlayer">
                            {{ t('add_to_subscription') }}
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>

<style>
@media print {
    nav, header, .no-print, button, a[href] { display: none !important; }
    .print-area { display: block !important; }
    body { font-size: 12px; }
}
</style>
