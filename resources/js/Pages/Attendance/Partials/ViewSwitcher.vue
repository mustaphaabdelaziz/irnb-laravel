<script setup>
import { useI18n } from 'vue-i18n';

defineProps({ view: { type: String, required: true } });
const emit = defineEmits(['switch']);
const { t } = useI18n();

// Keep in step with AttendanceCalendarController::VIEWS.
const VIEWS = ['month', 'week', 'agenda'];
</script>

<template>
    <div role="tablist" :aria-label="t('att.views')" class="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
        <button
            v-for="v in VIEWS"
            :key="v"
            type="button"
            role="tab"
            :aria-selected="view === v"
            class="rounded-md px-3 py-1 text-sm font-semibold"
            :class="view === v ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-slate-100' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
            @click="view !== v && emit('switch', v)"
        >{{ t(`att.view.${v}`) }}</button>
    </div>
</template>
