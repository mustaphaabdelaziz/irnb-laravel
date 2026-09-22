<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';

/**
 * Tells work equipment apart from a loan at a glance, everywhere a rental is
 * listed. An assignment is kit given to a player to work with (open-ended);
 * a rental is a temporary loan that comes back by a date.
 */
const props = defineProps({ type: { type: String, default: 'rental' } });
const { t } = useI18n();
const isAssignment = computed(() => props.type === 'assignment');
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
        :class="isAssignment
            ? 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/25'
            : 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/25'">
        <Icon :name="isAssignment ? 'wrench' : 'refresh'" class="text-sm" />
        {{ isAssignment ? t('equipment.assigned_badge') : t('rented') }}
    </span>
</template>
