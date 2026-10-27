<script setup>
import Icon from '@/Components/Icon.vue';

/**
 * One box of the board organigram. Filled: photo, name and the seat it holds;
 * empty: a dashed "+ seat" box. Either way a click opens the seat's editor.
 */
defineProps({
    member: { type: Object, default: null },
    label: { type: String, required: true },
    // 'lg' for the top row, 'md' for the bureau and committee heads, 'sm' for the rest.
    size: { type: String, default: 'md' },
    disabled: { type: Boolean, default: false },
});
defineEmits(['select']);

function initial(n) { return (n || '?').charAt(0).toUpperCase(); }
</script>

<template>
    <button v-if="member" type="button" :disabled="disabled" @click="$emit('select')"
        class="group flex w-full items-center gap-2.5 rounded-xl bg-white text-start shadow-sm ring-1 ring-slate-200 transition hover:shadow-md hover:ring-primary-300 disabled:cursor-default disabled:hover:shadow-sm disabled:hover:ring-slate-200 dark:bg-slate-900 dark:ring-slate-700 dark:hover:ring-primary-500/50"
        :class="{ 'p-3': size !== 'sm', 'p-2': size === 'sm' }">
        <span class="flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br from-primary-500 to-primary-600 font-bold text-white"
            :class="{ 'h-12 w-12 text-lg': size === 'lg', 'h-10 w-10': size === 'md', 'h-8 w-8 text-sm': size === 'sm' }">
            <img v-if="member.photo_url" :src="member.photo_url" :alt="member.name" class="h-full w-full object-cover" />
            <span v-else>{{ initial(member.name) }}</span>
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate font-bold text-slate-900 dark:text-slate-100" :class="size === 'sm' ? 'text-xs' : 'text-sm'">{{ member.name }}</span>
            <span class="block truncate text-xs font-semibold text-primary-600 dark:text-primary-400">{{ label }}</span>
        </span>
    </button>
    <button v-else type="button" :disabled="disabled" @click="$emit('select')"
        class="flex w-full items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-slate-300 text-xs font-semibold text-slate-400 transition hover:border-primary-400 hover:bg-primary-50/50 hover:text-primary-600 disabled:cursor-default disabled:hover:border-slate-300 disabled:hover:bg-transparent disabled:hover:text-slate-400 dark:border-slate-700 dark:hover:border-primary-500/60 dark:hover:bg-primary-500/5"
        :class="{ 'min-h-[4.5rem] p-3': size === 'lg', 'min-h-[4rem] p-3': size === 'md', 'min-h-[3rem] p-2': size === 'sm' }">
        <Icon v-if="!disabled" name="plus" class="h-4 w-4" />
        <span class="truncate">{{ label }}</span>
    </button>
</template>
