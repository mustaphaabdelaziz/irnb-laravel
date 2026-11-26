<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { useFormatDate } from '@/Composables/useFormatDate';

const { t } = useI18n();
const { can } = useCan();
const { formatDate } = useFormatDate();

const props = defineProps({ entries: Object, filters: Object, years: Array });

const DIRECTIONS = ['out', 'in'];
const STATUS_COLOR = { active: 'emerald', cancelled: 'rose' };
const direction = props.filters.direction ?? 'out';

// The tab is a link (it resets year and search); year and search reload in place.
const filters = reactive({ year: props.filters.year ?? '', q: props.filters.q ?? '' });
let timer = null;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('mail.index'), {
        direction, ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')),
    }, { preserveState: true, replace: true }), 300);
});
</script>

<template>
    <Head :title="t('mail')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mail') }}</h1>
                <IconButton v-if="can('mail', 'add')" :href="route('mail.create', { direction })" icon="plus" :label="t('mreg.new')" variant="primary" />
            </div>
        </template>

        <div class="space-y-4">
            <nav class="flex gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800/60">
                <Link v-for="d in DIRECTIONS" :key="d" :href="route('mail.index', { direction: d })"
                    :class="['flex-1 rounded-lg px-3 py-2 text-center text-sm font-semibold', d === direction
                        ? 'bg-white text-primary-700 shadow-sm dark:bg-slate-900 dark:text-primary-300'
                        : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white']">
                    {{ t(d === 'out' ? 'mreg.outgoing' : 'mreg.incoming') }}
                </Link>
            </nav>

            <div class="grid grid-cols-1 gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-3 dark:bg-slate-900 dark:ring-slate-800">
                <TextInput v-model="filters.q" class="sm:col-span-2" :placeholder="t('mreg.find_placeholder')" />
                <select v-model="filters.year" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('po.all_years') }}</option>
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('reference') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('date') }}</th>
                            <th class="px-4 py-3 text-start">{{ t(direction === 'out' ? 'mreg.recipient' : 'mreg.sender') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mreg.subject') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mreg.source_label') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="e in entries.data" :key="e.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="whitespace-nowrap px-4 py-3 font-mono">
                                <Link :href="route('mail.show', e.id)" class="text-primary-600 hover:underline dark:text-primary-300"><bdi>{{ e.reference || '—' }}</bdi></Link>
                                <span v-if="e.is_legacy" class="ms-1 rounded bg-slate-100 px-1 font-sans text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('po.legacy') }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3"><bdi v-if="e.entry_date" dir="ltr">{{ formatDate(e.entry_date) }}</bdi><span v-else class="text-slate-400 dark:text-slate-500">—</span></td>
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">{{ e.correspondent || '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ e.subject }}</td>
                            <td class="px-4 py-3 text-xs">
                                <Link v-if="e.source_url" :href="e.source_url" class="text-primary-600 hover:underline dark:text-primary-300">{{ t('mreg.source.' + e.source_type) }}</Link>
                                <span v-else-if="e.source_type" class="text-slate-500 dark:text-slate-400">{{ t('mreg.source.' + e.source_type) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center"><Badge :label="t('mreg.status.' + e.status)" :color="STATUS_COLOR[e.status]" /></td>
                        </tr>
                        <tr v-if="!entries.data.length"><td colspan="6" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div v-if="entries.last_page > 1" class="flex flex-wrap justify-center gap-1">
                <Link v-for="(link, i) in entries.links" :key="i" :href="link.url || ''" v-html="link.label" preserve-state
                    :class="['rounded px-3 py-1 text-sm', link.active ? 'bg-primary-600 text-white' : 'text-slate-600 dark:text-slate-300', !link.url && 'pointer-events-none opacity-40']" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
