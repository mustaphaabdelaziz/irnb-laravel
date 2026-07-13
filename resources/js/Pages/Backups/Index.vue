<script setup>
// Placeholder page: Task 5 (routes/controller/job) needs a real component here
// so Inertia has something to render, but the actual UI (destination picker,
// settings form, backup list with restore/delete/reveal) is Task 6's job.
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    settings: { type: Object, required: true },
    destinationWritable: { type: Boolean, required: true },
    backups: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Backups" />

    <AuthenticatedLayout>
        <div class="p-6">
            <p class="text-sm text-gray-500">Backup &amp; restore UI coming soon.</p>
        </div>
    </AuthenticatedLayout>
</template>
