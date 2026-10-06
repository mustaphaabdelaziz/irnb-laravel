<script setup>
defineProps({
    value: {
        type: String,
    },
    // Marks a mandatory field with a red asterisk.
    required: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
        <span v-if="required" class="ms-0.5 text-rose-600 dark:text-rose-400" aria-hidden="true">*</span>
    </label>
</template>
