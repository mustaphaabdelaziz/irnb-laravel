<script setup>
import { cn } from '@/lib/utils';
defineProps({ orientation: { type: String, default: 'horizontal' }, class: { type: null, required: false } });
</script>
<template><div role="separator" :aria-orientation="orientation" :class="cn('shrink-0 bg-border', orientation === 'horizontal' ? 'h-px w-full' : 'h-full w-px', $props.class)" /></template>
