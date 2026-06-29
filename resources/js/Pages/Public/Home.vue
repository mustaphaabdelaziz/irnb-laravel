<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';

const { t } = useI18n();
const page = usePage();

defineProps({
    club: Object,
    canLogin: Boolean,
    canRegister: Boolean,
});

const user = computed(() => page.props.auth?.user);

// label drives the accessible name; icon maps to the brand glyph in Icon.vue.
const socialMeta = {
    facebook: { label: 'Facebook', icon: 'facebook' },
    instagram: { label: 'Instagram', icon: 'instagram' },
    twitter: { label: 'X', icon: 'x' },
    youtube: { label: 'YouTube', icon: 'youtube' },
    linkedin: { label: 'LinkedIn', icon: 'linkedin' },
    tiktok: { label: 'TikTok', icon: 'tiktok' },
};
</script>

<template>
    <Head :title="club.name || 'IRNB'" />

    <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
        <!-- Top bar -->
        <header class="sticky top-0 z-20 border-b border-slate-200/70 dark:border-slate-800 bg-white/70 dark:bg-slate-900 backdrop-blur-xl">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
                <div class="flex items-center gap-3">
                    <img v-if="club.logo" :src="club.logo" :alt="club.name" class="h-14 w-14 object-contain" />
                    <span v-else class="flex h-14 w-14 items-center justify-center rounded-xl bg-gradient-to-br from-primary-500 to-primary-700 text-2xl font-bold text-white shadow-glow">{{ (club.shortName || club.name || 'I').charAt(0) }}</span>
                    <span class="text-base font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ club.name }}</span>
                </div>
                <nav class="flex items-center gap-1.5">
                    <ThemeToggle />
                    <Link v-if="user" :href="route('dashboard')" class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm ring-1 ring-inset ring-primary-500/40 transition hover:bg-primary-700 hover:shadow-glow active:scale-[0.98]">{{ t('dashboard') }}<Icon name="arrow" class="text-sm rtl:rotate-180" /></Link>
                    <template v-else>
                        <Link v-if="canLogin" :href="route('login')" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 transition-colors hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-slate-100">{{ t('login') }}</Link>
                        <Link v-if="canRegister" :href="route('register')" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm ring-1 ring-inset ring-primary-500/40 transition hover:bg-primary-700 hover:shadow-glow active:scale-[0.98]">{{ t('register') }}</Link>
                    </template>
                </nav>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative isolate overflow-hidden bg-gradient-to-b from-primary-50/70 via-white to-white text-slate-900 dark:from-slate-900 dark:via-slate-950 dark:to-slate-950 dark:text-slate-100">
            <!-- Optional club photo, kept faint so dark text stays legible -->
            <div
                v-if="club.heroImages?.length"
                class="absolute inset-0 -z-10 scale-105 bg-cover bg-center opacity-[0.12]"
                :style="{ backgroundImage: `url(${club.heroImages[0]})` }"
            />
            <!-- Ambient primary light + soft bloom -->
            <div class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(70%_55%_at_50%_-10%,theme(colors.primary.500/0.16),transparent_70%)]"></div>
            <div class="pointer-events-none absolute -top-32 start-1/2 -z-10 h-96 w-96 -translate-x-1/2 rounded-full bg-primary-300/25 blur-3xl"></div>
            <div class="pointer-events-none absolute inset-x-0 bottom-0 -z-10 h-32 bg-gradient-to-b from-transparent to-white dark:to-slate-950"></div>

            <div class="relative mx-auto flex min-h-[78vh] max-w-4xl flex-col items-center justify-center px-4 py-24 text-center">
                <img v-if="club.logo" :src="club.logo" :alt="club.name" class="mx-auto mb-8 h-36 w-36 object-contain animate-scale-in sm:h-44 sm:w-44" />
                <p v-if="club.motto" class="eyebrow mb-5 text-primary-600">{{ club.motto }}</p>
                <h1 class="text-balance text-5xl font-extrabold leading-[1.02] tracking-tight text-slate-900 dark:text-slate-100 sm:text-6xl lg:text-7xl">{{ club.name }}</h1>
                <p v-if="club.tagline" class="mx-auto mt-6 max-w-2xl text-pretty text-lg leading-relaxed text-slate-600 dark:text-slate-300">{{ club.tagline }}</p>
                <div class="mt-10 flex flex-wrap justify-center gap-3">
                    <Link v-if="user" :href="route('dashboard')" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-primary-600/25 ring-1 ring-inset ring-primary-500/40 transition hover:bg-primary-700 hover:shadow-glow active:scale-[0.98]">{{ t('dashboard') }}<Icon name="arrow" class="rtl:rotate-180" /></Link>
                    <Link v-else-if="canLogin" :href="route('login')" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-primary-600/25 ring-1 ring-inset ring-primary-500/40 transition hover:bg-primary-700 hover:shadow-glow active:scale-[0.98]">{{ t('login') }}<Icon name="arrow" class="rtl:rotate-180" /></Link>
                    <a href="#contact" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-6 py-3 text-sm font-semibold text-slate-700 dark:text-slate-200 shadow-sm transition hover:border-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800 active:scale-[0.98]">{{ t('contact') }}</a>
                </div>
            </div>

            <!-- Scroll cue -->
            <div class="pointer-events-none absolute inset-x-0 bottom-5 flex justify-center text-slate-300">
                <Icon name="chevron" class="animate-bounce text-2xl" />
            </div>
        </section>

        <!-- About -->
        <section v-if="club.description || club.founder || club.foundingYear || Object.keys(club.leadership || {}).length" class="relative bg-white dark:bg-slate-900 py-20">
            <div class="mx-auto max-w-4xl px-4">
                <div class="text-center">
                    <p class="eyebrow text-primary-600">{{ t('about') }}</p>
                    <h2 class="mt-2 text-balance text-3xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ club.name }}</h2>
                </div>
                <p v-if="club.description" class="mx-auto mt-5 max-w-2xl text-pretty text-center text-lg leading-relaxed text-slate-600 dark:text-slate-300">{{ club.description }}</p>

                <div class="mt-12 grid gap-4 sm:grid-cols-3">
                    <div v-if="club.foundingYear" class="card-interactive p-6 text-center">
                        <p class="figure text-4xl font-extrabold text-gradient">{{ club.foundingYear }}</p>
                        <p class="eyebrow mt-2 text-slate-500 dark:text-slate-400">{{ t('founding_year') }}</p>
                    </div>
                    <div v-if="club.founder" class="card-interactive flex flex-col items-center justify-center p-6 text-center">
                        <p class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ club.founder }}</p>
                        <p class="eyebrow mt-2 text-slate-500 dark:text-slate-400">{{ t('founder') }}</p>
                    </div>
                    <div v-if="club.leadership?.president" class="card-interactive flex flex-col items-center justify-center p-6 text-center">
                        <p class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ club.leadership.president }}</p>
                        <p class="eyebrow mt-2 text-slate-500 dark:text-slate-400">{{ t('president') }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contact -->
        <section id="contact" class="relative scroll-mt-16 overflow-hidden bg-slate-50 dark:bg-slate-950 py-20">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(60%_60%_at_50%_0%,theme(colors.primary.500/0.06),transparent_70%)]"></div>
            <div class="relative mx-auto max-w-4xl px-4">
                <div class="text-center">
                    <p class="eyebrow text-primary-600">{{ t('contact') }}</p>
                    <h2 class="mt-2 text-3xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ t('contact') }}</h2>
                </div>
                <div class="mt-10 grid gap-4 sm:grid-cols-2">
                    <a v-if="club.email" :href="`mailto:${club.email}`" class="card-interactive group flex items-center gap-4 p-5">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-primary-100 to-primary-50 text-xl text-primary-600 ring-1 ring-inset ring-primary-200/70"><Icon name="mail" /></span>
                        <div class="min-w-0">
                            <p class="eyebrow text-slate-500 dark:text-slate-400">{{ t('email') }}</p>
                            <p class="truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ club.email }}</p>
                        </div>
                    </a>
                    <a v-if="club.phone" :href="`tel:${club.phone}`" class="card-interactive group flex items-center gap-4 p-5">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-primary-100 to-primary-50 text-xl text-primary-600 ring-1 ring-inset ring-primary-200/70"><Icon name="phone" /></span>
                        <div class="min-w-0">
                            <p class="eyebrow text-slate-500 dark:text-slate-400">{{ t('phone') }}</p>
                            <p class="jersey truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ club.phone }}</p>
                        </div>
                    </a>
                    <div v-if="club.address" class="card flex items-center gap-4 p-5 sm:col-span-2">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-primary-100 to-primary-50 text-xl text-primary-600 ring-1 ring-inset ring-primary-200/70"><Icon name="location" /></span>
                        <div class="min-w-0">
                            <p class="eyebrow text-slate-500 dark:text-slate-400">{{ t('address') }}</p>
                            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ club.address }}</p>
                        </div>
                    </div>
                </div>

                <div v-if="Object.keys(club.social || {}).length" class="mt-10 flex flex-wrap justify-center gap-2.5">
                    <a v-for="(url, key) in club.social" :key="key" :href="url" target="_blank" rel="noopener"
                        :title="(socialMeta[key] && socialMeta[key].label) || key" :aria-label="(socialMeta[key] && socialMeta[key].label) || key"
                        class="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-lg text-slate-500 dark:text-slate-400 shadow-sm transition hover:-translate-y-0.5 hover:border-primary-300 hover:text-primary-600 hover:shadow-card-hover active:scale-95">
                        <Icon :name="(socialMeta[key] && socialMeta[key].icon) || 'external'" />
                    </a>
                </div>
            </div>
        </section>

        <footer class="relative border-t border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 py-8 text-center">
            <div class="pointer-events-none absolute inset-x-0 top-0 mx-auto h-px max-w-md bg-gradient-to-r from-transparent via-primary-400/40 to-transparent"></div>
            <p class="text-sm text-slate-500 dark:text-slate-400">© {{ new Date().getFullYear() }} {{ club.name }}</p>
        </footer>
    </div>
</template>
