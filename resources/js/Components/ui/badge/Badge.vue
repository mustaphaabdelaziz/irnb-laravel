<script setup>
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const props = defineProps({
    variant: { type: String, default: 'default' },
    class: { type: null, required: false },
});

const badgeVariants = cva('inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold transition-colors', {
    variants: {
        variant: {
            default: 'border-transparent bg-primary text-primary-foreground',
            secondary: 'border-transparent bg-secondary text-secondary-foreground',
            destructive: 'border-transparent bg-destructive text-destructive-foreground',
            outline: 'text-foreground',
        },
    },
    defaultVariants: { variant: 'default' },
});
</script>
<template><div :class="cn(badgeVariants({ variant }), props.class)"><slot /></div></template>
