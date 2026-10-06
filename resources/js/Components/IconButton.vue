<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

/**
 * Icon-only action button whose name slides open beside the icon on hover or
 * keyboard focus. Renders a <button>, an Inertia <Link> (href) or a plain <a>
 * (href + external). The label doubles as aria-label/title, so touch and
 * screen-reader users still get the name. Form submits (Save/Cancel/Confirm)
 * keep text buttons — this is for actions only.
 */
const props = defineProps({
    icon: { type: String, required: true },
    label: { type: String, required: true },
    variant: { type: String, default: 'neutral' }, // neutral | primary | danger | success | ghost
    size: { type: String, default: 'md' }, // sm | md
    href: { type: String, default: null },
    external: { type: Boolean, default: false }, // plain <a>: downloads, prints, new tabs
    method: { type: String, default: null },
    as: { type: String, default: null },
    type: { type: String, default: 'button' },
    pressed: { type: Boolean, default: null }, // toggle buttons (view switchers)
    plain: { type: Boolean, default: false }, // no ring/background — dense rows (table actions)
});

const tag = computed(() => {
    if (props.href) return props.external ? 'a' : Link;
    return 'button';
});

const VARIANTS = {
    neutral: 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 hover:text-slate-900 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800 dark:hover:text-white',
    primary: 'bg-primary-600 text-white shadow-sm hover:bg-primary-700',
    danger: 'bg-white text-rose-600 ring-1 ring-rose-200 hover:bg-rose-50 hover:text-rose-700 dark:bg-slate-900 dark:text-rose-300 dark:ring-rose-900/60 dark:hover:bg-rose-900/30',
    success: 'bg-white text-emerald-600 ring-1 ring-emerald-200 hover:bg-emerald-50 hover:text-emerald-700 dark:bg-slate-900 dark:text-emerald-300 dark:ring-emerald-900/60 dark:hover:bg-emerald-900/30',
    ghost: 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
};

const PLAIN = {
    neutral: 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
    primary: 'text-primary-600 hover:bg-primary-50 hover:text-primary-800 dark:text-primary-300 dark:hover:bg-primary-500/10',
    danger: 'text-rose-500 hover:bg-rose-50 hover:text-rose-700 dark:text-rose-400 dark:hover:bg-rose-900/30',
    success: 'text-emerald-600 hover:bg-emerald-50 hover:text-emerald-800 dark:text-emerald-400 dark:hover:bg-emerald-900/30',
    ghost: VARIANTS.ghost,
};

const colorClass = computed(() => {
    const map = props.plain ? PLAIN : VARIANTS;
    return map[props.variant] ?? map.neutral;
});

const SIZES = {
    sm: 'h-7 min-w-7 px-1.5 text-xs',
    md: 'h-9 min-w-9 px-2.5 text-sm',
};

const pressedClass = 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200 dark:bg-slate-700 dark:text-white dark:ring-slate-600';
</script>

<template>
    <component
        :is="tag"
        :href="href || undefined"
        :method="!external && href ? method || undefined : undefined"
        :as="!external && href ? as || undefined : undefined"
        :type="href ? undefined : type"
        :aria-label="label"
        :title="label"
        :aria-pressed="pressed === null ? undefined : pressed"
        class="group/ib inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-lg font-medium transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50 dark:focus-visible:ring-offset-slate-900"
        :class="[SIZES[size] ?? SIZES.md, pressed ? pressedClass : colorClass]"
    >
        <Icon :name="icon" class="text-[1.1em]" />
        <span
            class="grid grid-cols-[0fr] opacity-0 transition-all duration-200 ease-out group-hover/ib:grid-cols-[1fr] group-hover/ib:opacity-100 group-focus-visible/ib:grid-cols-[1fr] group-focus-visible/ib:opacity-100 motion-reduce:transition-none"
            aria-hidden="true"
        >
            <span class="overflow-hidden ps-0 transition-[padding] duration-200 group-hover/ib:ps-1.5 group-focus-visible/ib:ps-1.5">{{ label }}</span>
        </span>
        <slot />
    </component>
</template>
