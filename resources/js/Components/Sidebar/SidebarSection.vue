<script setup>
import { computed } from 'vue';
import Icon from '@/Components/Icon.vue';
import SidebarLink from '@/Components/SidebarLink.vue';
import { isActive, sectionBadge, sectionHasActive } from '@/lib/navigation';

const props = defineProps({
    section: { type: Object, required: true },
    url: { type: String, required: true },
    userOpen: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle']);

const hasActive = computed(() => sectionHasActive(props.section, props.url));
// The section of the current page is always open: closing it would hide where you are.
const open = computed(() => hasActive.value || props.userOpen);
const badge = computed(() => sectionBadge(props.section));
const panelId = computed(() => `nav-section-${props.section.key}`);

function onToggle() {
    if (hasActive.value) return;
    emit('toggle', props.section.key);
}
</script>

<template>
    <div>
        <button
            type="button"
            class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm font-semibold transition-colors hover:bg-slate-100 dark:hover:bg-slate-800"
            :class="hasActive ? 'text-slate-900 dark:text-slate-100' : 'text-slate-600 dark:text-slate-300'"
            :aria-expanded="open"
            :aria-controls="panelId"
            @click="onToggle"
        >
            <span
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-[1.05rem]"
                :class="hasActive ? 'text-primary-600 dark:text-primary-300' : 'text-slate-500 dark:text-slate-400'"
            ><Icon :name="section.icon" /></span>
            <span class="min-w-0 flex-1 truncate">{{ section.label }}</span>
            <span
                v-if="!open && badge"
                class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1.5 py-0.5 text-xs font-bold text-white shadow-sm"
            >{{ badge }}</span>
            <!-- Points down when open, toward the inline end when closed (mirrors in RTL). -->
            <Icon
                name="chevron"
                class="shrink-0 text-base text-slate-400 transition-transform duration-200 motion-reduce:transition-none"
                :class="open ? 'rotate-0' : '-rotate-90 rtl:rotate-90'"
            />
        </button>

        <!-- 0fr → 1fr animates to the content's natural height without measuring it. -->
        <div
            :id="panelId"
            class="grid transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none"
            :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
            :inert="open ? null : ''"
        >
            <div class="min-h-0 overflow-hidden">
                <div class="space-y-0.5 ps-3 pt-0.5">
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
    </div>
</template>
