<script setup>
import TextInput from '@/Components/TextInput.vue';
import { computed, ref, useAttrs } from 'vue';
import { useI18n } from 'vue-i18n';

defineOptions({ inheritAttrs: false });

const model = defineModel({
    type: String,
    required: true,
});

const { t } = useI18n();
const attrs = useAttrs();
const visible = ref(false);
const input = ref(null);

// Layout classes (margins, width) go on the wrapper so the eye button lines up
// with the field; every other attribute (id, autocomplete, required…) on the input.
const inputAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;
    return rest;
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <div class="relative" :class="attrs.class">
        <TextInput
            ref="input"
            v-bind="inputAttrs"
            v-model="model"
            :type="visible ? 'text' : 'password'"
            class="block w-full pe-10"
        />
        <button
            type="button"
            @click="visible = !visible"
            :aria-label="visible ? t('hide_password') : t('show_password')"
            :aria-pressed="visible"
            tabindex="-1"
            class="absolute inset-y-0 end-0 flex items-center px-3 text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:text-slate-600 dark:hover:text-slate-300 dark:focus-visible:text-slate-300"
        >
            <!-- eye -->
            <svg v-if="!visible" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
            <!-- eye-slash -->
            <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
            </svg>
        </button>
    </div>
</template>
