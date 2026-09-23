<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage();
const show = ref(false);
const message = ref('');
const type = ref('success');
let dismissTimer = null;

const flash = computed(() => page.props.flash);

/**
 * Controllers flash a translation key ('flash.player_created'), optionally
 * as { key, params } when the message interpolates data.
 *
 * t() returns the translation when the key exists and returns the input
 * unchanged when it does not, so a controller still flashing a literal
 * English sentence keeps working. te() is deliberately NOT used as a guard:
 * it wrongly reports flat dotted keys (our whole flash.* namespace) as
 * missing, which is what rendered raw "flash.player_updated" on screen.
 */
function translate(payload) {
    if (payload && typeof payload === 'object' && payload.key) {
        return t(payload.key, payload.params ?? {});
    }

    return typeof payload === 'string' ? t(payload) : payload;
}

// This component mounts once in the layout, so checking on mount alone meant
// every flash after the first Inertia visit was silently dropped.
watch(flash, checkFlash, { deep: true });
onMounted(checkFlash);

function checkFlash() {
    // 'status' is what Breeze's auth flows flash (password reset, verification).
    const success = flash.value?.success ?? flash.value?.status;

    if (success) {
        message.value = translate(success);
        type.value = 'success';
        show.value = true;
        autoDismiss();
    } else if (flash.value?.error) {
        message.value = translate(flash.value.error);
        type.value = 'error';
        show.value = true;
        autoDismiss();
    }
}

function autoDismiss() {
    clearTimeout(dismissTimer);
    dismissTimer = setTimeout(() => { show.value = false; }, 2000);
}
</script>

<template>
    <Transition
        enter-active-class="transition ease-out duration-300"
        enter-from-class="opacity-0 translate-y-[-1rem]"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition ease-in duration-200"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 translate-y-[-1rem]"
    >
        <div v-if="show" class="pointer-events-none fixed top-4 inset-x-0 z-50 flex justify-center px-4">
            <div
                class="pointer-events-auto flex items-center gap-3 rounded-lg px-4 py-3 shadow-lg"
                :class="type === 'success'
                    ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-200 dark:ring-emerald-500/30'
                    : 'bg-rose-50 text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/15 dark:text-rose-200 dark:ring-rose-500/30'"
            >
                <svg v-if="type === 'success'" class="h-5 w-5 text-emerald-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <svg v-else class="h-5 w-5 text-rose-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
                <p class="max-w-md whitespace-pre-line text-sm font-medium">{{ message }}</p>
                <button @click="show = false" class="ms-4 text-current opacity-50 hover:opacity-100">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
        </div>
    </Transition>
</template>
