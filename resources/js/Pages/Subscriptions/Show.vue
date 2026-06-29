<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { ref, computed } from 'vue';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    subscription: Object,
    playerSubscriptions: Array,
    categories: Array,
    availablePlayers: Array,
    stats: Object,
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
const assignForm = useForm({ category_id: '' });

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
        `${p.firstname} ${p.lastname} ${p.membership_id}`.toLowerCase().includes(q)
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
function exportExcel(filter) {
    window.location.href = route('subscriptions.export', props.subscription.id) + '?filter=' + filter;
}

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
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ subscription.name }} ({{ subscription.year }})</h1>
                </div>
                <div class="no-print flex flex-wrap gap-2">
                    <!-- Add single player -->
                    <button @click="showAddPlayerModal = true"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700 transition-colors">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add Player
                    </button>
                    <!-- Assign all -->
                    <button @click="showAssignModal = true"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 transition-colors">
                        {{ t('assign_subscription') }}
                    </button>
                    <!-- Export dropdown -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="exportExcel(activeTab)"
                            class="inline-flex items-center gap-2 rounded-lg border border-emerald-300 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-emerald-700 shadow-sm hover:bg-emerald-50 transition-colors">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Export Excel
                        </button>
                    </div>
                    <!-- Print -->
                    <button @click="printList"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        Print
                    </button>
                    <Link :href="route('subscriptions.edit', subscription.id)"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        {{ t('edit') }}
                    </Link>
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
                    <p class="text-xs text-emerald-600">Paid</p>
                    <p class="mt-1 text-xl font-bold text-emerald-700">{{ stats?.paid_count ?? 0 }}</p>
                </div>
                <div class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-rose-200">
                    <p class="text-xs text-rose-600">Unpaid</p>
                    <p class="mt-1 text-xl font-bold text-rose-700">{{ stats?.unpaid_count ?? 0 }}</p>
                </div>
                <div class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">Collected</p>
                    <p class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">{{ formatMoney(stats?.total_collected ?? 0) }}</p>
                </div>
            </div>

            <!-- Payment percentage bar -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-slate-700 dark:text-slate-200">Payment rate</span>
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
                <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ stats?.paid_count ?? 0 }} of {{ stats?.total ?? 0 }} players have paid</p>
            </div>

            <!-- Tabs + Player subscriptions table -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 print-area">
                <!-- Tab bar -->
                <div class="no-print flex border-b border-slate-100 dark:border-slate-800">
                    <button v-for="tab in ['all','paid','unpaid','partial']" :key="tab"
                        @click="activeTab = tab"
                        class="relative px-5 py-3 text-sm font-medium transition-colors"
                        :class="activeTab === tab
                            ? 'text-primary-600 after:absolute after:bottom-0 after:left-0 after:right-0 after:h-0.5 after:bg-primary-600'
                            : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'">
                        {{ tab.charAt(0).toUpperCase() + tab.slice(1) }}
                        <span class="ml-1.5 rounded-full px-1.5 py-0.5 text-xs"
                            :class="activeTab === tab ? 'bg-primary-100 text-primary-700' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'">
                            {{ tabCount(tab) }}
                        </span>
                    </button>
                    <!-- Export current tab -->
                    <div class="no-print ml-auto flex items-center pr-4 gap-2">
                        <button @click="exportExcel(activeTab)"
                            class="inline-flex items-center gap-1 rounded-md border border-slate-200 dark:border-slate-800 px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Export {{ activeTab }}
                        </button>
                    </div>
                </div>

                <!-- Print header (only shown when printing) -->
                <div class="hidden px-5 py-4 print:block">
                    <h2 class="text-lg font-bold">{{ subscription.name }} — {{ subscription.year }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ activeTab.toUpperCase() }} players — Printed {{ new Date().toLocaleDateString() }}</p>
                </div>

                <div v-if="!filteredList.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">
                    No players in this list.
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">#</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('player') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Category</th>
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
                                        {{ ps.player?.lastname }} {{ ps.player?.firstname }}
                                        <span v-if="ps.player?.nickname" class="text-slate-400 dark:text-slate-500 text-xs">({{ ps.player.nickname }})</span>
                                    </Link>
                                    <p class="text-xs text-slate-400 dark:text-slate-500">{{ ps.player?.membership_id }}</p>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ ps.player?.category?.name ?? '-' }}</td>
                                <td class="px-4 py-3 text-end text-sm">{{ formatMoney(ps.amount_owed) }}</td>
                                <td class="px-4 py-3 text-end text-sm text-emerald-700">{{ formatMoney(ps.amount_paid) }}</td>
                                <td class="px-4 py-3 text-end text-sm font-semibold"
                                    :class="ps.remaining_amount > 0 ? 'text-rose-700' : 'text-slate-400 dark:text-slate-500'">
                                    {{ formatMoney(ps.remaining_amount) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge :label="ps.payment_status" :color="statusColor(ps.payment_status)" />
                                </td>
                                <td class="no-print px-4 py-3 text-end">
                                    <Link :href="route('players.show', ps.player_id)"
                                        class="text-xs text-slate-400 dark:text-slate-500 hover:text-primary-600">
                                        View →
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <td colspan="3" class="px-4 py-3 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Total ({{ filteredList.length }} players)
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
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ subscription.name }} ({{ subscription.year }})</p>
                    <form @submit.prevent="assign" class="mt-4 space-y-4">
                        <div>
                            <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('category') }}</label>
                            <select v-model="assignForm.category_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">{{ t('all_categories') }}</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
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
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Add Player to Subscription</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ subscription.name }} ({{ subscription.year }})</p>

                    <!-- Search -->
                    <div class="mt-4">
                        <input v-model="playerSearch" type="text" placeholder="Search by name or ID…"
                            class="w-full rounded-lg border border-slate-300 dark:border-slate-700 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
                    </div>

                    <!-- Player list -->
                    <div class="mt-3 max-h-64 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-800">
                        <div v-if="!filteredAvailable.length" class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                            No available players found.
                        </div>
                        <label v-for="player in filteredAvailable" :key="player.id"
                            class="flex cursor-pointer items-center gap-3 px-4 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800"
                            :class="addPlayerForm.player_id === player.id ? 'bg-primary-50' : ''">
                            <input type="radio" :value="player.id" v-model="addPlayerForm.player_id" class="text-primary-600" />
                            <div>
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ player.lastname }} {{ player.firstname }}
                                    <span v-if="player.nickname" class="text-slate-400 dark:text-slate-500 text-xs">({{ player.nickname }})</span>
                                </p>
                                <p class="text-xs text-slate-400 dark:text-slate-500">
                                    {{ player.membership_id }}
                                    <span v-if="player.category" class="ml-2">· {{ player.category?.name }}</span>
                                    <span class="ml-2">· {{ player.is_student ? 'Student' : 'Worker' }}</span>
                                </p>
                            </div>
                        </label>
                    </div>

                    <div v-if="addPlayerForm.player_id" class="mt-3 rounded-lg bg-slate-50 dark:bg-slate-950 px-4 py-2 text-sm text-slate-600 dark:text-slate-300">
                        Amount will be:
                        <strong>{{ formatMoney(availablePlayers?.find(p => p.id === addPlayerForm.player_id)?.is_student ? subscription.amount_student : subscription.amount_worker) }}</strong>
                        ({{ availablePlayers?.find(p => p.id === addPlayerForm.player_id)?.is_student ? 'student' : 'worker' }} rate)
                    </div>

                    <div class="mt-4 flex justify-end gap-3">
                        <button type="button" @click="showAddPlayerModal = false; addPlayerForm.reset(); playerSearch = ''"
                            class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">
                            {{ t('cancel') }}
                        </button>
                        <PrimaryButton :disabled="!addPlayerForm.player_id || addPlayerForm.processing" @click="addSinglePlayer">
                            Add to Subscription
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
