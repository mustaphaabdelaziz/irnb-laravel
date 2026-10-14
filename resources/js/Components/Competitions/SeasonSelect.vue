<script setup>
defineProps({ modelValue: Number, seasons: Array });
defineEmits(['update:modelValue']);
</script>

<template>
    <select :value="modelValue" @change="$emit('update:modelValue', Number($event.target.value))"
        class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
        <option v-for="s in seasons" :key="s.value" :value="s.value">{{ s.label }}</option>
    </select>
</template>
