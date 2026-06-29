<script setup>
defineProps({
    label: { type: String, required: true },
    color: { type: String, default: 'slate' },
    dot: { type: Boolean, default: false },
});

const colorMap = {
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/25',
    emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/25',
    red: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/15 dark:text-rose-300 dark:ring-rose-500/25',
    rose: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/15 dark:text-rose-300 dark:ring-rose-500/25',
    yellow: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/25',
    amber: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/25',
    blue: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/25',
    slate: 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-500/25',
    primary: 'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-500/15 dark:text-primary-300 dark:ring-primary-500/25',
};

const dotColor = {
    green: 'bg-emerald-500', emerald: 'bg-emerald-500',
    red: 'bg-rose-500', rose: 'bg-rose-500',
    yellow: 'bg-amber-500', amber: 'bg-amber-500',
    blue: 'bg-blue-500', slate: 'bg-slate-400', primary: 'bg-primary-500',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
        :class="colorMap[color] || colorMap.slate"
    >
        <span v-if="dot" class="h-1.5 w-1.5 rounded-full" :class="dotColor[color] || dotColor.slate" />
        {{ label }}
    </span>
</template>
