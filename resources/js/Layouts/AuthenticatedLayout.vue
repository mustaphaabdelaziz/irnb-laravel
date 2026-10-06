<script>
// Module scope: survives layout remounts across Inertia navigations.
let sidebarScrollTop = 0;
let backupHeartbeat = null;
</script>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SidebarLink from '@/Components/SidebarLink.vue';
import SidebarSection from '@/Components/Sidebar/SidebarSection.vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import { useNavigation } from '@/Composables/useNavigation.js';
import { useSidebarState } from '@/Composables/useSidebarState.js';
import { isActive } from '@/lib/navigation';
import { useCan } from '@/Composables/useCan.js';
import { useClubIdentity } from '@/Composables/useClubIdentity';
import FlashMessages from '@/Components/FlashMessages.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import Icon from '@/Components/Icon.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';

const { t, locale: i18nLocale } = useI18n();
const page = usePage();
const { isSuperadmin } = useCan();
const { sections, url } = useNavigation();
const { isOpen, toggleSection } = useSidebarState();

const mobileMenuOpen = ref(false);
const paletteOpen = ref(false);
const shortcutHint = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/i.test(navigator.platform) ? '⌘K' : 'Ctrl K';

// The layout remounts on every Inertia visit, which resets the sidebar's
// scroll position to the top — annoying when clicking items near the bottom.
// Persist scrollTop in module scope (survives remounts within the SPA session)
// and restore it before paint.
const navEl = ref(null);
function rememberNavScroll() {
    sidebarScrollTop = navEl.value?.scrollTop ?? 0;
}

// A desktop app only runs when it is open, and there is no scheduler. So the
// automatic backup is driven from here: once per app launch, then every 30
// minutes while the window stays open. The server decides whether one is
// actually due — this just knocks on the door.
function tickBackup(trigger) {
    window.axios.post('/backups/tick', { trigger }).catch(() => {});
}

function stopBackupHeartbeat() {
    if (backupHeartbeat !== null) {
        clearInterval(backupHeartbeat);
        backupHeartbeat = null;
    }
}

onMounted(() => {
    if (navEl.value) navEl.value.scrollTop = sidebarScrollTop;

    if (!isDesktop.value || !isSuperadmin.value) {
        // The interval is module-scoped so it survives the layout remounting on every
        // Inertia visit — but that means it also survives a change of USER. A superadmin
        // logging out and someone else logging in happens without a full page reload, so
        // an interval started for the superadmin would keep POSTing /backups/tick every
        // 30 minutes as a user who is not allowed to, collecting a 403 that .catch()
        // swallows. Tear it down when the gate that opened it no longer holds.
        stopBackupHeartbeat();

        return;
    }

    // sessionStorage is cleared when the Electron window closes, so this fires
    // exactly once per app launch — not on every Inertia navigation.
    if (!sessionStorage.getItem('backupLaunchTick')) {
        sessionStorage.setItem('backupLaunchTick', '1');
        tickBackup('launch');
    }

    if (backupHeartbeat === null) {
        backupHeartbeat = setInterval(() => tickBackup('heartbeat'), 30 * 60 * 1000);
    }
});

const user = computed(() => page.props.auth.user);
const isAdmin = computed(() => page.props.auth?.isAdmin ?? false);
const isDesktop = computed(() => page.props.isDesktop ?? false);
const currentLocale = computed(() => page.props.locale || 'en');
const { appName, appShortName } = useClubIdentity();
const appLogo = computed(() => page.props.branding?.logo ?? null);

const userInitial = computed(() => (user.value?.firstname || user.value?.name || '?').charAt(0).toUpperCase());
const userRole = computed(() => (isAdmin.value ? t('administrator') : t('member')));

const locales = [
    { code: 'ar', label: 'ع' },
    { code: 'fr', label: 'FR' },
    { code: 'en', label: 'EN' },
];

function switchLocale(code) {
    if (code === currentLocale.value) return;
    router.get(route('lang.switch', { locale: code }), {}, {
        preserveState: false,
        onSuccess: () => {
            i18nLocale.value = code;
            document.documentElement.lang = code;
            document.documentElement.dir = code === 'ar' ? 'rtl' : 'ltr';
        },
    });
}
</script>

