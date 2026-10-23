<script setup>
import { useI18n } from 'vue-i18n';

/**
 * The attendance pages' branch (sport) switch: one branch at a time, shown
 * when the club has two or more. The server remembers the choice
 * (BranchChoice); the page navigates on `switch`.
 */
defineProps({
    branches: { type: Array, default: () => [] }, // [{ id, name, logo_url? }]
    branchId: { type: Number, default: null },
});
const emit = defineEmits(['switch']);
const { t } = useI18n();
</script>

<template>
    <div v-if="branches.length > 1" role="tablist" :aria-label="t('branch')" class="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
        <button
            v-for="b in branches"
            :key="b.id"
            type="button"
            role="tab"
            :aria-selected="branchId === b.id"
            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-sm font-semibold"
            :class="branchId === b.id ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-slate-100' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
            @click="branchId !== b.id && emit('switch', b.id)"
        >
            <img v-if="b.logo_url" :src="b.logo_url" alt="" class="h-4 w-4 rounded object-contain" />
            {{ b.name }}
        </button>
    </div>
</template>
