<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { KIND_DOT, addMonths, parseDay } from '@/lib/attendanceCalendar';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null }, // null = every category
    month: { type: String, required: true }, // YYYY-MM
    sessions: { type: Array, default: () => [] },
});
const emit = defineEmits(['navigate']);
const { t, locale } = useI18n();
const { summaryText } = useAttendanceCodes();

const monthLabel = computed(() => parseDay(`${props.month}-01`).toLocaleDateString(locale.value, { month: 'long', year: 'numeric' }));
const dayLabel = (key) => parseDay(key).toLocaleDateString(locale.value, { weekday: 'short', day: 'numeric', month: 'short' });
const go = (params) => emit('navigate', { category_id: props.categoryId, month: props.month, ...params });
const pickCategory = (value) => go({ category_id: value ? Number(value) : null });

const stateClass = { planned: 'text-slate-500', held: 'text-emerald-600 dark:text-emerald-400', cancelled: 'text-slate-400' };
const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="pickCategory($event.target.value)">
                <option value="">{{ t('att.all_categories') }}</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rtl:rotate-180" :aria-label="t('att.prev_month')" @click="go({ month: addMonths(month, -1) })"><Icon name="back" /></button>
            <span class="min-w-[9rem] text-center text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ monthLabel }}</span>
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 ltr:rotate-180" :aria-label="t('att.next_month')" @click="go({ month: addMonths(month, 1) })"><Icon name="back" /></button>
        </div>

        <p v-if="!sessions.length" class="text-sm text-slate-500">{{ t('att.no_sessions') }}</p>
        <div v-else class="overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <table class="w-full min-w-[44rem] text-sm">
                <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                        <th class="p-2 text-start font-semibold">{{ t('att.date') }}</th>
                        <th class="p-2 text-start font-semibold">{{ t('att.col.time') }}</th>
                        <th class="p-2 text-start font-semibold">{{ t('att.col.kind') }}</th>
                        <th class="p-2 text-start font-semibold">{{ t('att.categories') }}</th>
                        <th class="p-2 text-start font-semibold">{{ t('att.title_goal') }}</th>
                        <th class="p-2 text-start font-semibold">{{ t('att.col.state') }}</th>
                        <th class="p-2 text-end font-semibold">{{ t('att.col.marked') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in sessions" :key="s.id" class="border-t border-slate-100 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/40" :class="{ 'text-slate-400 line-through': s.state === 'cancelled' }">
                        <td class="whitespace-nowrap p-2 font-medium capitalize">
                            <Link :href="route('attendance.sessions.show', s.id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ dayLabel(s.date) }}</Link>
                        </td>
                        <td class="whitespace-nowrap p-2"><span dir="ltr">{{ s.start_time }}–{{ s.end_time }}</span></td>
                        <td class="whitespace-nowrap p-2">
                            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" :class="KIND_DOT[s.kind]"></span>{{ t(`att.kind.${s.kind}`) }}</span>
                        </td>
                        <td class="p-2">{{ s.categories.map((c) => c.name).join(' · ') }}</td>
                        <td class="max-w-[16rem] truncate p-2" :title="s.title ?? ''">{{ s.title }}</td>
                        <td class="whitespace-nowrap p-2" :class="stateClass[s.state]" :title="s.cancel_reason ?? ''">{{ t(`att.state.${s.state}`) }}</td>
                        <td class="whitespace-nowrap p-2 text-end" :title="summaryText(s.summary)">{{ s.marked ? t('att.marked', { n: s.marked }) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
