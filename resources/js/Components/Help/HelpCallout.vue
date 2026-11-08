<script setup>
import { useI18n } from 'vue-i18n';

// Tip / warning / example boxes inside an article. `html` is server-rendered
// Markdown with raw HTML stripped (HelpArticleParser), so v-html is safe.
defineProps({
    type: { type: String, required: true }, // tip | warning | example
    html: { type: String, required: true },
});

const { t } = useI18n();

const styles = {
    tip: { icon: '💡', box: 'border-primary-500 bg-primary-50 text-primary-900 dark:border-primary-400 dark:bg-primary-500/10 dark:text-primary-100', label: 'help.tip' },
    warning: { icon: '⚠️', box: 'border-amber-500 bg-amber-50 text-amber-900 dark:border-amber-400 dark:bg-amber-500/10 dark:text-amber-100', label: 'help.warning' },
    example: { icon: '📋', box: 'border-sky-500 bg-sky-50 text-sky-900 dark:border-sky-400 dark:bg-sky-500/10 dark:text-sky-100', label: 'help.example' },
};
</script>

<template>
    <aside class="my-4 rounded-xl border-s-4 px-4 py-3 text-base leading-relaxed" :class="styles[type].box">
        <p class="mb-1 font-bold"><span aria-hidden="true">{{ styles[type].icon }}</span> {{ t(styles[type].label) }}</p>
        <div class="help-prose" v-html="html" />
    </aside>
</template>
