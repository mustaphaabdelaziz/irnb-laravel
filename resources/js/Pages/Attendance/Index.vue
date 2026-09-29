<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import AddSessionModal from './Partials/AddSessionModal.vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null },
    month: { type: String, required: true }, // YYYY-MM
    sessions: { type: Array, default: () => [] },
    preseason: { type: Object, default: null },
    hasSchedule: { type: Boolean, default: false },
});
const { t, locale } = useI18n();
const { can } = useCan();
const lang = computed(() => (locale.value === 'ar' ? 'ar' : locale.value));

const key = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayKey = key(new Date());
const anchor = computed(() => new Date(`${props.month}-01T00:00:00`));
const monthLabel = computed(() => anchor.value.toLocaleDateString(lang.value, { month: 'long', year: 'numeric' }));
const weekdays = computed(() => Array.from({ length: 7 }, (_, i) =>
    new Date(Date.UTC(2024, 0, 1 + i)).toLocaleDateString(lang.value, { weekday: 'short', timeZone: 'UTC' })));

const byDate = computed(() => props.sessions.reduce((acc, s) => ((acc[s.date] ??= []).push(s), acc), {}));

// 6-week grid starting on the Monday on/before the 1st.
const cells = computed(() => {
    const first = anchor.value;
    const start = new Date(first);
    start.setDate(first.getDate() - ((first.getDay() + 6) % 7));
    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const k = key(d);
        return { key: k, day: d.getDate(), inMonth: d.getMonth() === first.getMonth(), sessions: byDate.value[k] ?? [] };
    });
});

function visit(params) {
    router.get(route('attendance.index'), { category_id: props.categoryId, month: props.month, ...params }, { preserveScroll: true });
}
function shift(delta) {
    const d = new Date(anchor.value);
    d.setMonth(d.getMonth() + delta);
    visit({ month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` });
}

const chip = {
    planned: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    held: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    cancelled: 'bg-slate-200 text-slate-500 line-through dark:bg-slate-700 dark:text-slate-400',
};
const kindDot = { regular: 'bg-primary-500', preseason: 'bg-amber-500', extra: 'bg-violet-500' };
const chipTitle = (s) => [t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.categories.map((c) => c.name).join(', '), s.title]
    .filter(Boolean).join(' · ');

const preseasonLabel = computed(() => {
    if (!props.preseason) return '';
    const { season, done, target } = props.preseason;
    return target === null ? t('att.preseason_no_target', { season, done }) : t('att.preseason_progress', { season, done, target });
});

// ---- Add an extra / pre-season session ----
const showCreate = ref(false);
const createKind = ref('extra');
function openCreate(kind) {
    createKind.value = kind;
    showCreate.value = true;
}
const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <Head :title="t('attendance')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('attendance') }}</h1>
                <div class="flex gap-2">
                    <Link v-if="categoryId" :href="route('attendance.grid', { category_id: categoryId, month })" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800">{{ t('att.grid') }}</Link>
                    <Link v-if="can('attendance', 'edit')" :href="route('attendance.settings')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="settings" />{{ t('att.settings') }}</Link>
                </div>
            </div>
        </template>

        <p v-if="!categories.length" class="text-sm text-slate-500">{{ t('att.no_category') }}</p>

        <div v-else class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <select :value="categoryId" :class="input" :aria-label="t('att.category')" @change="visit({ category_id: Number($event.target.value) })">
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rtl:rotate-180" @click="shift(-1)"><Icon name="back" /></button>
                    <span class="min-w-[9rem] text-center text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ monthLabel }}</span>
                    <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 ltr:rotate-180" @click="shift(1)"><Icon name="back" /></button>
                    <span v-if="preseason" class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ preseasonLabel }}</span>
                </div>
                <div v-if="can('attendance', 'add')" class="flex gap-2">
                    <button class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-700" @click="openCreate('extra')">+ {{ t('att.add_extra') }}</button>
                    <button class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-600" @click="openCreate('preseason')">+ {{ t('att.add_preseason') }}</button>
                </div>
            </div>

            <p v-if="!hasSchedule" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ t('att.no_schedule') }}</p>

            <div class="grid grid-cols-7 gap-px overflow-hidden rounded-xl bg-slate-200 ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-800">
                <div v-for="w in weekdays" :key="w" class="bg-slate-50 p-2 text-center text-xs font-semibold text-slate-500 dark:bg-slate-900">{{ w }}</div>
                <div v-for="cell in cells" :key="cell.key" class="min-h-[5.5rem] bg-white p-1.5 dark:bg-slate-900" :class="{ 'opacity-40': !cell.inMonth }">
                    <div class="mb-1 text-xs font-semibold" :class="cell.key === todayKey ? 'text-primary-600' : 'text-slate-400'">{{ cell.day }}</div>
                    <Link v-for="s in cell.sessions" :key="s.id" :href="route('attendance.sessions.show', s.id)" class="mb-1 flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium" :class="chip[s.state]" :title="chipTitle(s)">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="kindDot[s.kind]"></span>
                        <span dir="ltr">{{ s.start_time }}</span>
                        <span v-if="s.categories.length > 1" class="shrink-0 rounded bg-amber-200/70 px-1 text-[10px] text-amber-900 dark:bg-amber-500/30 dark:text-amber-100">+{{ s.categories.length - 1 }}</span>
                        <span v-if="s.title" class="min-w-0 truncate">{{ s.title }}</span>
                        <span v-if="s.state === 'held'" class="ms-auto">✓</span>
                    </Link>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 text-xs text-slate-500">
                <span v-for="(cls, kind) in kindDot" :key="kind" class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" :class="cls"></span>{{ t(`att.kind.${kind}`) }}</span>
            </div>
        </div>

        <AddSessionModal :show="showCreate" :kind="createKind" :categories="categories" :category-id="categoryId" :date="todayKey" @close="showCreate = false" />
    </AuthenticatedLayout>
</template>
