<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ThemeToggle from '@/Components/ThemeToggle.vue';

const page = usePage();
const appLogo = computed(() => page.props.branding?.logo ?? null);
const appShortName = computed(() => page.props.appShortName ?? 'IRNB');
const appName = computed(() => {
    const name = page.props.appName;
    const loc = page.props.locale || 'ar';
    return typeof name === 'object' ? (name[loc] || name.en || 'IRNB') : (name || 'IRNB');
});
</script>

<template>
    <div class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-slate-50 p-4 dark:bg-slate-950">
        <!-- Soft primary ambient on a clean canvas -->
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(60%_50%_at_50%_0%,theme(colors.primary.500/0.10),transparent_70%)] dark:bg-[radial-gradient(60%_50%_at_50%_0%,theme(colors.primary.500/0.18),transparent_70%)]"></div>
        <div class="pointer-events-none absolute -top-24 start-1/2 h-72 w-72 -translate-x-1/2 rounded-full bg-primary-400/20 blur-3xl dark:bg-primary-500/20"></div>

        <div class="absolute end-4 top-4">
            <ThemeToggle />
        </div>

        <div class="relative w-full max-w-md animate-fade-up">
            <div class="mb-6 flex flex-col items-center gap-3 text-center">
                <Link href="/" class="flex h-16 w-16 items-center justify-center rounded-2xl" :class="appLogo ? '' : 'bg-white shadow-card ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800'">
                    <img v-if="appLogo" :src="appLogo" :alt="appName" class="h-16 w-16 object-contain" />
                    <span v-else class="text-2xl font-extrabold text-primary-600 dark:text-primary-400">{{ appShortName.charAt(0) }}</span>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ appName }}</h1>
            </div>

            <div class="card p-6 shadow-soft sm:p-8">
                <slot />
            </div>
        </div>
    </div>
</template>
