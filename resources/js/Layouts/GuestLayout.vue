<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { useClubIdentity } from '@/Composables/useClubIdentity';
import { PRODUCT_LOGO, PRODUCT_MARK, PRODUCT_NAME } from '@/lib/productBrand';

const { t } = useI18n();
const page = usePage();
const appLogo = computed(() => page.props.branding?.logo ?? null);
const { appName, appShortName } = useClubIdentity();
// A club that set neither a logo nor a name gets the full ASIREM logo instead
// of a letter tile over the product name; the footer would then repeat it.
const productOnly = computed(() => !appLogo.value && appShortName.value === PRODUCT_NAME);
</script>

<template>
    <div class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-slate-50 p-4 dark:bg-slate-950">
        <!-- Guest-side flashes (password reset, verification) had nowhere to render. -->
        <FlashMessages />
        <!-- Soft primary ambient on a clean canvas -->
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(60%_50%_at_50%_0%,theme(colors.primary.500/0.10),transparent_70%)] dark:bg-[radial-gradient(60%_50%_at_50%_0%,theme(colors.primary.500/0.18),transparent_70%)]"></div>
        <div class="pointer-events-none absolute -top-24 start-1/2 h-72 w-72 -translate-x-1/2 rounded-full bg-primary-400/20 blur-3xl dark:bg-primary-500/20"></div>

        <div class="absolute end-4 top-4">
            <ThemeToggle />
        </div>

        <div class="relative w-full max-w-md animate-fade-up">
            <div v-if="productOnly" class="mb-6 flex justify-center">
                <Link href="/">
                    <h1 class="sr-only">{{ PRODUCT_NAME }}</h1>
                    <img :src="PRODUCT_LOGO.light" :alt="PRODUCT_NAME" class="h-40 w-40 object-contain dark:hidden" />
                    <img :src="PRODUCT_LOGO.dark" :alt="PRODUCT_NAME" class="hidden h-40 w-40 object-contain dark:block" />
                </Link>
            </div>
            <div v-else class="mb-6 flex flex-col items-center gap-3 text-center">
                <Link href="/" class="flex h-16 w-16 items-center justify-center rounded-2xl">
                    <img v-if="appLogo" :src="appLogo" :alt="appName" class="h-16 w-16 object-contain" />
                    <template v-else>
                        <img :src="PRODUCT_MARK.light" :alt="appName" class="h-16 w-16 object-contain dark:hidden" />
                        <img :src="PRODUCT_MARK.dark" :alt="appName" class="hidden h-16 w-16 object-contain dark:block" />
                    </template>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ appName }}</h1>
            </div>

            <div class="card p-6 shadow-soft sm:p-8">
                <slot />
            </div>

            <p v-if="!productOnly" class="mt-6 flex items-center justify-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <img :src="PRODUCT_MARK.light" alt="" class="h-5 w-5 object-contain dark:hidden" />
                <img :src="PRODUCT_MARK.dark" alt="" class="hidden h-5 w-5 object-contain dark:block" />
                <span><span class="font-bold text-slate-700 dark:text-slate-200">{{ PRODUCT_NAME }}</span> · {{ t('product_tagline') }}</span>
            </p>
        </div>
    </div>
</template>
