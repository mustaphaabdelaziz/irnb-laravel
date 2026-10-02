<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import StatusBreakdown from '@/Components/Attendance/StatusBreakdown.vue';
import { Card, CardHeader, CardTitle } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import { KIND_DOT } from '@/lib/attendanceCalendar';
import { pct } from '@/lib/attendanceStats';

/** Today's sessions and the last 30 days by status (AttendanceCard on the server). */
const props = defineProps({
    data: { type: Object, required: true }, // { date, today: [], last30: {...}, risk: { period, count, worst: [] } }
});
const { t } = useI18n();

// Any mark at all, even only not_counted ones (then nothing is expected, but the marks still show).
const hasMarks = computed(() => Object.values(props.data.last30.counts ?? {}).some((n) => n > 0));
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
        <div class="grid gap-5 px-5 py-4 lg:grid-cols-3">
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
                    <StatusBreakdown :counts="data.last30.counts" :pct="data.last30.pct" />
                </div>
            </section>
            <section>
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-muted-foreground">{{ t('att.risk.dash_title') }}</h3>
                    <Link :href="route('attendance.alerts')" class="text-xs font-medium text-primary-700 hover:underline dark:text-primary-300">{{ t('att.risk.see_all') }}</Link>
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums" :class="data.risk.count ? 'text-rose-600 dark:text-rose-400' : 'text-foreground'">{{ data.risk.count }}</p>
                <p v-if="!data.risk.count" class="text-sm text-muted-foreground">{{ t('att.risk.none') }}</p>
                <ul v-else class="mt-1 divide-y divide-border/70">
                    <li v-for="row in data.risk.worst" :key="row.player_id" class="flex items-center gap-2 py-1.5 text-sm">
                        <Link :href="route('players.show', row.player_id)" class="min-w-0 flex-1 truncate font-medium hover:underline">{{ row.name }}</Link>
                        <span v-if="row.streak" class="shrink-0 text-xs text-rose-600 dark:text-rose-400" :title="t('att.risk.col.longest_streak')">{{ t('att.risk.flag.streak') }} · {{ row.longest_streak }}</span>
                        <bdi dir="ltr" class="shrink-0 text-xs font-semibold tabular-nums">{{ pct(row.score_pct) }}</bdi>
                    </li>
                </ul>
            </section>
        </div>
    </Card>
</template>
