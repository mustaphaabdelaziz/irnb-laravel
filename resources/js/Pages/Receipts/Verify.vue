<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCurrency } from '@/Composables/useCurrency';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useStatusLabel } from '@/Composables/useStatusLabel';

/**
 * Checks a paper receipt by the code under its QR. A USB scanner types the
 * code and presses Enter, so the field keeps focus and submits on Enter.
 */
const props = defineProps({
    input: { type: String, default: '' },
    result: { type: Object, default: null },
});

const { t } = useI18n();
const { currencyLabel } = useCurrency();
const { formatMoney } = useFormatMoney();
const { statusLabel } = useStatusLabel();

const code = ref(props.input);
const field = ref(null);

onMounted(() => field.value?.focus());

function check() {
    router.get(route('receipts.verify'), { code: code.value }, {
        preserveScroll: true,
        onFinish: () => {
            code.value = '';
            field.value?.focus();
        },
    });
}

const COLORS = { valid: 'emerald', modified: 'amber', cancelled: 'rose', deleted: 'rose', unknown: 'rose' };
const status = computed(() => props.result?.status);
const snap = computed(() => props.result?.snapshot ?? {});

/** A printed or current value, in the words the screens use. */
function show(name, value) {
    if (value === null || value === undefined || value === '') return '—';
    if (name === 'amount') return `${formatMoney(value)} ${currencyLabel.value}`;
    if (name === 'archived') return value ? t('receipts.cancelled_yes') : t('receipts.cancelled_no');
    if (name === 'type') return t(value);
    if (name === 'status') return statusLabel('payment', value);
    if (name === 'payment_method') return statusLabel('payment_method', value);
    if (name === 'player_id' || name === 'player_subscription_id') return `#${value}`;
    return String(value);
}
</script>

<template>
    <Head :title="t('receipts.verify_title')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('receipts.verify_title') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <form class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800" @submit.prevent="check">
                <label for="receipt-code" class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('receipts.code_label') }}</label>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('receipts.verify_hint') }}</p>
                <div class="mt-3 flex gap-2">
                    <TextInput id="receipt-code" ref="field" v-model="code" dir="ltr" autocomplete="off" inputmode="numeric"
                        class="block w-full font-mono tracking-widest" :placeholder="t('receipts.code_placeholder')" />
                    <PrimaryButton :disabled="!code.trim()">{{ t('receipts.check') }}</PrimaryButton>
                </div>
            </form>

            <div v-if="result" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex flex-wrap items-center gap-3">
                    <Badge :label="t(`receipts.status.${status}`)" :color="COLORS[status]" />
                    <span v-if="result.code" class="font-mono text-sm text-slate-500 dark:text-slate-400" dir="ltr">{{ result.code }}</span>
                </div>
                <p class="mt-2 text-sm text-slate-700 dark:text-slate-200">{{ t(`receipts.explain.${status}`) }}</p>

                <template v-if="status !== 'unknown'">
                    <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('receipts.paper_copy') }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">
                                {{ result.copy_number === 1 ? t('receipts.original') : t('receipts.duplicate', { n: result.copy_number }) }}
                                · {{ t(`receipts.format.${result.format}`) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('receipts.printed') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ result.printed_at }} · {{ result.printed_by || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('receipts.number') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                                <Link v-if="result.transaction_id" :href="route('transactions.show', result.transaction_id)" class="text-primary-600 hover:underline">#{{ result.transaction_number }}</Link>
                                <span v-else>#{{ result.transaction_number }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('receipts.on_paper_amount') }}</dt>
                            <dd class="mt-1 text-lg font-bold tabular-nums text-slate-900 dark:text-slate-100" dir="ltr">{{ show('amount', snap.amount) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('date') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ show('date', snap.date) }}</dd>
                        </div>
                        <div v-if="snap.player_name">
                            <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('player') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ snap.player_name }} <span class="font-mono text-xs text-slate-500">{{ snap.membership_id }}</span></dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('title') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ snap.title || '—' }}</dd>
                        </div>
                    </dl>

                    <div v-if="result.differences?.length" class="mt-6">
                        <h3 class="text-sm font-semibold text-amber-700 dark:text-amber-400">{{ t('receipts.changed_since') }}</h3>
                        <table class="mt-2 min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead>
                                <tr class="text-xs uppercase text-slate-500 dark:text-slate-400">
                                    <th class="py-2 text-start font-semibold"></th>
                                    <th class="py-2 text-start font-semibold">{{ t('receipts.on_paper') }}</th>
                                    <th class="py-2 text-start font-semibold">{{ t('receipts.now') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="d in result.differences" :key="d.field">
                                    <td class="py-2 font-medium text-slate-700 dark:text-slate-200">{{ t(`receipts.field.${d.field}`) }}</td>
                                    <td class="py-2 text-slate-900 dark:text-slate-100">{{ show(d.field, d.printed) }}</td>
                                    <td class="py-2 font-semibold text-amber-700 dark:text-amber-400">{{ show(d.field, d.current) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="result.copies?.length" class="mt-6">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('receipts.history') }}</h3>
                        <ul class="mt-2 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                            <li v-for="c in result.copies" :key="c.copy_number" class="flex flex-wrap justify-between gap-2 py-2" :class="c.is_this ? 'font-semibold text-primary-700 dark:text-primary-400' : 'text-slate-700 dark:text-slate-200'">
                                <span>{{ c.copy_number === 1 ? t('receipts.original') : t('receipts.duplicate', { n: c.copy_number }) }} · {{ t(`receipts.format.${c.format}`) }}</span>
                                <span>{{ c.printed_at }} · {{ c.printed_by || '—' }}</span>
                            </li>
                        </ul>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
