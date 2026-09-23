<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Card, CardContent } from '@/Components/ui/card';
import Icon from '@/Components/Icon.vue';
import DeltaBadge from '@/Components/Dashboard/DeltaBadge.vue';
import Sparkline from '@/Components/Dashboard/Sparkline.vue';
import { useFormatMoney } from '@/Composables/useFormatMoney';

/**
 * One tile anatomy, used by the hero row and every module strip.
 *
 * label -> value -> delta -> sparkline, in that order, at the same sizes
 * everywhere. A dashboard where each number is styled to taste reads as a
 * collection of widgets; one where every number wears the same clothes reads
 * as a single instrument.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], default: null },
    format: { type: String, default: 'number' },
    delta: { type: Object, default: null },
    spark: { type: Array, default: () => [] },
    icon: { type: String, default: null },
    tone: { type: String, default: 'primary' },
    meta: { type: String, default: null },
    periodLabel: { type: String, default: '' },
    href: { type: String, default: null },
});

const { formatMoney } = useFormatMoney();

const toneClasses = {
    primary: 'bg-primary/10 text-primary',
    positive: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    negative: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    warning: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    neutral: 'bg-muted text-muted-foreground',
};

// A null value means "no data to compute this from", which is a different
// statement from zero — an em dash says so instead of inventing a number.
const display = computed(() => {
    if (props.value === null || props.value === undefined || props.value === '') return '—';
    if (props.format === 'money') return formatMoney(props.value);
    if (props.format === 'percent') return `${Number(props.value).toFixed(1).replace(/\.0$/, '')}%`;

    return new Intl.NumberFormat().format(props.value);
});

const isEmpty = computed(() => display.value === '—');
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href || undefined"
        class="block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 rounded-xl"
    >
        <Card
            class="h-full border-border/70 shadow-none transition-colors"
            :class="href ? 'hover:border-primary/40' : ''"
        >
            <CardContent class="flex h-full flex-col justify-between gap-3 p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <!-- Wraps rather than truncates: six tiles across leave
                             roughly 170px, and "Outstanding debt" clipped to
                             "Outstandi…" is a label that has stopped working. -->
                        <p class="text-sm font-medium leading-snug text-muted-foreground">{{ label }}</p>
                        <p
                            class="mt-2 text-[1.75rem] font-semibold leading-none tracking-tight tabular-nums"
                            :class="isEmpty ? 'text-muted-foreground/60' : 'text-foreground'"
                        >
                            {{ display }}
                        </p>
                        <DeltaBadge :delta="delta" :period-label="periodLabel" />
                        <p v-if="meta" class="mt-1.5 text-xs text-muted-foreground">{{ meta }}</p>
                    </div>

                    <span
                        v-if="icon"
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                        :class="toneClasses[tone] ?? toneClasses.primary"
                    >
                        <Icon :name="icon" />
                    </span>
                </div>

                <Sparkline :points="spark" :tone="delta?.tone ?? 'neutral'" class="self-start" />
            </CardContent>
        </Card>
    </component>
</template>
