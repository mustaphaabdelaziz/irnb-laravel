<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatStrip from '@/Components/Dashboard/StatStrip.vue';
import Pagination from '@/Components/Pagination.vue';
import Badge from '@/Components/Badge.vue';
import SearchInput from '@/Components/SearchInput.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useListFilters } from '@/Composables/useListFilters';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();

const props = defineProps({
    debts: Object,
    strip: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const status = ref(props.filters?.status ?? '');
const search = ref(props.filters?.search ?? '');

const { loading: filtering } = useListFilters('subscriptions.manual-debts', () => ({
    status: status.value,
    search: search.value,
}), { only: ['debts', 'filters'] });

const statusOptions = computed(() => [
    { value: '', label: t('all') },
    { value: 'unpaid', label: t('unpaid') },
    { value: 'paid', label: t('paid') },
]);

const STATUS_COLOR = { paid: 'emerald', partial: 'amber', unpaid: 'rose', exempt: 'slate' };

const th = 'px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400';
const playerLink = 'font-medium text-slate-900 hover:text-primary-600 hover:underline dark:text-slate-100 dark:hover:text-primary-300';
const leftClass = (debt) => debt.remaining > 0
    ? 'font-semibold text-rose-700 dark:text-rose-400'
    : 'text-slate-500 dark:text-slate-400';
</script>

<template>
    <Head :title="t('manual_debts')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('subscriptions.index')" :aria-label="t('subscriptions')" :title="t('subscriptions')" class="text-slate-400 hover:text-slate-600 dark:text-slate-400 dark:hover:text-slate-300">
                    <svg class="h-5 w-5 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('manual_debts') }}</h1>
            </div>
        </template>

        <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">{{ t('manual_debts_note') }}</p>

        <StatStrip :tiles="strip || []" class="mb-4" />

        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex rounded-xl bg-white p-1 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700" role="group" :aria-label="t('status')">
                    <button
                        v-for="option in statusOptions"
                        :key="option.value"
                        type="button"
                        :aria-pressed="status === option.value"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="status === option.value
                            ? 'bg-primary-600 text-white'
                            : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                        @click="status = option.value"
                    >{{ option.label }}</button>
                </div>
                <SearchInput v-model="search" :loading="filtering" :placeholder="t('manual_debts_search')" class="min-w-0 flex-1 sm:max-w-sm" />
            </div>

            <div :class="{ 'opacity-60': filtering }" :aria-busy="filtering" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition-opacity dark:bg-slate-900 dark:ring-slate-800">
                <!-- Phones: one card per debt -->
                <ul class="divide-y divide-slate-100 dark:divide-slate-800 md:hidden">
                    <li v-for="debt in debts.data" :key="debt.id" class="space-y-1 px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <Link v-if="debt.player" :href="route('players.show', debt.player.id)" class="min-w-0 break-words text-sm" :class="playerLink">{{ debt.player.fullname }}</Link>
                            <span v-else class="text-sm text-slate-400">-</span>
                            <Badge :label="t(debt.status)" :color="STATUS_COLOR[debt.status]" class="shrink-0" />
                        </div>
                        <p class="break-words text-xs text-slate-500 dark:text-slate-400">
                            {{ debt.label || t('previous_debt') }}<template v-if="debt.season"> · {{ debt.season }}</template>
                        </p>
                        <p class="text-xs text-slate-400 dark:text-slate-400">
                            <span dir="ltr">{{ debt.player?.membership_id }}</span> · {{ formatDate(debt.created_at) }}
                        </p>
                        <dl class="grid grid-cols-3 gap-2 pt-1 text-xs">
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">{{ t('owed') }}</dt>
                                <dd class="tabular-nums text-slate-900 dark:text-slate-100">{{ formatMoney(debt.owed) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">{{ t('paid') }}</dt>
                                <dd class="tabular-nums text-emerald-700 dark:text-emerald-400">{{ formatMoney(debt.paid) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">{{ t('remaining') }}</dt>
                                <dd class="tabular-nums" :class="leftClass(debt)">{{ formatMoney(debt.remaining) }}</dd>
                            </div>
                        </dl>
                    </li>
                    <li v-if="!debts.data?.length" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</li>
                </ul>

                <!-- Wider screens: a table -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th :class="[th, 'text-start']">{{ t('player') }}</th>
                                <th :class="[th, 'text-start']">{{ t('label') }}</th>
                                <th :class="[th, 'text-start']">{{ t('season') }}</th>
                                <th :class="[th, 'text-end']">{{ t('owed') }}</th>
                                <th :class="[th, 'text-end']">{{ t('paid') }}</th>
                                <th :class="[th, 'text-end']">{{ t('remaining') }}</th>
                                <th :class="[th, 'text-start']">{{ t('date_added') }}</th>
                                <th :class="[th, 'text-start']">{{ t('status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="debt in debts.data" :key="debt.id" class="hover:bg-slate-50 dark:hover:bg-slate-800">
                                <td class="px-4 py-3 text-sm">
                                    <Link v-if="debt.player" :href="route('players.show', debt.player.id)" :class="playerLink">{{ debt.player.fullname }}</Link>
                                    <span v-else class="text-slate-400">-</span>
                                    <p class="text-xs text-slate-400 dark:text-slate-400" dir="ltr">{{ debt.player?.membership_id }}</p>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ debt.label || t('previous_debt') }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ debt.season || '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums">{{ formatMoney(debt.owed) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-emerald-700 dark:text-emerald-400">{{ formatMoney(debt.paid) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums" :class="leftClass(debt)">{{ formatMoney(debt.remaining) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ formatDate(debt.created_at) }}</td>
                                <td class="px-4 py-3"><Badge :label="t(debt.status)" :color="STATUS_COLOR[debt.status]" /></td>
                            </tr>
                            <tr v-if="!debts.data?.length">
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="px-4">
                    <Pagination :links="debts" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
