<script setup>
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';

// Eligibility warnings next to a player's name — information only, never a block.
const { t } = useI18n();
const props = defineProps({ flags: { type: Object, default: null } });

const suspensionTitle = computed(() => {
    const s = props.flags?.suspension;
    if (!s) return '';
    const what = s.until ? t('comp.flag.suspended_until', { date: s.until }) : t('comp.flag.suspended_left', { n: s.remaining });
    const why = s.reason || (s.cause ? t(`comp.flag.cause_${s.cause}`) : '');
    return [what, why, s.opponent].filter(Boolean).join(' — ');
});
const debtTitle = computed(() => (props.flags?.debt != null ? t('comp.flag.debt', { amount: Number(props.flags.debt).toLocaleString() }) : ''));
const chip = 'rounded px-1.5 py-0.5 text-xs';
</script>

<template>
    <span v-if="flags" class="inline-flex flex-wrap items-center gap-1 align-middle">
        <span v-if="flags.injured" :class="chip" class="bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ t('comp.badge_injured') }}</span>
        <span v-if="flags.suspended" :class="chip" :title="suspensionTitle" class="bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">⛔ {{ t('comp.flag.suspended') }}</span>
        <span v-if="flags.no_licence" :class="chip" class="bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200">🪪 {{ t('comp.flag.no_licence') }}</span>
        <span v-if="flags.documents_missing" :class="chip" :title="flags.documents.join(', ')" class="bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200">📄 {{ t('comp.flag.documents') }}</span>
        <span v-if="flags.unpaid" :class="chip" :title="debtTitle" class="bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200">💰 {{ t('comp.flag.unpaid') }}</span>
    </span>
</template>
