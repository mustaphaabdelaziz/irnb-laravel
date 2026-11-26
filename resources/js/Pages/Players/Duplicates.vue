<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Icon from '@/Components/Icon.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    filters: { type: Object, required: true },
    total: { type: Number, default: 0 },
    pairs: { type: Object, required: true },
    criteriaOptions: { type: Array, default: () => [] },
    minScores: { type: Array, default: () => [] },
});

const open = ref(true);
const status = ref(props.filters.status);
const minScore = ref(props.filters.min_score);
const criteria = ref([...props.filters.criteria]);
const hasMergeRoute = route().has('players.duplicates.merge');
const selectClass = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';

const statusClass = {
    pending: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    dismissed: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    merged: 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300',
};

function apply() {
    router.get(
        route('players.duplicates.index'),
        { status: status.value, min_score: minScore.value, criteria: criteria.value },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function toggleCriterion(criterion) {
    criteria.value = criteria.value.includes(criterion)
        ? criteria.value.filter((c) => c !== criterion)
        : [...criteria.value, criterion];
    apply();
}

function refresh() {
    router.reload({ preserveScroll: true });
}

function dismiss(pair) {
    router.post(route('players.duplicates.dismiss'), { a: pair.a.id, b: pair.b.id }, { preserveScroll: true });
}

function restore(pair) {
    router.delete(route('players.duplicates.restore', pair.decision_id), { preserveScroll: true });
}

function mergeHref(pair, view = false) {
    return route('players.duplicates.merge', { a: pair.a.id, b: pair.b.id, ...(view ? { view: 1 } : {}) });
}
</script>

<template>
    <Head :title="t('dup.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('dup.title') }}</h1>
        </template>

        <div class="mx-auto max-w-7xl space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3 dark:border-slate-700">
                    <Icon name="copy" class="h-5 w-5 text-amber-600 dark:text-amber-300" />
                    <span class="font-semibold text-slate-900 dark:text-slate-100">{{ t('dup.title') }}</span>
                    <span class="rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">{{ t('dup.pairs_count', { count: total }) }}</span>
                    <button type="button" class="rounded p-1 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" :aria-expanded="open" @click="open = !open">
                        <Icon name="chevron" :class="['h-4 w-4 transition', open ? 'rotate-180' : '']" />
                    </button>
                    <div class="ms-auto flex flex-wrap items-center gap-2">
                        <select v-model="status" :class="selectClass" :aria-label="t('dup.col_status')" @change="apply">
                            <option value="pending">{{ t('dup.status.pending') }}</option>
                            <option value="dismissed">{{ t('dup.status.dismissed') }}</option>
                            <option value="merged">{{ t('dup.status.merged') }}</option>
                        </select>
                        <select v-model.number="minScore" :class="selectClass" :aria-label="t('dup.score')" @change="apply">
                            <option v-for="score in minScores" :key="score" :value="score">{{ score ? t('dup.min_score', { score }) : t('dup.min_score_all') }}</option>
                        </select>
                        <IconButton icon="refresh" :label="t('dup.refresh')" variant="success" plain @click="refresh" />
                    </div>
                </div>

                <div v-show="open">
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3 text-sm dark:border-slate-700">
                        <span class="text-slate-600 dark:text-slate-300">{{ t('dup.must_match') }}</span>
                        <button
                            v-for="criterion in criteriaOptions"
                            :key="criterion"
                            type="button"
                            :aria-pressed="criteria.includes(criterion)"
                            :class="['rounded-full border px-3 py-1', criteria.includes(criterion)
                                ? 'border-primary-600 bg-primary-600 text-white dark:border-primary-300 dark:bg-primary-300 dark:text-slate-900'
                                : 'border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800']"
                            @click="toggleCriterion(criterion)"
                        >{{ t('dup.criterion.' + criterion) }}</button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th class="px-3 py-2 text-start">{{ t('dup.player_a') }}</th>
                                    <th class="px-3 py-2 text-start">{{ t('dup.player_b') }}</th>
                                    <th class="px-3 py-2 text-start">{{ t('dup.score') }}</th>
                                    <th class="px-3 py-2 text-start">{{ t('dup.reasons') }}</th>
                                    <th class="px-3 py-2 text-start">{{ t('dup.col_status') }}</th>
                                    <th class="px-3 py-2 text-start">{{ t('dup.col_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="pair in pairs.data" :key="pair.key" class="hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                    <td v-for="side in ['a', 'b']" :key="side" class="px-3 py-2 align-top">
                                        <div class="flex items-center gap-2">
                                            <img v-if="pair[side]?.picture_url" :src="pair[side].picture_url" alt="" class="h-8 w-8 rounded-full object-cover" />
                                            <div class="min-w-0">
                                                <Link
                                                    v-if="pair.status !== 'merged' || pair[side]?.id === pair.kept_player_id"
                                                    :href="route('players.show', pair[side].id)"
                                                    class="block break-words font-semibold text-slate-900 hover:text-primary-600 dark:text-slate-100 dark:hover:text-primary-300"
                                                >{{ pair[side]?.name }}</Link>
                                                <span v-else class="block break-words font-semibold text-slate-500 line-through dark:text-slate-400">{{ pair[side]?.name }}</span>
                                                <span class="block text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ (pair[side]?.membership_ids || []).join(' · ') }}<template v-if="pair[side]?.birthdate"> · {{ pair[side].birthdate }}</template></span>
                                                <span v-if="pair[side]?.archived" class="mt-0.5 inline-block rounded bg-slate-100 px-1.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('dup.archived') }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <span class="inline-block rounded bg-rose-50 px-2 py-0.5 font-bold text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">{{ pair.score }}</span>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <div class="flex flex-wrap gap-1">
                                            <span
                                                v-for="reason in pair.reasons"
                                                :key="reason"
                                                :class="['rounded px-2 py-0.5 text-xs', reason === 'different_gender'
                                                    ? 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300'
                                                    : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200']"
                                            >{{ t('dup.reason.' + reason) }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <span :class="['rounded px-2 py-0.5 text-xs font-medium', statusClass[pair.status]]">{{ t('dup.status.' + pair.status) }}</span>
                                        <span v-if="pair.decided_at" class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ pair.decided_at }}</span>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <div class="flex gap-1">
                                            <template v-if="pair.status === 'pending'">
                                                <IconButton v-if="hasMergeRoute" icon="eye" :label="t('dup.compare')" :href="mergeHref(pair, true)" icon-only />
                                                <IconButton icon="close" :label="t('dup.not_duplicate')" variant="danger" icon-only @click="dismiss(pair)" />
                                                <IconButton v-if="hasMergeRoute" icon="merge" :label="t('dup.merge_action')" variant="primary" :href="mergeHref(pair)" icon-only />
                                            </template>
                                            <IconButton v-else-if="pair.status === 'dismissed'" icon="restore" :label="t('dup.undo')" icon-only @click="restore(pair)" />
                                            <IconButton v-else-if="pair.kept_player_id" icon="eye" :label="t('dup.open_kept')" :href="route('players.show', pair.kept_player_id)" icon-only />
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!pairs.data.length">
                                    <td colspan="6" class="px-3 py-8 text-center text-slate-500 dark:text-slate-400">{{ t('dup.empty') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="pairs.prev_page_url || pairs.next_page_url" class="flex justify-between p-3">
                        <Link v-if="pairs.prev_page_url" :href="pairs.prev_page_url" class="text-sm text-primary-600 dark:text-primary-300">{{ t('previous') }}</Link>
                        <span v-else />
                        <Link v-if="pairs.next_page_url" :href="pairs.next_page_url" class="text-sm text-primary-600 dark:text-primary-300">{{ t('next') }}</Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
