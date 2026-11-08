<script setup>
import HelpFigure from '@/Components/Help/HelpFigure.vue';

// One numbered step card: big number, one short sentence, its screenshot(s).
defineProps({ step: { type: Object, required: true } });
</script>

<template>
    <section class="help-step mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
        <h2 class="flex items-start gap-3 text-lg font-bold text-slate-900 dark:text-slate-100 sm:text-xl">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-600 text-base font-bold text-white shadow-sm">{{ step.number }}</span>
            <span class="pt-1">{{ step.heading }}</span>
        </h2>
        <div v-if="step.html" class="help-prose mt-2 ps-12 text-base leading-relaxed text-slate-700 dark:text-slate-300" v-html="step.html" />
        <HelpFigure v-for="(figure, i) in step.figures" :key="i" :figure="figure" :number="step.number" :alt="step.heading" />
    </section>
</template>
