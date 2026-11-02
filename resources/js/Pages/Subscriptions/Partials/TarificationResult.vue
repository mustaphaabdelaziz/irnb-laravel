<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCurrency } from '@/Composables/useCurrency';
import { formatNumber } from '@/lib/numberFormat';

const props = defineProps({
    result: { type: Object, required: true },
    collected: { type: Number, default: null },
    mixedPrices: { type: Boolean, default: false },
});

const { t } = useI18n();
const { currencyLabel } = useCurrency();
const page = usePage();

const number = (value) => formatNumber(value, page.props.locale || 'fr');
const money = (value) => `${number(value)} ${currencyLabel.value}`;

const lineDetail = (line) => {
    const parts = [number(line.amount), number(line.quantity)];
    if (line.unit === 'per_session') parts.push(number(props.result.sessions));
    if (line.unit === 'per_player') parts.push(number(props.result.players));

    return parts.join(' × ');
};

const statusClass = computed(() => ({
    win: 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-800',
    lose: 'bg-rose-50 text-rose-800 ring-rose-200 dark:bg-rose-900/20 dark:text-rose-300 dark:ring-rose-800',
    even: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-800',
}[props.result.status]));
</script>

<template>
    <div class="space-y-4">
        <p v-if="result.prompt" class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t(result.prompt) }}</p>

        <template v-else>
            <div class="rounded-xl p-4 ring-1" :class="statusClass">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="text-sm font-semibold">{{ t(`tarification.status.${result.status}`) }}</span>
                    <span class="text-2xl font-bold" dir="ltr">{{ money(result.margin) }}</span>
                </div>
                <p v-if="result.marginPerPlayer !== null" class="mt-1 text-sm">
                    {{ t('tarification.per_player', { amount: money(result.marginPerPlayer) }) }}
                </p>
            </div>

            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                <div class="flex flex-wrap justify-between gap-x-4 gap-y-1 py-2">
                    <dt>
                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ t('tarification.income') }}</span>
                        <span class="ms-2 text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.income_detail', { players: number(result.players), price: number(result.price), rate: number(result.paymentRate) }) }}</span>
                    </dt>
                    <dd class="font-semibold text-slate-900 dark:text-slate-100">{{ money(result.income) }}</dd>
                </div>
                <div v-for="(line, index) in result.lines" :key="index" class="flex flex-wrap justify-between gap-x-4 gap-y-1 py-2">
                    <dt>
                        <span class="text-slate-700 dark:text-slate-200">{{ line.label }}</span>
                        <span class="ms-2 text-xs text-slate-500 dark:text-slate-400"><bdi dir="ltr">{{ lineDetail(line) }}</bdi></span>
                    </dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ money(line.total) }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-2 font-semibold text-slate-900 dark:text-slate-100">
                    <dt>{{ t('tarification.total_costs') }}</dt>
                    <dd>{{ money(result.costs) }}</dd>
                </div>
            </dl>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.break_even_price') }}</p>
                    <p class="mt-1 font-bold text-slate-900 dark:text-slate-100">{{ result.breakEvenPrice === null ? '—' : money(result.breakEvenPrice) }}</p>
                    <p v-if="mixedPrices" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.average_price') }}</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.break_even_players') }}</p>
                    <p v-if="result.breakEvenPlayers !== null" class="mt-1 font-bold text-slate-900 dark:text-slate-100">{{ number(result.breakEvenPlayers) }}</p>
                    <p v-else class="mt-1 text-sm text-rose-700 dark:text-rose-300">{{ t('tarification.no_break_even') }}</p>
                </div>
            </div>
        </template>

        <p v-if="collected !== null" class="flex justify-between gap-4 text-sm text-slate-700 dark:text-slate-200">
            <span>{{ t('tarification.paid_so_far') }}</span>
            <span class="font-semibold">{{ money(collected) }}</span>
        </p>
    </div>
</template>
