<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';

// The `?` side panel: the help articles that declare this page in `pages:`.
const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['update:open']);

const { t } = useI18n();
const page = usePage();
const articles = ref([]);
const loading = ref(false);
let loadedFor = null;

function close() {
    emit('update:open', false);
}

watch(() => props.open, async (isOpen) => {
    if (!isOpen) return;
    const name = page.props.routeName ?? '';
    if (loadedFor === name) return;
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('help.panel'), { params: { route: name } });
        articles.value = data.articles;
        loadedFor = name;
    } catch {
        articles.value = [];
    } finally {
        loading.value = false;
    }
});

const onKey = (e) => { if (e.key === 'Escape' && props.open) close(); };
let stopNavigate = null;
onMounted(() => {
    document.addEventListener('keydown', onKey);
    stopNavigate = router.on('navigate', close);
});
onUnmounted(() => {
    document.removeEventListener('keydown', onKey);
    stopNavigate?.();
});
</script>

<template>
    <Teleport to="body">
        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition-opacity duration-150" leave-active-class="transition-opacity duration-150">
            <div v-if="open" class="fixed inset-0 z-50 print:hidden">
                <div class="absolute inset-0 bg-slate-900/40 dark:bg-black/60" @click="close" />
                <aside role="dialog" aria-modal="true" :aria-label="t('help.this_page')" class="absolute inset-y-0 end-0 flex w-full max-w-sm flex-col bg-white shadow-2xl dark:bg-slate-900">
                    <header class="flex items-center gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-50 text-lg text-primary-600 dark:bg-primary-500/15 dark:text-primary-300"><Icon name="help" /></span>
                        <h2 class="flex-1 font-bold text-slate-900 dark:text-slate-100">{{ t('help.this_page') }}</h2>
                        <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" :aria-label="t('help.close')" @click="close"><Icon name="close" /></button>
                    </header>

                    <div class="flex-1 space-y-2 overflow-y-auto p-4">
                        <template v-if="loading">
                            <div v-for="n in 3" :key="n" class="h-16 animate-pulse rounded-xl bg-slate-100 dark:bg-slate-800" />
                        </template>
                        <template v-else-if="articles.length">
                            <Link v-for="a in articles" :key="a.key" :href="a.href" class="block rounded-xl border border-slate-200 p-3 hover:border-primary-400 hover:bg-primary-50/50 dark:border-slate-800 dark:hover:border-primary-500 dark:hover:bg-primary-500/10">
                                <p class="font-semibold text-slate-900 dark:text-slate-100">{{ a.title }}</p>
                                <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ a.summary }}</p>
                                <p class="mt-1 text-xs text-slate-400">⏱ {{ t('help.minutes', { n: a.minutes }) }}</p>
                            </Link>
                        </template>
                        <p v-else class="py-8 text-center text-slate-500 dark:text-slate-400">{{ t('help.no_articles') }}</p>
                    </div>

                    <footer class="border-t border-slate-200 p-4 dark:border-slate-800">
                        <Link :href="route('help.index')" class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 font-semibold text-white hover:bg-primary-700">
                            <Icon name="help" /> {{ t('help.open_center') }}
                        </Link>
                    </footer>
                </aside>
            </div>
        </Transition>
    </Teleport>
</template>
