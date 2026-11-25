<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useStatusLabel } from '@/Composables/useStatusLabel';

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();
const { statusLabel } = useStatusLabel();

const props = defineProps({ orders: Object, filters: Object, total: Number, years: Array, suppliers: Array });

const filters = reactive({
    year: props.filters.year ?? '', status: props.filters.status ?? '', method: props.filters.method ?? '',
    supplier: props.filters.supplier ?? '', q: props.filters.q ?? '',
});
let timer = null;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('payment-orders.index'),
        Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')), { preserveState: true, replace: true }), 300);
});

const STATUS_COLOR = { draft: 'slate', approved: 'amber', paid: 'emerald', cancelled: 'rose' };
</script>

<template>
    <Head :title="t('payment_orders')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('payment_orders') }}</h1>
                <IconButton v-if="can('payment_orders', 'add')" :href="route('payment-orders.create')" icon="plus" :label="t('po.new')" variant="primary" />
            </div>
        </template>

        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-3 lg:grid-cols-6 dark:bg-slate-900 dark:ring-slate-800">
                <TextInput v-model="filters.q" class="sm:col-span-3 lg:col-span-2" :placeholder="t('po.find_placeholder')" />
                <select v-model="filters.method" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('po.all_methods') }}</option>
                    <option v-for="m in ['bank', 'ccp', 'cheque', 'cash', 'baridimob', 'other']" :key="m" :value="m">{{ statusLabel('payment_method', m) }}</option>
                </select>
                <select v-model="filters.year" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('po.all_years') }}</option>
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
                <select v-model="filters.status" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('po.all_statuses') }}</option>
                    <option v-for="s in ['draft', 'approved', 'paid', 'cancelled']" :key="s" :value="s">{{ statusLabel('payment_order', s) }}</option>
                </select>
                <select v-model="filters.supplier" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('po.all_suppliers') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('po.number') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('date') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('po.subject') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('po.beneficiary') }}</th>
                            <th class="px-4 py-3 text-end">{{ t('amount') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="o in orders.data" :key="o.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-4 py-3 font-mono"><Link :href="route('payment-orders.show', o.id)" class="text-primary-600 hover:underline dark:text-primary-300"><bdi dir="ltr">{{ o.reference }}</bdi></Link></td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ formatDate(o.order_date) }}</bdi></td>
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">{{ o.subject }} <span v-if="o.is_legacy" class="ms-1 rounded bg-slate-100 px-1 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('po.legacy') }}</span></td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ o.beneficiary_label }}</td>
                            <td class="px-4 py-3 text-end tabular-nums"><bdi dir="ltr">{{ formatMoney(o.amount) }}</bdi></td>
                            <td class="px-4 py-3 text-center"><Badge :label="statusLabel('payment_order', o.status)" :color="STATUS_COLOR[o.status]" /></td>
                        </tr>
                        <tr v-if="!orders.data.length"><td colspan="6" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td></tr>
                    </tbody>
                    <tfoot v-if="orders.data.length" class="bg-slate-50 text-sm font-semibold dark:bg-slate-950">
                        <tr><td colspan="4" class="px-4 py-3">{{ t('po.total_not_cancelled') }}</td><td class="px-4 py-3 text-end tabular-nums"><bdi dir="ltr">{{ formatMoney(total) }}</bdi></td><td></td></tr>
                    </tfoot>
                </table>
            </div>

            <div v-if="orders.last_page > 1" class="flex flex-wrap justify-center gap-1">
                <Link v-for="link in orders.links" :key="link.label" :href="link.url || ''" v-html="link.label" preserve-state
                    :class="['rounded px-3 py-1 text-sm', link.active ? 'bg-primary-600 text-white' : 'text-slate-600 dark:text-slate-300', !link.url && 'pointer-events-none opacity-40']" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
