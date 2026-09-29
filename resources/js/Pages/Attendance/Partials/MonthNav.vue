<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import { addMonths, parseDay } from '@/lib/attendanceCalendar';

const props = defineProps({
    month: { type: String, required: true }, // YYYY-MM
});
const emit = defineEmits(['change']);
const { t, locale } = useI18n();

const monthLabel = computed(() => parseDay(`${props.month}-01`).toLocaleDateString(locale.value, { month: 'long', year: 'numeric' }));
</script>

<template>
    <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rtl:rotate-180" :aria-label="t('att.prev_month')" @click="emit('change', addMonths(month, -1))"><Icon name="back" /></button>
    <span class="min-w-[9rem] text-center text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ monthLabel }}</span>
    <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 ltr:rotate-180" :aria-label="t('att.next_month')" @click="emit('change', addMonths(month, 1))"><Icon name="back" /></button>
</template>
