<script setup>
import { useI18n } from 'vue-i18n';

// Leaderboards (top 5 per metric). Ratings are shown in the app only, never exported.
const { t } = useI18n();
defineProps({ leaders: Object, showRating: { type: Boolean, default: true } });
const boards = [
    { key: 'goals', value: (r) => r.goals },
    { key: 'assists', value: (r) => r.assists },
    { key: 'minutes', value: (r) => `${r.minutes}′` },
    { key: 'sets', value: (r) => r.sets },
    { key: 'appearances', value: (r) => r.appearances },
    { key: 'cards', value: (r) => `🟨${r.yellows} 🟥${r.reds}` },
    { key: 'rating', value: (r) => r.avg_rating },
];
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="b in boards.filter((x) => (showRating || x.key !== 'rating') && (x.key !== 'sets' || leaders.sets?.length))" :key="b.key" class="rounded-xl ring-1 ring-slate-200 p-3 dark:ring-slate-800">
            <h4 class="mb-2 text-xs font-semibold uppercase text-slate-500">{{ t(`comp.stats.${b.key}`) }}</h4>
            <ol class="space-y-1 text-sm">
                <li v-for="(r, i) in (leaders[b.key] ?? [])" :key="`${r.player_id}-${r.sport}`" class="flex justify-between gap-2">
                    <span class="truncate"><span class="text-slate-400">{{ i + 1 }}.</span> {{ r.name }}</span>
                    <bdi dir="ltr" class="font-mono">{{ b.value(r) }}</bdi>
                </li>
                <li v-if="!(leaders[b.key] ?? []).length" class="text-slate-400">—</li>
            </ol>
        </div>
    </div>
</template>
