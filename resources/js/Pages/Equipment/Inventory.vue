<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import Icon from '@/Components/Icon.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    summary: Object,
    conditionBreakdown: Array,
    categoryBreakdown: Array,
    overdueRentals: Array,
});
</script>

<template>
    <Head :title="t('inventory_report')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('equipment.catalogs.index')" class="text-lg text-slate-400 dark:text-slate-500 transition-colors hover:text-slate-600 dark:hover:text-slate-300">
                        <Icon name="back" class="rtl:rotate-180" />
                    </Link>
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('inventory_report') }}</h1>
                </div>
                <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm transition hover:border-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800 active:scale-[0.98] print:hidden">
                    <Icon name="print" class="text-base" /> {{ t('print') }}
                </button>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Stats -->
            <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <StatCard :label="t('total')" :value="summary?.total || 0" color="primary" icon="box" />
                <StatCard :label="t('available')" :value="summary?.available || 0" color="emerald" icon="check" />
                <StatCard :label="t('rented')" :value="summary?.rented || 0" color="amber" icon="refresh" />
                <StatCard :label="t('under_repair')" :value="summary?.under_repair || 0" color="slate" icon="wrench" />
                <StatCard :label="t('lost')" :value="summary?.lost || 0" color="rose" icon="xcircle" />
                <StatCard :label="t('retired')" :value="summary?.retired || 0" color="slate" icon="archive" />
            </div>

            <!-- Overdue rentals warning -->
            <div v-if="overdueRentals?.length" class="rounded-2xl border border-rose-200 bg-rose-50 p-5 dark:border-rose-500/30 dark:bg-rose-500/10">
                <h3 class="flex items-center gap-2 text-base font-semibold text-rose-900 dark:text-rose-200"><Icon name="alert" class="text-lg text-rose-500 dark:text-rose-400" /> {{ t('overdue') }} ({{ overdueRentals.length }})</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-rose-200">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-start text-xs font-semibold uppercase text-rose-600">{{ t('equipment') }}</th>
                                <th class="px-3 py-2 text-start text-xs font-semibold uppercase text-rose-600">{{ t('player') }}</th>
                                <th class="px-3 py-2 text-start text-xs font-semibold uppercase text-rose-600">{{ t('due_date') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rose-100">
                            <tr v-for="r in overdueRentals" :key="r.id">
                                <td class="px-3 py-2 text-sm text-rose-900">{{ r.catalog?.name }} ({{ r.unique_identifier }})</td>
                                <td class="px-3 py-2 text-sm text-rose-800">{{ r.rented_to?.firstname }} {{ r.rented_to?.lastname }}</td>
                                <td class="px-3 py-2 text-sm font-medium text-rose-700">{{ r.due_date }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Condition breakdown -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('condition') }}</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('condition') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('count') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="c in conditionBreakdown" :key="c.condition">
                                <td class="px-4 py-3 text-sm text-slate-900 dark:text-slate-100">{{ c.condition }}</td>
                                <td class="px-4 py-3 text-end text-sm font-semibold text-slate-900 dark:text-slate-100">{{ c.count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Category breakdown -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('categories') }}</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('category') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('total') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('available') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('rented') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="c in categoryBreakdown" :key="c.category">
                                <td class="px-4 py-3 text-sm text-slate-900 dark:text-slate-100">{{ c.category }}</td>
                                <td class="px-4 py-3 text-end text-sm font-semibold">{{ c.total }}</td>
                                <td class="px-4 py-3 text-end text-sm text-emerald-700">{{ c.available }}</td>
                                <td class="px-4 py-3 text-end text-sm text-amber-700">{{ c.rented }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
