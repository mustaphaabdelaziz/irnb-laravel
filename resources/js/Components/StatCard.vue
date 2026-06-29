<script setup>
import Icon from '@/Components/Icon.vue';

defineProps({
    label: String,
    value: [String, Number],
    icon: { type: String, default: null },
    color: { type: String, default: 'slate' },
    suffix: { type: String, default: '' },
});

const valueColor = {
    slate: 'text-slate-900 dark:text-slate-100',
    emerald: 'text-emerald-700 dark:text-emerald-400',
    primary: 'text-primary-700 dark:text-primary-400',
    rose: 'text-rose-700 dark:text-rose-400',
    amber: 'text-amber-700 dark:text-amber-400',
};

// Soft gradient chips read more crafted than a flat tint.
const chip = {
    slate: 'from-slate-100 to-slate-50 text-slate-600 ring-slate-200/70 dark:from-slate-700 dark:to-slate-800 dark:text-slate-300 dark:ring-slate-700',
    emerald: 'from-emerald-100 to-emerald-50 text-emerald-600 ring-emerald-200/70 dark:from-emerald-500/20 dark:to-emerald-500/5 dark:text-emerald-300 dark:ring-emerald-500/25',
    primary: 'from-primary-100 to-primary-50 text-primary-600 ring-primary-200/70 dark:from-primary-500/20 dark:to-primary-500/5 dark:text-primary-300 dark:ring-primary-500/25',
    rose: 'from-rose-100 to-rose-50 text-rose-600 ring-rose-200/70 dark:from-rose-500/20 dark:to-rose-500/5 dark:text-rose-300 dark:ring-rose-500/25',
    amber: 'from-amber-100 to-amber-50 text-amber-600 ring-amber-200/70 dark:from-amber-500/20 dark:to-amber-500/5 dark:text-amber-300 dark:ring-amber-500/25',
};

// Hairline accent along the top edge, tinted to the stat's meaning.
const accent = {
    slate: 'before:from-slate-300/0 before:via-slate-400/40 before:to-slate-300/0',
    emerald: 'before:from-emerald-400/0 before:via-emerald-500/60 before:to-emerald-400/0',
    primary: 'before:from-primary-400/0 before:via-primary-500/60 before:to-primary-400/0',
    rose: 'before:from-rose-400/0 before:via-rose-500/60 before:to-rose-400/0',
    amber: 'before:from-amber-400/0 before:via-amber-500/60 before:to-amber-400/0',
};
</script>

<template>
    <article
        class="card-interactive relative overflow-hidden p-5 before:absolute before:inset-x-5 before:top-0 before:h-px before:bg-gradient-to-r before:content-['']"
        :class="accent[color] || accent.slate"
    >
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ label }}</p>
            <span
                v-if="icon"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br text-[1.15rem] ring-1 ring-inset"
                :class="chip[color] || chip.slate"
            ><Icon :name="icon" /></span>
        </div>
        <p class="figure mt-3 text-3xl font-extrabold" :class="valueColor[color] || valueColor.slate">
            {{ value }}<span v-if="suffix" class="ms-1 text-base font-medium text-slate-400">{{ suffix }}</span>
        </p>
        <slot />
    </article>
</template>
