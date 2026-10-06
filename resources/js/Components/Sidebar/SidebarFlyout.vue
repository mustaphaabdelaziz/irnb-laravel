<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';
import SidebarLink from '@/Components/SidebarLink.vue';
import { isActive, sectionBadge, sectionHasActive } from '@/lib/navigation';

const props = defineProps({
    section: { type: Object, required: true },
    url: { type: String, required: true },
});

const open = ref(false);
const top = ref(0);
const wrapper = ref(null);
const trigger = ref(null);
const panel = ref(null);
let openTimer = null;
let closeTimer = null;
let skipFocusOpen = false;
let removeStartListener = null;

const hasActive = computed(() => sectionHasActive(props.section, props.url));
const badge = computed(() => sectionBadge(props.section));
const panelId = computed(() => `nav-flyout-${props.section.key}`);

async function show() {
    clearTimeout(openTimer);
    clearTimeout(closeTimer);
    top.value = trigger.value.getBoundingClientRect().top;
    open.value = true;
    await nextTick();
    // Keep the panel on screen when the icon sits low in the rail.
    const height = panel.value?.offsetHeight ?? 0;
    top.value = Math.max(8, Math.min(top.value, window.innerHeight - height - 8));
}

function hide() {
    clearTimeout(openTimer);
    clearTimeout(closeTimer);
    open.value = false;
}

function scheduleOpen() {
    clearTimeout(closeTimer);
    clearTimeout(openTimer);
    openTimer = setTimeout(show, 100);
}

// Grace period so the pointer can cross the gap between the icon and the panel.
function scheduleClose() {
    clearTimeout(openTimer);
    closeTimer = setTimeout(hide, 150);
}

function onFocus() {
    if (skipFocusOpen) {
        skipFocusOpen = false;
        return;
    }
    show();
}

function onFocusOut(e) {
    if (!wrapper.value?.contains(e.relatedTarget)) scheduleClose();
}

function onKeydown(e) {
    if (e.key === 'Escape' && open.value) {
        hide();
        skipFocusOpen = true;
        trigger.value?.focus();
    }
}

function onDocumentPointer(e) {
    if (open.value && !wrapper.value?.contains(e.target)) hide();
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointer);
    removeStartListener = router.on('start', hide);
});

onUnmounted(() => {
    document.removeEventListener('pointerdown', onDocumentPointer);
    removeStartListener?.();
    clearTimeout(openTimer);
    clearTimeout(closeTimer);
});
</script>

<template>
    <div
        ref="wrapper"
        @mouseenter="scheduleOpen"
        @mouseleave="scheduleClose"
        @focusout="onFocusOut"
        @keydown="onKeydown"
    >
        <button
            ref="trigger"
            type="button"
            class="relative flex w-full items-center justify-center rounded-xl p-1.5 transition-colors"
            :class="hasActive || open
                ? 'bg-primary-50 text-primary-600 dark:bg-primary-500/15 dark:text-primary-300'
                : 'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800'"
            :aria-label="section.label"
            :aria-expanded="open"
            :aria-controls="panelId"
            @click="show"
            @focus="onFocus"
        >
            <span class="flex h-7 w-7 items-center justify-center text-[1.05rem]"><Icon :name="section.icon" /></span>
            <span v-if="hasActive" class="absolute bottom-1 end-1 h-1.5 w-1.5 rounded-full bg-primary-500"></span>
            <span
                v-if="badge"
                class="absolute -end-0.5 -top-0.5 inline-flex min-w-[1rem] items-center justify-center rounded-full bg-rose-500 px-1 text-[0.6rem] font-bold text-white shadow-sm"
            >{{ badge }}</span>
        </button>

        <!-- Fixed, so the nav's overflow scroll can't clip it; `start-16` sits it
             against the rail on either side (mirrors in RTL). -->
        <div
            v-show="open"
            :id="panelId"
            ref="panel"
            class="fixed start-16 z-50 ms-1 w-60 rounded-xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-800 dark:bg-slate-900"
            :style="{ top: `${top}px` }"
        >
            <p class="eyebrow px-2 pb-1 pt-0.5 !text-[0.65rem] text-slate-400">{{ section.label }}</p>
            <div class="space-y-0.5">
                <SidebarLink
                    v-for="item in section.items"
                    :key="item.href"
                    :href="item.href"
                    :active="isActive(item, url)"
                    :icon="item.icon"
                    :badge="item.badge"
                    compact
                >{{ item.label }}</SidebarLink>
            </div>
        </div>
    </div>
</template>
