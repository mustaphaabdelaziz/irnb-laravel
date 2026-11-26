<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    a: { type: Number, required: true },
    b: { type: Number, required: true },
    keeper: { type: Object, required: true },
    duplicate: { type: Object, required: true },
    fields: { type: Array, required: true },
    counts: { type: Object, default: () => ({}) },
    clashes: { type: Array, default: () => [] },
    pair: { type: Object, default: () => ({ score: 0, reasons: [] }) },
    viewOnly: { type: Boolean, default: false },
});

const form = useForm({
    a: props.a,
    b: props.b,
    keeper: props.keeper.id,
    choices: Object.fromEntries(props.fields.filter((f) => f.mode === 'choose').map((f) => [f.field, 'keeper'])),
});

const countRows = computed(() => Object.entries(props.counts));

function swapKeeper() {
    router.get(route('players.duplicates.merge'), { a: props.a, b: props.b, keeper: props.duplicate.id, ...(props.viewOnly ? { view: 1 } : {}) });
}

function submit() {
    if (!window.confirm(t('dup.confirm_question'))) return;
    form.post(route('players.duplicates.merge.store'), { preserveScroll: true });
}

function rowClass(field) {
    return field.mode === 'same' ? 'text-slate-400 dark:text-slate-500' : 'text-slate-800 dark:text-slate-100';
}
</script>

<template>
    <Head :title="t('dup.merge_title')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('dup.merge_title') }}</h1>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <Link :href="route('players.duplicates.index')" class="text-sm text-primary-600 dark:text-primary-300">{{ t('dup.back') }}</Link>
                <span v-if="viewOnly" class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('dup.view_only') }}</span>
                <span class="ms-auto rounded bg-rose-50 px-2 py-0.5 font-bold text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">{{ pair.score }}</span>
                <span v-for="reason in pair.reasons" :key="reason" class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ t('dup.reason.' + reason) }}</span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div v-for="(card, side) in { keeper, duplicate }" :key="side" :class="['rounded-xl border p-3', side === 'keeper'
                    ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-900/20'
                    : 'border-rose-200 bg-rose-50 dark:border-rose-800 dark:bg-rose-900/20']">
                    <div class="flex items-center gap-3">
                        <img v-if="card.picture_url" :src="card.picture_url" alt="" class="h-12 w-12 rounded-full object-cover" />
                        <div class="min-w-0">
                            <Link :href="route('players.show', card.id)" class="block break-words font-semibold text-slate-900 hover:text-primary-600 dark:text-slate-100 dark:hover:text-primary-300">{{ card.name }}</Link>
                            <span class="block text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ card.membership_ids.join(' · ') }}</span>
                            <span :class="['mt-1 inline-block rounded px-2 py-0.5 text-xs font-medium', side === 'keeper'
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'
                                : 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300']">{{ side === 'keeper' ? t('dup.kept_badge') : t('dup.removed_badge') }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800" @click="swapKeeper">{{ t('dup.swap_keeper') }}</button>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <tr>
                            <th class="px-3 py-2 text-start">{{ t('dup.field_col') }}</th>
                            <th class="px-3 py-2 text-start">{{ keeper.name }}</th>
                            <th class="px-3 py-2 text-start">{{ duplicate.name }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="field in fields" :key="field.field" :class="rowClass(field)">
                            <td class="px-3 py-2 font-medium">{{ t('dup.field.' + field.field) }}</td>
                            <td v-for="side in ['keeper', 'duplicate']" :key="side" class="px-3 py-2">
                                <label class="flex items-center gap-2">
                                    <input
                                        v-if="field.mode === 'choose' && !viewOnly"
                                        v-model="form.choices[field.field]"
                                        type="radio"
                                        :name="'choice-' + field.field"
                                        :value="side"
                                        class="text-primary-600 focus:ring-primary-500"
                                    />
                                    <img v-if="field.field === 'picture' && field[side]" :src="field[side]" alt="" class="h-10 w-10 rounded object-cover" />
                                    <span v-else class="break-words">{{ field[side] ?? '—' }}</span>
                                    <span v-if="side === 'duplicate' && field.mode === 'auto'" class="rounded bg-amber-50 px-1.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ t('dup.auto_filled') }}</span>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="border-t border-slate-200 p-3 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">{{ t('dup.phones_joined') }}</p>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                    <h2 class="mb-2 font-semibold text-slate-900 dark:text-slate-100">{{ t('dup.moves_title') }}</h2>
                    <ul v-if="countRows.length" class="space-y-1 text-sm text-slate-700 dark:text-slate-200">
                        <li v-for="[table, count] in countRows" :key="table" class="flex justify-between gap-2">
                            <span>{{ t('dup.count.' + table) }}</span><span class="font-semibold">{{ count }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-500 dark:text-slate-400">{{ t('dup.nothing_moves') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                    <h2 class="mb-2 font-semibold text-slate-900 dark:text-slate-100">{{ t('dup.clashes_title') }}</h2>
                    <ul v-if="clashes.length" class="space-y-1 text-sm text-slate-700 dark:text-slate-200">
                        <li v-for="clash in clashes" :key="clash.kind + clash.keeper_row">
                            {{ t('dup.clash.' + clash.kind, { name: clash.label }) }} —
                            <span class="font-medium">{{ t('dup.kept_from', { name: clash.winner === 'keeper' ? keeper.name : duplicate.name }) }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-500 dark:text-slate-400">{{ t('dup.no_clashes') }}</p>
                </div>
            </div>

            <div v-if="!viewOnly" class="flex justify-end">
                <button type="button" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700 disabled:opacity-50" :disabled="form.processing" @click="submit">{{ t('dup.confirm') }}</button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
