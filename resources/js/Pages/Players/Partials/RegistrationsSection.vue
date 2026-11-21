<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import StatusChangeModal from '@/Pages/Players/Partials/StatusChangeModal.vue';
import { useCan } from '@/Composables/useCan';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    registrations: { type: Array, default: () => [] },
    /** The training group each registration is in today: branch id => { id, name }. */
    groups: { type: [Object, Array], default: () => ({}) },
    /** The player's other positions: each belongs under its branch's entry, and those with no branch under the first. */
    otherPositions: { type: Array, default: () => [] },
    /** The page's own date formatter, so dates read the same everywhere. */
    formatDate: { type: Function, required: true },
    /** For the status history: the player and the statuses a hand-typed line may use. */
    playerId: { type: Number, default: null },
    playerStatuses: { type: Array, default: () => [] },
});

const named = (item) => item?.localized_name || item?.name || '—';

const othersFor = (registration, index) => props.otherPositions.filter((position) => (
    position.branch_id === null || position.branch_id === undefined
        ? index === 0
        : Number(position.branch_id) === Number(registration.branch_id)
));

// The history: only the edit right may change it, and only the past.
const canEditHistory = () => props.playerId !== null && can('players', 'edit');
const modal = ref({ show: false, registration: null, line: null });
const openAdd = (registration) => { modal.value = { show: true, registration, line: null }; };
const openEdit = (line) => { modal.value = { show: true, registration: null, line }; };
const deleting = ref(null);

function confirmDelete() {
    router.delete(route('players.status-changes.destroy', [props.playerId, deleting.value.id]), {
        preserveScroll: true,
        onFinish: () => { deleting.value = null; },
    });
}

const recordedOn = (line) => (line.recorded_at ? props.formatDate(line.recorded_at) : '');

// "by {name}" split around the name, so the name sits in its own <bdi>: an Arabic name in the
// French or English sentence would otherwise reorder the date and brackets around it.
const byParts = () => t('status_history.by', { name: '\u0000' }).split('\u0000');
</script>

<template>
    <!-- One root, so the page's data-help lands on it. -->
    <div>
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
                <div><dt class="inline text-slate-500 dark:text-slate-400">{{ t('category') }}:</dt> <dd class="ms-1 inline">{{ named(registration.category) }}<template v-if="groups[registration.branch_id]"> · {{ groups[registration.branch_id].name }}</template></dd></div>
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

            <section v-if="registration.status_history" class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800" :data-help="index === 0 ? 'player-status-history' : undefined">
                <div class="flex items-center justify-between gap-2">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('status_history.title') }}</h4>
                    <IconButton v-if="canEditHistory()" icon="plus" :label="t('status_history.add')" plain size="sm" :data-help="index === 0 ? 'player-status-add' : undefined" @click="openAdd(registration)" />
                </div>
                <ol class="mt-2 space-y-1.5 border-s-2 border-slate-200 ps-3 text-sm dark:border-slate-700">
                    <li class="text-slate-600 dark:text-slate-300">
                        <span class="font-medium text-slate-800 dark:text-slate-100">{{ t('status_history.joined') }}</span>
                        <span class="ms-1">
                            <template v-if="registration.join_date">{{ formatDate(registration.join_date) }}</template>
                            <template v-else-if="registration.legacy_join_year">{{ t('join_year_only', { year: registration.legacy_join_year }) }}</template>
                            <template v-else>—</template>
                        </span>
                    </li>
                    <li v-for="(line, lineIndex) in registration.status_history" :key="line.id" class="flex items-start justify-between gap-2 text-slate-600 dark:text-slate-300">
                        <div class="min-w-0">
                            <span class="font-medium text-slate-800 dark:text-slate-100">{{ line.to_status ? named(line.to_status) : t('status_history.removed_status') }}</span>
                            <span class="ms-1">{{ formatDate(line.changed_on) }}</span>
                            <span v-if="line.note" class="ms-1 text-slate-500 dark:text-slate-400">— {{ line.note }}</span>
                            <span v-if="line.user" class="ms-1 text-xs text-slate-400 dark:text-slate-500">({{ byParts()[0] }}<bdi>{{ line.user }}</bdi>{{ byParts()[1] ?? '' }}<template v-if="recordedOn(line)"> · {{ recordedOn(line) }}</template>)</span>
                        </div>
                        <div v-if="canEditHistory()" class="flex shrink-0 items-center gap-1">
                            <IconButton icon="pencil" :label="t('status_history.edit')" plain size="sm" icon-only :data-help="index === 0 && lineIndex === 0 ? 'player-status-edit' : undefined" @click="openEdit(line)" />
                            <IconButton icon="trash" :label="t('status_history.delete')" variant="danger" plain size="sm" icon-only @click="deleting = line" />
                        </div>
                    </li>
                </ol>
                <p v-if="!registration.status_history.length" class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ t('status_history.empty') }}</p>
                <p v-if="canEditHistory()" class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ t('status_history.hint') }}</p>
            </section>
        </li>
    </ul>

    <StatusChangeModal
        v-if="playerId !== null"
        :show="modal.show"
        :player-id="playerId"
        :registration="modal.registration"
        :line="modal.line"
        :player-statuses="playerStatuses"
        @close="modal.show = false"
    />
    <ConfirmModal
        :show="deleting !== null"
        :title="t('status_history.delete')"
        :message="t('status_history.delete_confirm')"
        @confirm="confirmDelete"
        @cancel="deleting = null"
    />
    </div>
</template>
