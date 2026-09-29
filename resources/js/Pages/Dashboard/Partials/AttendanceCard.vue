<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import StatusBreakdown from '@/Components/Attendance/StatusBreakdown.vue';
import { Card, CardHeader, CardTitle } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import { KIND_DOT } from '@/lib/attendanceCalendar';

/** Today's sessions and the last 30 days by status (AttendanceCard on the server). */
const props = defineProps({
    data: { type: Object, required: true }, // { date, today: [], last30: { from, to, expected, counts, pct } }
});
const { t } = useI18n();

const hasMarks = computed(() => props.data.last30.expected > 0);
const stateClass = {
    planned: 'text-muted-foreground',
    held: 'text-emerald-600 dark:text-emerald-400',
    cancelled: 'text-muted-foreground line-through',
};
</script>

<template>
    <Card class="border-border/70 shadow-none">
        <CardHeader class="flex-row items-center justify-between space-y-0 px-5 py-4">
            <CardTitle class="text-base">{{ t('attendance') }}</CardTitle>
            <div class="flex gap-3 text-xs font-medium">
                <Link :href="route('attendance.index')" class="text-primary-700 hover:underline dark:text-primary-300">{{ t('att.dash.open_calendar') }}</Link>
                <Link :href="route('attendance.stats')" class="text-primary-700 hover:underline dark:text-primary-300">{{ t('att.statistics') }}</Link>
            </div>
        </CardHeader>
        <Separator />
        <div class="grid gap-5 px-5 py-4 lg:grid-cols-2">
            <section>
                <h3 class="text-sm font-semibold text-muted-foreground">{{ t('att.dash.today') }}</h3>
                <p v-if="!data.today.length" class="mt-3 text-sm text-muted-foreground">{{ t('att.dash.none_today') }}</p>
                <ul v-else class="mt-2 divide-y divide-border/70">
                    <li v-for="s in data.today" :key="s.id">
                        <Link :href="route('attendance.sessions.show', s.id)" class="flex items-center gap-3 rounded-md px-1 py-2 text-sm hover:bg-muted/40">
                            <span dir="ltr" class="w-24 shrink-0 tabular-nums text-muted-foreground">{{ s.start_time }}–{{ s.end_time }}</span>
                            <span class="h-2 w-2 shrink-0 rounded-full" :class="KIND_DOT[s.kind]" :title="t(`att.kind.${s.kind}`)"></span>
                            <span class="min-w-0 flex-1 truncate">
                                <span class="font-medium">{{ s.categories.map((c) => c.name).join(' · ') }}</span>
                                <span v-if="s.title" class="text-muted-foreground"> — {{ s.title }}</span>
                            </span>
                            <span class="shrink-0 text-xs" :class="stateClass[s.state]">{{ t(`att.state.${s.state}`) }}</span>
                        </Link>
                    </li>
                </ul>
            </section>
            <section>
                <h3 class="text-sm font-semibold text-muted-foreground">{{ t('att.dash.last30') }}</h3>
                <p v-if="!hasMarks" class="mt-3 text-sm text-muted-foreground">{{ t('att.dash.no_marks') }}</p>
                <div v-else class="mt-3">
                    <StatusBreakdown :counts="data.last30.counts" />
                </div>
            </section>
        </div>
    </Card>
</template>
