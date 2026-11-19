<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({
    debts: Object,
    strip: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});
</script>

<template>
    <AuthenticatedLayout />
</template>
