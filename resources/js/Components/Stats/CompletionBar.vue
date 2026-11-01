<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useChartTheme } from '@/Composables/useChartTheme';
import { useFormatNumber } from '@/Composables/useFormatNumber';
import { completionColors, completionPct, segments } from '@/lib/completion';

/**
 * "How much of the paperwork is in": one bar split into valid, expiring
 * (still counted as received) and missing documents, plus how many players
 * have a complete file. The numbers come from App\Services\Player\DocumentStats.
 */
const props = defineProps({
    received: { type: Number, default: 0 },
    expiring: { type: Number, default: 0 },
    missing: { type: Number, default: 0 },
    required: { type: Number, default: 0 },
    playersComplete: { type: Number, default: 0 },
    playersTotal: { type: Number, default: 0 },
    // { missing?: href } — the missing count links to those players.
    links: { type: Object, default: () => ({}) },
    size: { type: String, default: 'lg', validator: (value) => ['lg', 'sm'].includes(value) },
});

const { t } = useI18n();
const { themeVersion } = useChartTheme();
const { formatNumber, formatPercent } = useFormatNumber();

const lg = computed(() => props.size === 'lg');
const pct = computed(() => completionPct(props));
const parts = computed(() => segments(props));

// The same colours as the dashboard's completion charts, re-read on a theme toggle.
const colors = computed(() => {
    themeVersion.value;

    return completionColors();
});

const legend = computed(() => [
    { key: 'received', label: t('stats.doc_valid'), count: Math.max(0, props.received - props.expiring), href: null },
    { key: 'expiring', label: t('stats.doc_expiring'), count: props.expiring, href: null },
    { key: 'missing', label: t('stats.doc_missing'), count: props.missing, href: props.missing > 0 ? (props.links?.missing ?? null) : null },
]);

const valueText = computed(() => t('stats.doc_value_text', {
    received: formatNumber(props.received),
    required: formatNumber(props.required),
    expiring: formatNumber(props.expiring),
    missing: formatNumber(props.missing),
}));
</script>

<template>
    <section class="rounded-xl border border-border/70 bg-card text-card-foreground" :class="lg ? 'p-5' : 'px-4 py-3'">
        <p v-if="!playersTotal" class="text-sm text-muted-foreground">{{ t('stats.doc_no_players') }}</p>
        <p v-else-if="!required" class="text-sm text-muted-foreground">{{ t('stats.doc_none_required') }}</p>
        <template v-else>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p class="font-semibold" :class="lg ? 'text-base' : 'text-sm'">
                    {{ t('stats.doc_received_headline') }} —
                    <bdi class="tabular-nums">{{ formatPercent(pct) }}</bdi>
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ t('stats.doc_players_complete', { complete: formatNumber(playersComplete), total: formatNumber(playersTotal) }) }}
                </p>
            </div>

            <div
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-valuenow="pct"
                :aria-valuetext="valueText"
                :aria-label="t('stats.doc_received_headline')"
                class="flex w-full overflow-hidden rounded-full bg-muted"
                :class="lg ? 'mt-3 h-3.5' : 'mt-2 h-2'"
            >
                <!-- A card-coloured end edge separates neighbouring parts, so two colours never touch. -->
                <div
                    v-for="part in parts"
                    :key="part.key"
                    class="h-full border-e-2 border-card last:border-e-0"
                    :style="{ width: `${part.width}%`, backgroundColor: colors[part.key] }"
                />
            </div>

            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground" :class="lg ? 'mt-3' : 'mt-2'">
                <li v-for="item in legend" :key="item.key" class="inline-flex items-center gap-1.5">
                    <span class="size-2 shrink-0 rounded-full" :style="{ backgroundColor: colors[item.key] }" aria-hidden="true" />
                    <Link
                        v-if="item.href"
                        :href="item.href"
                        :title="t('stats.doc_see_missing')"
                        class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                    >
                        {{ item.label }} <bdi class="tabular-nums">{{ formatNumber(item.count) }}</bdi>
                    </Link>
                    <span v-else>{{ item.label }} <bdi class="tabular-nums text-foreground">{{ formatNumber(item.count) }}</bdi></span>
                </li>
            </ul>
        </template>
    </section>
</template>
