<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useTheme } from '@/Composables/useTheme';
import { bubbleSide, zoomStyle } from '@/lib/helpZoom';

// A screenshot from the help script with amber rings on the targets and a
// magnifier bubble on the zoom target. Shows the shot that matches the user's
// light/dark mode; falls back to the other one, then to a placeholder.
const props = defineProps({
    figure: { type: Object, required: true },
    number: { type: Number, default: null },
    alt: { type: String, default: '' },
});

const { t } = useI18n();
const { isDark } = useTheme();
const enlarged = ref(false);

const src = computed(() => (isDark.value ? props.figure.dark ?? props.figure.light : props.figure.light ?? props.figure.dark));
const zoom = computed(() => (src.value && props.figure.zoomBox ? zoomStyle(props.figure.zoomBox, props.figure.aspect ?? 1.6) : null));
const side = computed(() => (props.figure.zoomBox ? bubbleSide(props.figure.zoomBox) : 'left'));
// Screenshots are physical pictures: position with left/top even in RTL.
const place = (r) => ({ left: `${r.x}%`, top: `${r.y}%`, width: `${r.w}%`, height: `${r.h}%` });
</script>

<template>
    <figure class="my-4">
        <div v-if="src" class="relative" :class="zoom ? 'pb-16 sm:pb-20' : ''">
            <button
                type="button"
                class="relative block w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-slate-700 dark:bg-slate-900"
                :aria-label="t('help.zoom_hint')"
                :title="t('help.zoom_hint')"
                @click="enlarged = true"
            >
                <img :src="src" :alt="alt" class="block h-auto w-full" loading="lazy" />
                <span
                    v-for="ring in figure.rings"
                    :key="ring.name"
                    class="pointer-events-none absolute rounded-md outline outline-[3px] outline-offset-2 outline-amber-500 shadow-[0_0_0_7px_rgba(245,158,11,0.28)]"
                    :style="place(ring)"
                >
                    <span
                        v-if="number"
                        class="absolute -right-3 -top-3 flex h-6 w-6 items-center justify-center rounded-full bg-amber-500 text-xs font-bold text-white shadow ring-2 ring-white dark:ring-slate-900"
                    >{{ number }}</span>
                </span>
            </button>
            <div
                v-if="zoom"
                class="pointer-events-none absolute bottom-0 h-28 w-28 rounded-full border-4 border-amber-500 bg-white bg-no-repeat shadow-lg dark:bg-slate-900 sm:h-32 sm:w-32"
                :class="side === 'left' ? 'left-3' : 'right-3'"
                :style="{ backgroundImage: `url('${src}')`, ...zoom }"
                aria-hidden="true"
            />
        </div>
        <div
            v-else
            class="flex aspect-[16/10] items-center justify-center rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-400"
        >
            {{ t('help.picture_missing') }}
        </div>

        <Teleport to="body">
            <div
                v-if="enlarged"
                class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/85 p-3 print:hidden"
                role="dialog"
                aria-modal="true"
                @click="enlarged = false"
                @keydown.esc="enlarged = false"
            >
                <img :src="src" :alt="alt" class="max-h-full max-w-full rounded-lg shadow-2xl" />
                <button type="button" class="absolute end-4 top-4 rounded-full bg-white/90 px-3 py-1 text-sm font-semibold text-slate-800" @click.stop="enlarged = false">
                    {{ t('help.close') }}
                </button>
            </div>
        </Teleport>
    </figure>
</template>
