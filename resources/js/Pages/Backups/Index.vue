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

    // The outcome of the last restore. NOT a flash message: a restore relaunches the
    // app, and the flash dies with the process before it can be shown (see
    // BackupSettings::recordRestore()). Task 6 must render this as a PERSISTENT,
    // dismissible banner — no auto-dismiss, no max-w-md clipping, the paths selectable
    // and copyable, because they are Windows paths the user has to act on:
    //
    //   null, or {
    //     outcome: 'success' | 'failed_after_swap' | 'failed',
    //     message: string,          // plain English, ready to display as-is
    //     snapshot: string | null,  // pre-restore snapshot; the way back after a failed_after_swap
    //     leftover_media: string | null, // duplicate media folder the user must delete by hand
    //     at: string | null,        // ISO-8601
    //   }
    //
    // Dismiss it with: router.delete(route('backups.last-restore.dismiss'))
    lastRestore: { type: Object, default: null },
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