<template>
    <div class="min-h-screen bg-slate-50 dark:bg-slate-950 print:bg-white">
        <FlashMessages />
        <CommandPalette v-model:open="paletteOpen" :sections="sections" />

        <!-- Mobile overlay -->
        <div
            v-if="mobileMenuOpen"
            class="fixed inset-0 z-40 bg-slate-900/60 backdrop-blur-sm dark:bg-black/70 lg:hidden"
            @click="mobileMenuOpen = false"
        />

        <!-- ===== SIDEBAR ===== -->
        <aside
            class="fixed inset-y-0 start-0 z-50 flex w-64 flex-col border-e border-slate-200 bg-white text-slate-700 transition-transform duration-300 ease-out dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 print:hidden"
            :class="mobileMenuOpen ? 'translate-x-0' : 'max-lg:-translate-x-full max-lg:rtl:translate-x-full'"
        >
            <!-- Crest -->
            <div class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 px-5 dark:border-slate-800">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl" :class="appLogo ? '' : 'bg-primary-50 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:ring-primary-500/25'">
                    <img v-if="appLogo" :src="appLogo" :alt="appShortName" class="h-full w-full object-contain" />
                    <span v-else class="text-lg font-extrabold text-primary-600 dark:text-primary-400">{{ appShortName.charAt(0) }}</span>
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-bold text-slate-900 dark:text-slate-100">{{ appShortName }}</p>
                    <p class="eyebrow truncate !text-[0.65rem] text-slate-400">{{ t('club_management') }}</p>
                </div>
            </div>

            <!-- Navigation -->
            <nav ref="navEl" @scroll.passive="rememberNavScroll" class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                <button
                    type="button"
                    class="mb-2 flex w-full items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-400 transition-colors hover:border-slate-300 hover:text-slate-600 dark:border-slate-800 dark:bg-slate-800/50 dark:hover:border-slate-700 dark:hover:text-slate-300"
                    @click="paletteOpen = true"
                >
                    <Icon name="search" class="shrink-0 text-base" />
                    <span class="flex-1 text-start">{{ t('nav.search') }}</span>
                    <kbd class="rounded border border-slate-200 px-1.5 font-sans text-[0.65rem] font-semibold dark:border-slate-700" dir="ltr">{{ shortcutHint }}</kbd>
                </button>
                <template v-for="section in sections" :key="section.key">
                    <template v-if="section.standalone">
                        <SidebarLink
                            v-for="item in section.items"
                            :key="item.href"
                            :href="item.href"
                            :active="isActive(item, url)"
                            :icon="item.icon"
                            :badge="item.badge"
                        >{{ item.label }}</SidebarLink>
                    </template>
                    <SidebarSection
                        v-else
                        :section="section"
                        :url="url"
                        :user-open="isOpen(section.key)"
                        @toggle="toggleSection"
                    />
                </template>
            </nav>

            <!-- Footer: user + language -->
            <div class="shrink-0 space-y-3 border-t border-slate-200 p-3 dark:border-slate-800">
                <Link :href="route('profile.edit')" class="flex items-center gap-3 rounded-xl p-2 transition-colors hover:bg-slate-100 dark:hover:bg-slate-800">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary-500 to-primary-600 text-sm font-bold text-white ring-1 ring-inset ring-primary-400/40">{{ userInitial }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ user?.firstname || user?.name }}</span>
                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ userRole }}</span>
                    </span>
                </Link>
                <div class="flex items-center gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
                    <button
                        v-for="loc in locales"
                        :key="loc.code"
                        @click="switchLocale(loc.code)"
                        class="flex-1 rounded-lg py-1.5 text-xs font-bold transition-colors"
                        :class="currentLocale === loc.code ? 'bg-primary-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'"
                    >{{ loc.label }}</button>
                </div>
            </div>
        </aside>

        <!-- ===== MAIN ===== -->
        <div class="lg:ms-64 print:ms-0">
            <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200/80 bg-white/75 px-4 backdrop-blur-xl dark:border-slate-800/80 dark:bg-slate-900/75 sm:px-6 print:static print:border-0 print:shadow-none">
                <button
                    @click="mobileMenuOpen = !mobileMenuOpen"
                    class="-ms-1 rounded-lg p-2 text-xl text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200 lg:hidden print:hidden"
                    :aria-label="t('toggle_menu')"
                >
                    <Icon name="menu" />
                </button>

                <div class="min-w-0 flex-1">
                    <slot name="header" />
                </div>

                <ThemeToggle class="print:hidden" />

                <a :href="route('home')" target="_blank" class="hidden rounded-lg p-2 text-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-200 sm:block print:hidden" :title="t('home')">
                    <Icon name="external" />
                </a>

                <Dropdown align="right" width="48" class="print:hidden">
                    <template #trigger>
                        <button class="flex items-center gap-2 rounded-full p-1 pe-2 transition-colors hover:bg-slate-100 dark:hover:bg-slate-800">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-primary-500 to-primary-600 text-sm font-bold text-white ring-1 ring-inset ring-primary-400/40">{{ userInitial }}</span>
                            <span class="hidden text-sm font-medium text-slate-700 dark:text-slate-200 sm:inline">{{ user?.firstname || user?.name }}</span>
                            <Icon name="chevron" class="text-base text-slate-400" />
                        </button>
                    </template>
                    <template #content>
                        <DropdownLink :href="route('profile.edit')">{{ t('profile') }}</DropdownLink>
                        <DropdownLink :href="route('profile.activity')">{{ t('activity.my_title') }}</DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button">{{ t('logout') }}</DropdownLink>
                    </template>
                </Dropdown>
            </header>

            <main class="mx-auto max-w-7xl animate-fade-up p-4 sm:p-6 lg:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>
