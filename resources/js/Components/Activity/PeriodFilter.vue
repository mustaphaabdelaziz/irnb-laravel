<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';

/**
 * A period control (month / season / custom range). With `href` it writes
 * the period to the URL (`period`, `from`, `to`), so a view survives a
 * refresh and can be shared; `keep` carries the other query params the page
 * wants kept (the page number is always dropped). Without `href` it only
 * emits `change` with that query, for a card that fetches its own data.
 */
const props = defineProps({
    period: { type: Object, required: true },
    href: { type: String, default: null },
    keep: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['change']);

const { t } = useI18n();

const PERIODS = ['month', 'season', 'custom'];

const selected = ref(props.period.period);
const from = ref(props.period.from);
const to = ref(props.period.to);

watch(() => props.period, (p) => {
    selected.value = p.period;
    from.value = p.from;
    to.value = p.to;
});

function visit(query) {
    if (!props.href) {
        emit('change', query);
        return;
    }
    router.get(props.href, { ...props.keep, ...query }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function choose(value) {
    selected.value = value;
    // A custom range waits for its dates; the presets apply at once.
    if (value !== 'custom') visit({ period: value });
}

function applyCustom() {
    if (!from.value || !to.value) return;
    visit({ period: 'custom', from: from.value, to: to.value });
}

const inputClass = 'h-9 rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex items-center gap-1.5">
            <Icon name="calendar" class="size-4 text-slate-400" />
            <select
                :class="inputClass"
                :value="selected"
                :aria-label="t('activity.period_label')"
                @change="choose($event.target.value)"
            >
                <option v-for="p in PERIODS" :key="p" :value="p">{{ t(`activity.period.${p}`) }}</option>
            </select>
        </div>

        <form v-if="selected === 'custom'" class="flex flex-wrap items-center gap-2" @submit.prevent="applyCustom">
            <label class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-300">
                {{ t('activity.from') }}
                <input v-model="from" type="date" :class="inputClass" required />
            </label>
            <label class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-300">
                {{ t('activity.to') }}
                <input v-model="to" type="date" :min="from" :class="inputClass" required />
            </label>
            <button
                type="submit"
                class="h-9 rounded-lg bg-primary-600 px-3 text-sm font-medium text-white shadow-sm hover:bg-primary-700 disabled:opacity-50"
                :disabled="!from || !to"
            >{{ t('activity.apply') }}</button>
        </form>

        <span class="text-sm text-slate-500 dark:text-slate-400" dir="ltr">{{ period.label }}</span>
    </div>
</template>
