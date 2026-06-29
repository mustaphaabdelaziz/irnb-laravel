<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

defineProps({
    href: { type: String, required: true },
    active: { type: Boolean, default: false },
    icon: { type: String, default: null },
    badge: { type: [Number, String], default: null },
});
</script>

<template>
    <Link
        :href="href"
        class="group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-200"
        :class="active
            ? 'bg-primary-50 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300'
            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100'"
    >
        <!-- RTL-aware active accent bar -->
        <span v-if="active" class="absolute inset-y-2 start-0 w-1 rounded-full bg-primary-500"></span>

        <span
            v-if="icon"
            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-[1.05rem] transition-colors"
            :class="active ? 'bg-primary-100 text-primary-600 ring-1 ring-inset ring-primary-200 dark:bg-primary-500/20 dark:text-primary-300 dark:ring-primary-500/30' : 'bg-slate-100 text-slate-500 group-hover:text-slate-700 dark:bg-slate-800 dark:text-slate-400 dark:group-hover:text-slate-200'"
        ><Icon :name="icon" /></span>

        <span class="truncate"><slot /></span>

        <span
            v-if="badge"
            class="ms-auto inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1.5 py-0.5 text-xs font-bold text-white shadow-sm"
        >{{ badge }}</span>
    </Link>
</template>
