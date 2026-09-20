<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

/**
 * A single thing that needs attention.
 *
 * Severity always ships with an icon and a label — colour alone is not an
 * accessible way to say "this one is worse than that one", and these chips are
 * the component most likely to be read at a glance.
 */
defineProps({
    label: { type: String, required: true },
    count: { type: Number, required: true },
    severity: { type: String, default: 'warning' },
    href: { type: String, default: null },
});

const severityClasses = {
    warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/25 dark:bg-amber-500/10 dark:text-amber-300',
    serious: 'border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-500/25 dark:bg-orange-500/10 dark:text-orange-300',
    critical: 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-500/25 dark:bg-rose-500/10 dark:text-rose-300',
};

const severityIcon = {
    warning: 'alert',
    serious: 'alert',
    critical: 'xcircle',
};
</script>

<template>
    <component
        :is="href ? Link : 'span'"
        :href="href || undefined"
        class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-opacity"
        :class="[severityClasses[severity] ?? severityClasses.warning, href ? 'hover:opacity-80' : '']"
    >
        <Icon :name="severityIcon[severity] ?? 'alert'" class="size-4 shrink-0" />
        <span class="tabular-nums">{{ count }}</span>
        <span class="font-normal">{{ label }}</span>
    </component>
</template>
