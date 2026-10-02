<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    kind: { type: String, default: 'extra' }, // extra | preseason
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null }, // pre-selected primary category
    date: { type: String, required: true }, // 'Y-m-d'
    playerStatuses: { type: Array, default: () => [] }, // [{ id, name }]
    rosterStatusIds: { type: Array, default: () => [] }, // the settings' default roster set
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const form = useForm({ category_id: null, category_ids: [], kind: 'extra', date: '', start_time: '18:00', end_time: '19:30', title: '', roster_status_ids: [] });
watch(() => props.show, (open) => {
    if (!open) return;
    form.reset();
    form.clearErrors();
    Object.assign(form, {
        category_id: props.categoryId ?? props.categories[0]?.id ?? null,
        category_ids: [],
        kind: props.kind,
        date: props.date,
        roster_status_ids: [...props.rosterStatusIds],
    });
});

// Only a pre-season session can be shared; the primary category is always part of it.
const others = computed(() => props.categories.filter((c) => c.id !== form.category_id));
function submit() {
    form
        .transform((d) => ({
            ...d,
            category_ids: d.kind === 'preseason' ? [d.category_id, ...d.category_ids.filter((id) => id !== d.category_id)] : [],
            title: d.title || null,
            // No player status defined at all: left out, the roster takes the default.
            roster_status_ids: props.playerStatuses.length ? d.roster_status_ids : undefined,
        }))
        .post(route('attendance.sessions.store'), { onSuccess: () => emit('close') });
}

const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form class="space-y-3 p-5" @submit.prevent="submit">
            <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ form.kind === 'preseason' ? t('att.add_preseason') : t('att.add_extra') }}</h2>
            <label class="block text-sm">{{ t('att.category') }}
                <select v-model="form.category_id" :class="[input, 'mt-1 block w-full']">
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </label>
            <fieldset v-if="form.kind === 'preseason' && others.length" class="text-sm">
                <legend class="mb-1">{{ t('att.other_categories') }}</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <label v-for="c in others" :key="c.id" class="inline-flex items-center gap-1.5">
                        <input v-model="form.category_ids" type="checkbox" :value="c.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                        {{ c.name }}
                    </label>
                </div>
            </fieldset>
            <label class="block text-sm">{{ t('att.date') }}<input v-model="form.date" type="date" :class="[input, 'mt-1 block w-full']" /></label>
            <div class="flex gap-2">
                <label class="flex-1 text-sm">{{ t('att.start') }}<input v-model="form.start_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="flex-1 text-sm">{{ t('att.end') }}<input v-model="form.end_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
            </div>
            <fieldset v-if="playerStatuses.length" class="text-sm">
                <legend class="mb-1">{{ t('att.roster_statuses') }}</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <label v-for="s in playerStatuses" :key="s.id" class="inline-flex items-center gap-1.5">
                        <input v-model="form.roster_status_ids" type="checkbox" :value="s.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                        {{ s.name }}
                    </label>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ t('att.roster_statuses_help') }}</p>
            </fieldset>
            <label class="block text-sm">{{ t('att.title_goal') }}<input v-model="form.title" type="text" maxlength="150" :placeholder="t('att.title_placeholder')" :class="[input, 'mt-1 block w-full']" /></label>
            <InputError v-for="(e, k) in form.errors" :key="k" :message="tr(e)" />
            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="emit('close')">{{ t('att.close') }}</button>
                <button type="submit" :disabled="form.processing || (playerStatuses.length > 0 && !form.roster_status_ids.length)" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('att.save') }}</button>
            </div>
        </form>
    </Modal>
</template>
