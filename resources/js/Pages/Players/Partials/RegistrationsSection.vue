<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    registrations: { type: Array, default: () => [] },
    /** The player's other positions: each belongs under its branch's entry, and those with no branch under the first. */
    otherPositions: { type: Array, default: () => [] },
    /** The page's own date formatter, so dates read the same everywhere. */
    formatDate: { type: Function, required: true },
});

const named = (item) => item?.localized_name || item?.name || '—';

const othersFor = (registration, index) => props.otherPositions.filter((position) => (
    position.branch_id === null || position.branch_id === undefined
        ? index === 0
        : Number(position.branch_id) === Number(registration.branch_id)
));
</script>

<template>
    <ul v-if="registrations.length" class="mt-5 space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800">
        <li v-for="(registration, index) in registrations" :key="registration.id" class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
                    <img v-if="registration.branch?.logo_url" :src="registration.branch.logo_url" alt="" class="h-5 w-5 rounded-sm object-contain" />
                    {{ named(registration.branch) }}
                </p>
                <span class="font-mono text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ registration.membership_id || '—' }}</span>
            </div>
            <dl class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 text-sm text-slate-600 sm:grid-cols-2 dark:text-slate-300">
                <div>
                    <dt class="inline text-slate-500 dark:text-slate-400">{{ t('join_date') }}:</dt>
                    <dd class="ms-1 inline">
                        <template v-if="registration.join_date">{{ formatDate(registration.join_date) }}</template>
                        <span v-else-if="registration.legacy_join_year" class="text-amber-600 dark:text-amber-400">{{ t('join_year_only', { year: registration.legacy_join_year }) }}</span>
                        <template v-else>—</template>
                    </dd>
                </div>
                <div><dt class="inline text-slate-500 dark:text-slate-400">{{ t('category') }}:</dt> <dd class="ms-1 inline">{{ named(registration.category) }}</dd></div>
                <div v-if="registration.position"><dt class="inline text-slate-500 dark:text-slate-400">{{ t('main_position') }}:</dt> <dd class="ms-1 inline">{{ registration.position.name }}</dd></div>
                <div v-if="othersFor(registration, index).length">
                    <dt class="inline text-slate-500 dark:text-slate-400">{{ t('other_positions') }}:</dt>
                    <dd class="ms-1 inline">
                        <template v-for="(position, i) in othersFor(registration, index)" :key="position.id"><span :title="position.name">{{ position.abbreviation }}</span>{{ i < othersFor(registration, index).length - 1 ? ', ' : '' }}</template>
                    </dd>
                </div>
                <div v-if="registration.licence_number"><dt class="inline text-slate-500 dark:text-slate-400">{{ t('licence_number') }}:</dt> <dd class="ms-1 inline" dir="ltr">{{ registration.licence_number }}</dd></div>
                <div><dt class="inline text-slate-500 dark:text-slate-400">{{ t('membership_status') }}:</dt> <dd class="ms-1 inline">{{ named(registration.status) }}</dd></div>
                <div v-if="registration.left_at"><dt class="inline text-slate-500 dark:text-slate-400">{{ t('left_at') }}:</dt> <dd class="ms-1 inline">{{ formatDate(registration.left_at) }}</dd></div>
                <div v-if="registration.sporting_status"><dt class="inline text-slate-500 dark:text-slate-400">{{ t('sporting_status') }}:</dt> <dd class="ms-1 inline">{{ named(registration.sporting_status) }}</dd></div>
            </dl>
        </li>
    </ul>
</template>
