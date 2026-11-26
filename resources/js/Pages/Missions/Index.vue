<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import TextInput from '@/Components/TextInput.vue';
import MissionPrintButton from '@/Components/MissionPrintButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatDate } from '@/Composables/useFormatDate';

const { t } = useI18n();
const { formatDate } = useFormatDate();

const props = defineProps({ orders: Object, filters: Object, years: Array, can: Object });

const STATUSES = ['active', 'cancelled'];
const STATUS_COLOR = { active: 'emerald', cancelled: 'rose' };

// References a print on this page took, until the next reload.
const taken = reactive({});
const referenceOf = (order) => taken[order.id] ?? order.reference;
const registerUrl = (order) => (props.can.register && order.status === 'active' && !order.is_legacy && !referenceOf(order)
    ? route('missions.register', order.id) : null);

// The year filter arrives as a string, the list of years as ints: compare as strings.
const filters = reactive({ year: String(props.filters.year ?? ''), status: props.filters.status ?? '', q: props.filters.q ?? '' });
let timer = null;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('missions.index'),
        Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true }), 300);
});
</script>

<template>
    <Head :title="t('missions')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('missions') }}</h1>
                <IconButton v-if="can.create" :href="route('missions.create')" icon="plus" :label="t('mis.new')" variant="primary" />
            </div>
        </template>

        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-4 dark:bg-slate-900 dark:ring-slate-800">
                <TextInput v-model="filters.q" class="sm:col-span-2" :placeholder="t('mis.find_placeholder')" />
                <select v-model="filters.year" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('po.all_years') }}</option>
                    <option v-for="y in years" :key="y" :value="String(y)">{{ y }}</option>
                </select>
                <select v-model="filters.status" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('mis.all_statuses') }}</option>
                    <option v-for="s in STATUSES" :key="s" :value="s">{{ t('mis.status.' + s) }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('reference') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mis.order_date') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mis.destination') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mis.travelers') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mis.trip') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('status') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="o in orders.data" :key="o.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="whitespace-nowrap px-4 py-3 font-mono">
                                <Link :href="route('missions.show', o.id)" class="text-primary-600 hover:underline dark:text-primary-300"><bdi>{{ referenceOf(o) || '—' }}</bdi></Link>
                                <span v-if="o.is_legacy" class="ms-1 rounded bg-slate-100 px-1 font-sans text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('po.legacy') }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3"><bdi v-if="o.order_date" dir="ltr">{{ formatDate(o.order_date) }}</bdi><span v-else class="text-slate-400 dark:text-slate-500">—</span></td>
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">
                                <Link :href="route('missions.show', o.id)" class="hover:underline">{{ o.destination || '—' }}</Link>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                {{ o.travelers }}<span v-if="o.travelers_count > 3" class="text-slate-400 dark:text-slate-500"> +{{ o.travelers_count - 3 }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-300">
                                <bdi v-if="o.departure_date" dir="ltr">{{ formatDate(o.departure_date) }}<template v-if="o.return_date"> – {{ formatDate(o.return_date) }}</template></bdi>
                            </td>
                            <td class="px-4 py-3 text-center"><Badge :label="t('mis.status.' + o.status)" :color="STATUS_COLOR[o.status]" /></td>
                            <td class="px-4 py-3 text-end">
                                <MissionPrintButton :href="route('missions.print', o.id)" :register-url="registerUrl(o)" :label="t('mis.print_all')" size="sm" plain
                                    @registered="(reference) => { taken[o.id] = reference; }" />
                            </td>
                        </tr>
                        <tr v-if="!orders.data.length"><td colspan="7" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div v-if="orders.last_page > 1" class="flex flex-wrap justify-center gap-1">
                <Link v-for="(link, i) in orders.links" :key="i" :href="link.url || ''" v-html="link.label" preserve-state
                    :class="['rounded px-3 py-1 text-sm', link.active ? 'bg-primary-600 text-white' : 'text-slate-600 dark:text-slate-300', !link.url && 'pointer-events-none opacity-40']" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
