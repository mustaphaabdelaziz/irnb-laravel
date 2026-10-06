<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

defineProps({
    href: { type: String, required: true },
    active: { type: Boolean, default: false },
    icon: { type: String, default: null },
    badge: { type: [Number, String], default: null },
    // Tighter rows, used inside sections and the narrow-mode flyout.
    compact: { type: Boolean, default: false },
    // Icon alone (narrow rail). Pass `title`/`aria-label` as attributes.
    iconOnly: { type: Boolean, default: false },
});
</script>

<template>
    <!-- Hover prefetch: the page is usually loaded before the click lands. -->
    <Link
        :href="href"
        prefetch
        cache-for="30s"
        class="group relative flex items-center rounded-xl text-sm font-medium transition-all duration-200"
        :class="[
            iconOnly ? 'justify-center p-1.5' : compact ? 'gap-2.5 px-2.5 py-1.5' : 'gap-3 px-3 py-2.5',
            active
                ? 'bg-primary-50 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300'
                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100',
        ]"
    >
        <!-- RTL-aware active accent bar -->
        <span v-if="active && !iconOnly" class="absolute inset-y-2 start-0 w-1 rounded-full bg-primary-500"></span>

        <span
            v-if="icon"
            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-[1.05rem] transition-colors"
            :class="active ? 'bg-primary-100 text-primary-600 ring-1 ring-inset ring-primary-200 dark:bg-primary-500/20 dark:text-primary-300 dark:ring-primary-500/30' : 'bg-slate-100 text-slate-500 group-hover:text-slate-700 dark:bg-slate-800 dark:text-slate-400 dark:group-hover:text-slate-200'"
        ><Icon :name="icon" /></span>

        <span :class="iconOnly ? 'sr-only' : 'truncate'"><slot /></span>

        <span
            v-if="badge"
            class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1.5 py-0.5 text-xs font-bold text-white shadow-sm"
            :class="iconOnly ? 'absolute -end-1 -top-1 !min-w-[1rem] !px-1 !py-0 !text-[0.6rem]' : 'ms-auto'"
        >{{ badge }}</span>
    </Link>
</template>
