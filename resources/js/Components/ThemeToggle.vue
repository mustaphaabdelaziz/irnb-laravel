<script setup>
import { useTheme } from '@/Composables/useTheme';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';

const { isDark, toggle } = useTheme();
const { t } = useI18n();
</script>

<template>
    <button
        type="button"
        @click="toggle"
        :aria-label="isDark ? t('light_mode') : t('dark_mode')"
        :title="isDark ? t('light_mode') : t('dark_mode')"
        class="relative flex h-9 w-9 items-center justify-center rounded-lg text-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200"
    >
        <!-- Cross-fade sun/moon -->
        <Icon name="sun" class="absolute transition-all duration-300" :class="isDark ? 'rotate-0 scale-100 opacity-100' : '-rotate-90 scale-0 opacity-0'" />
        <Icon name="moon" class="absolute transition-all duration-300" :class="isDark ? 'rotate-90 scale-0 opacity-0' : 'rotate-0 scale-100 opacity-100'" />
    </button>
</template>
