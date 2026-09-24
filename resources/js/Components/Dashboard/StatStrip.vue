<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import StatTile from '@/Components/Dashboard/StatTile.vue';

/**
 * A module's four headline figures, above its list.
 *
 * Built from the dashboard's own StatTile so the same number wears the same
 * clothes wherever it appears. Icons and tones are declared here rather than
 * sent from the server: they are presentation, and the server should not be
 * choosing them.
 */
const props = defineProps({
    tiles: { type: Array, default: () => [] },
});

const { t } = useI18n();

const STYLE = {
    players_active: { icon: 'players', tone: 'primary' },
    players_with_debt: { icon: 'alert', tone: 'warning' },
    players_debt_total: { icon: 'money', tone: 'negative' },
    players_new: { icon: 'plus', tone: 'positive' },
    players_left: { icon: 'logout', tone: 'negative' },

    subs_enrolled: { icon: 'subscriptions', tone: 'primary' },
    subs_collected: { icon: 'check', tone: 'positive' },
    subs_owed: { icon: 'alert', tone: 'warning' },
    subs_rate: { icon: 'money', tone: 'primary' },

    equip_catalogs: { icon: 'box', tone: 'primary' },
    equip_units: { icon: 'archive', tone: 'neutral' },
    equip_value: { icon: 'money', tone: 'primary' },
    equip_on_loan: { icon: 'external', tone: 'warning' },

    tx_income: { icon: 'money', tone: 'positive' },
    tx_expense: { icon: 'money', tone: 'negative' },
    tx_net: { icon: 'transactions', tone: 'primary' },
    tx_debts: { icon: 'alert', tone: 'warning' },
};

const decorated = computed(() => props.tiles.map((tile) => ({
    ...tile,
    label: t(`strip.${tile.key}`),
    icon: STYLE[tile.key]?.icon ?? 'dot',
    tone: STYLE[tile.key]?.tone ?? 'primary',
})));
</script>

<template>
    <section v-if="decorated.length" class="grid gap-3 sm:grid-cols-2" :class="decorated.length > 4 ? 'lg:grid-cols-3 xl:grid-cols-5' : 'xl:grid-cols-4'">
        <StatTile
            v-for="tile in decorated"
            :key="tile.key"
            :label="tile.label"
            :value="tile.value"
            :format="tile.format"
            :icon="tile.icon"
            :tone="tile.tone"
        />
    </section>
</template>
