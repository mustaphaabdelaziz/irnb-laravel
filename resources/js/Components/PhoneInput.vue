<script setup>
import { formatPhone } from '@/lib/phone';

/**
 * A phone field that groups the number as it is typed (0559453948 →
 * 05 59 45 39 48). Always left-to-right: in an RTL page a spaced number
 * would otherwise be laid out with its groups reversed.
 *
 * v-model holds the grouped text; strip it with phoneDigits() before saving.
 */
const model = defineModel({ type: String, default: '' });

function onInput(event) {
    const formatted = formatPhone(event.target.value);
    // Write back even when the model is unchanged (a typed letter), so the
    // field never shows anything but the grouped number.
    event.target.value = formatted;
    model.value = formatted;
}
</script>

<template>
    <input
        :value="formatPhone(model)"
        @input="onInput"
        type="tel"
        inputmode="tel"
        dir="ltr"
        autocomplete="tel"
        maxlength="20"
        class="rounded-lg border-slate-300 font-mono text-slate-900 shadow-sm transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/40 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500 rtl:text-right dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500 dark:disabled:bg-slate-900 dark:disabled:text-slate-500"
    />
</template>
