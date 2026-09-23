<script setup>
import Icon from '@/Components/Icon.vue';

/**
 * One line of a member's record: an icon to find it by, the label that says
 * what it is, and the value. The icon never replaces the label — it is there
 * to make the page scannable, not to be decoded.
 */
defineProps({
    icon: { type: String, required: true },
    label: { type: String, required: true },
    value: { type: [String, Number], default: '' },
    mono: { type: Boolean, default: false },
});
</script>

<template>
    <div class="flex items-start gap-2.5">
        <span class="mt-0.5 text-slate-400 dark:text-slate-500"><Icon :name="icon" /></span>
        <div class="min-w-0">
            <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ label }}</dt>
            <dd class="text-sm text-slate-900 dark:text-slate-100" :class="mono ? 'font-mono' : ''">
                <slot>{{ value || '—' }}</slot>
            </dd>
        </div>
    </div>
</template>
