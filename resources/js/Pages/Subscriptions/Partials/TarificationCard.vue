<script setup>
import { computed, watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import IconButton from '@/Components/IconButton.vue';
import TarificationResult from './TarificationResult.vue';
import { DEFAULT_PAYMENT_RATE, DEFAULT_WEEKS, UNITS, forecast } from '@/lib/tarification';
import { formatNumber } from '@/lib/numberFormat';

const props = defineProps({
    form: { type: Object, required: true },
    data: { type: Object, required: true },
    exists: { type: Boolean, default: false },
});

const { t } = useI18n();
const page = usePage();

const inputs = computed(() => props.form.tarification);
const isAnnual = computed(() => props.form.kind === 'annual');

// Exceptional charges have no sessions; a per-session line already there keeps
// its option so the request's error can point at it.
const unitsFor = (line) => UNITS.filter((unit) => isAnnual.value || unit !== 'per_session' || line.unit === unit);

const result = computed(() => forecast({
    tarification: inputs.value,
    roster: props.data.roster,
    schedule: props.data.schedule,
    subscription: {
        kind: props.form.kind,
        amount_student: props.form.amount_student,
        amount_worker: props.form.amount_worker,
        category_ids: props.form.category_ids,
        branch_ids: props.form.branch_ids,
        category_prices: props.form.category_prices,
    },
}));

const mixedPrices = computed(() => Number(props.form.amount_student) !== Number(props.form.amount_worker)
    || Object.values(props.form.category_prices).some((prices) => prices.amount_student || prices.amount_worker));

const addLine = () => inputs.value.lines.push({ label: '', unit: isAnnual.value ? 'per_session' : 'per_trip', amount: '', quantity: 1 });
const removeLine = (index) => inputs.value.lines.splice(index, 1);

// The quantity input is hidden per player, and the formula still multiplies by it.
watchEffect(() => {
    for (const line of inputs.value.lines) {
        if (line.unit === 'per_player' && line.quantity !== 1) line.quantity = 1;
    }
});

const number = (value) => formatNumber(value, page.props.locale || 'fr');
const lineTotal = (index) => number(result.value.lines[index]?.total ?? 0);
const errorFor = (index, field) => props.form.errors[`tarification.lines.${index}.${field}`];
</script>

<template>
    <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('tarification.title') }}</h2>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.hint') }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <template v-if="isAnnual">
                <div>
                    <InputLabel :value="t('tarification.sessions_per_week')" />
                    <TextInput v-model="inputs.sessions_per_week" type="number" min="0" max="21" class="mt-1 w-full" :placeholder="String(result.autoSessionsPerWeek)" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ result.autoSessionsPerWeek ? t('tarification.schedule_sessions', { n: number(result.autoSessionsPerWeek) }) : t('tarification.no_schedule') }}
                    </p>
                    <InputError :message="form.errors['tarification.sessions_per_week']" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('tarification.weeks')" />
                    <TextInput v-model="inputs.weeks" type="number" min="0" max="53" class="mt-1 w-full" :placeholder="String(DEFAULT_WEEKS)" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.sessions_total', { n: number(result.sessions) }) }}</p>
                    <InputError :message="form.errors['tarification.weeks']" class="mt-1" />
                </div>
            </template>
            <div>
                <InputLabel :value="t('tarification.expected_players')" />
                <TextInput v-model="inputs.expected_players" type="number" min="0" class="mt-1 w-full" :placeholder="String(result.autoPlayers)" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.matching_players', { n: number(result.autoPlayers) }) }}</p>
                <InputError :message="form.errors['tarification.expected_players']" class="mt-1" />
            </div>
            <div>
                <InputLabel :value="t('tarification.payment_rate')" />
                <TextInput v-model="inputs.expected_payment_rate" type="number" min="0" max="100" class="mt-1 w-full" :placeholder="String(DEFAULT_PAYMENT_RATE)" />
                <p v-if="exists" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ data.realRate === null || data.realRate === 0 ? t('tarification.no_payments') : t('tarification.real_rate', { rate: number(data.realRate) }) }}
                </p>
                <InputError :message="form.errors['tarification.expected_payment_rate']" class="mt-1" />
            </div>
        </div>

        <div class="mt-6">
            <p class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ t('tarification.costs') }}</p>

            <div class="mt-2 space-y-3">
                <div v-for="(line, index) in inputs.lines" :key="index" class="rounded-xl p-3 ring-1 ring-slate-200 dark:ring-slate-800">
                    <div class="grid gap-3 sm:grid-cols-12">
                        <label class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-3">
                            {{ t('tarification.line_label') }}
                            <TextInput v-model="line.label" class="mt-0.5 w-full py-1.5 text-sm" maxlength="100" :placeholder="t('tarification.line_placeholder')" />
                            <InputError :message="errorFor(index, 'label')" class="mt-1" />
                        </label>
                        <label class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-4">
                            {{ t('tarification.unit_label') }}
                            <select v-model="line.unit" class="mt-0.5 w-full rounded-lg border-slate-300 py-1.5 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900">
                                <option v-for="unit in unitsFor(line)" :key="unit" :value="unit">{{ t(`tarification.unit.${unit}`) }}</option>
                            </select>
                            <InputError :message="errorFor(index, 'unit')" class="mt-1" />
                        </label>
                        <label class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-3">
                            {{ t('tarification.amount') }}
                            <TextInput v-model="line.amount" type="number" step="0.01" min="0" class="mt-0.5 w-full py-1.5 text-sm" />
                            <InputError :message="errorFor(index, 'amount')" class="mt-1" />
                        </label>
                        <label v-if="line.unit !== 'per_player'" class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-2">
                            {{ t(`tarification.quantity.${line.unit}`) }}
                            <TextInput v-model="line.quantity" type="number" min="1" class="mt-0.5 w-full py-1.5 text-sm" />
                            <InputError :message="errorFor(index, 'quantity')" class="mt-1" />
                        </label>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.line_total') }}: <span class="font-semibold text-slate-900 dark:text-slate-100">{{ lineTotal(index) }}</span></span>
                        <IconButton icon="trash" :label="t('tarification.remove_line')" variant="danger" @click="removeLine(index)" />
                    </div>
                </div>
            </div>

            <SecondaryButton type="button" class="mt-3" @click="addLine">{{ t('tarification.add_line') }}</SecondaryButton>
            <InputError :message="form.errors['tarification.lines']" class="mt-1" />
        </div>

        <div class="mt-6 border-t border-slate-100 pt-4 dark:border-slate-800">
            <TarificationResult :result="result" :collected="exists ? data.collected : null" :mixed-prices="mixedPrices" />
        </div>
    </div>
</template>
