<script setup>
import HelpCallout from '@/Components/Help/HelpCallout.vue';
import HelpFigure from '@/Components/Help/HelpFigure.vue';
import HelpStep from '@/Components/Help/HelpStep.vue';

// Renders the parser's typed blocks in order. Text/callout HTML is
// server-rendered Markdown with raw HTML stripped.
defineProps({ blocks: { type: Array, required: true } });
</script>

<template>
    <div class="help-article">
        <template v-for="(block, i) in blocks" :key="i">
            <HelpStep v-if="block.type === 'step'" :step="block" />
            <HelpCallout v-else-if="['tip', 'warning', 'example'].includes(block.type)" :type="block.type" :html="block.html" />
            <HelpFigure v-else-if="block.type === 'figure'" :figure="block" />
            <div v-else class="help-prose my-4 text-base leading-relaxed text-slate-700 dark:text-slate-300" v-html="block.html" />
        </template>
    </div>
</template>

<style>
.help-prose p + p { margin-top: 0.5rem; }
.help-prose ul { list-style: disc; padding-inline-start: 1.25rem; }
.help-prose ol { list-style: decimal; padding-inline-start: 1.25rem; }
.help-prose li + li { margin-top: 0.25rem; }
.help-prose strong { font-weight: 700; }
.help-prose kbd, .help-prose code { border-radius: 0.375rem; border: 1px solid rgb(203 213 225); padding: 0 0.35rem; font-family: inherit; font-size: 0.9em; }
.dark .help-prose kbd, .dark .help-prose code { border-color: rgb(71 85 105); }
@media print {
    .help-step { break-inside: avoid; box-shadow: none; }
}
</style>
