<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    schedules: { type: Array, default: () => [] },
    closures: { type: Array, default: () => [] },
    targets: { type: Array, default: () => [] },
    seasons: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    customStatuses: { type: Array, default: () => [] },
    behaviours: { type: Array, default: () => [] },
    playerStatuses: { type: Array, default: () => [] }, // [{ id, name }]
});
const { t, locale } = useI18n();
const lang = computed(() => (locale.value === 'ar' ? 'ar' : locale.value));
// Local 'Y-m-d', not toISOString()'s UTC date (see Index.vue's key()).
const key = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const today = key(new Date());

// ISO weekday 1..7 -> localized name (2024-01-01 is a Monday).
const weekdayName = (n) => new Date(Date.UTC(2024, 0, n)).toLocaleDateString(lang.value, { weekday: 'long', timeZone: 'UTC' });
const categoryName = (id) => props.categories.find((c) => c.id === id)?.name ?? '';

const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
// Only att.* values are translation keys; Laravel's own messages show as they are.
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);

// ---- Weekly schedule ----
const editingId = ref(null);
const scheduleForm = useForm({ category_id: props.categories[0]?.id ?? null, weekday: 1, start_time: '18:00', end_time: '19:30', valid_from: today, valid_to: '' });
function editSchedule(s) {
    editingId.value = s.id;
    Object.assign(scheduleForm, { category_id: s.category_id, weekday: s.weekday, start_time: s.start_time, end_time: s.end_time, valid_from: s.valid_from, valid_to: s.valid_to ?? '' });
}
function resetSchedule() {
    editingId.value = null;
    scheduleForm.reset();
    scheduleForm.clearErrors();
}
function submitSchedule() {
    const opts = { preserveScroll: true, onSuccess: resetSchedule };
    scheduleForm.transform((d) => ({ ...d, valid_to: d.valid_to || null }));
    editingId.value ? scheduleForm.put(route('attendance.schedules.update', editingId.value), opts) : scheduleForm.post(route('attendance.schedules.store'), opts);
}
function destroy(name, id) {
    if (window.confirm(t('att.confirm_delete'))) router.delete(route(name, id), { preserveScroll: true });
}

// ---- Closures ----
const closureForm = useForm({ start_date: today, end_date: today, reason: '' });
const submitClosure = () => closureForm.post(route('attendance.closures.store'), { preserveScroll: true, onSuccess: () => closureForm.reset() });

// ---- Pre-season targets ----
const targetForm = useForm({ category_id: props.categories[0]?.id ?? null, season_start_year: props.seasons[0]?.start_year, target_count: 0 });
watch(() => [targetForm.category_id, targetForm.season_start_year], ([c, y]) => {
    targetForm.target_count = props.targets.find((x) => x.category_id === c && x.season_start_year === y)?.target_count ?? 0;
}, { immediate: true });
const submitTarget = () => targetForm.post(route('attendance.preseason-targets.store'), { preserveScroll: true });

// ---- Codes, points, rules, alerts: one form, one save ----
const LOCALES = ['ar', 'fr', 'en'];
const settingsForm = useForm(JSON.parse(JSON.stringify(props.settings)));
// With no player status defined at all there is nothing to choose: the roster set is left out.
const submitSettings = () => settingsForm
    .transform((d) => ({ ...d, roster_status_ids: props.playerStatuses.length ? d.roster_status_ids : null }))
    .put(route('attendance.settings.update'), { preserveScroll: true });

// ---- Parent letter: its own form and save ----
const PLACEHOLDERS = ['player', 'category', 'period', 'absences', 'lates', 'club'];
// Built in the script: a mustache may not contain "}}".
const placeholderTokens = PLACEHOLDERS.map((p) => ({ key: p, token: '{' + p + '}' }));
// Each placeholder standing for itself, so the built-in text shows them as they are typed.
const literal = Object.fromEntries(placeholderTokens.map((p) => [p.key, p.token]));
const letterForm = useForm({ letter: JSON.parse(JSON.stringify(props.settings.letter)) });
const submitLetter = () => letterForm.put(route('attendance.settings.letter'), { preserveScroll: true });
const builtinLetter = (part, loc) => t(`att.letter.default_${part}`, literal, { locale: loc });
const letterErrors = computed(() => Object.values(letterForm.errors).map(tr));

// The built-in name in one language, shown as the placeholder of that language's field.
const builtin = (status, loc) => t(`att.status.${status}`, {}, { locale: loc });
const codeError = (status) => tr(settingsForm.errors[`codes.${status}.code`]);
const colorError = (status) => tr(settingsForm.errors[`codes.${status}.color`]);
const otherErrors = computed(() => Object.entries(settingsForm.errors)
    .filter(([k]) => !/^codes\.[a-z_]+\.(code|color)$/.test(k))
    .map(([, e]) => tr(e)));

// ---- Custom codes: added, edited, hidden or deleted one at a time ----
const blankCustom = { code: '', color: '#7c3aed', label_ar: '', label_fr: '', label_en: '', behaviour: 'not_counted', is_active: true };
const editingCustomId = ref(null);
const customForm = useForm({ ...blankCustom });
const page = usePage();
const customErrors = computed(() => [...Object.values(customForm.errors), page.props.errors?.custom_status].filter(Boolean).map(tr));
const customName = (s) => s[`label_${locale.value}`] || s.label_ar || s.label_fr || s.label_en || s.code;
function editCustom(s) {
    editingCustomId.value = s.id;
    customForm.clearErrors();
    Object.assign(customForm, { code: s.code, color: s.color, label_ar: s.label_ar ?? '', label_fr: s.label_fr ?? '', label_en: s.label_en ?? '', behaviour: s.behaviour, is_active: s.is_active });
}
function resetCustom() {
    editingCustomId.value = null;
    customForm.defaults({ ...blankCustom });
    customForm.reset();
    customForm.clearErrors();
}
function submitCustom() {
    const opts = { preserveScroll: true, onSuccess: resetCustom };
    editingCustomId.value
        ? customForm.put(route('attendance.custom-statuses.update', editingCustomId.value), opts)
        : customForm.post(route('attendance.custom-statuses.store'), opts);
}
// Hide or show keeps every other field as it is.
function toggleCustom(s) {
    router.put(route('attendance.custom-statuses.update', s.id), {
        code: s.code, color: s.color, label_ar: s.label_ar, label_fr: s.label_fr, label_en: s.label_en, behaviour: s.behaviour, is_active: !s.is_active,
    }, { preserveScroll: true });
}
function destroyCustom(s) {
    if (window.confirm(t('att.confirm_delete'))) router.delete(route('attendance.custom-statuses.destroy', s.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('att.settings')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('attendance.index')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></Link>
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('att.settings') }}</h1>
            </div>
        </template>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Weekly schedule -->
            <section :class="[card, 'lg:col-span-2']">
                <h2 class="mb-3 font-bold text-slate-900 dark:text-slate-100">{{ t('att.schedules') }}</h2>
                <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="submitSchedule">
                    <label class="text-xs text-slate-500">{{ t('att.category') }}
                        <select v-model="scheduleForm.category_id" :class="[input, 'block']"><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.weekday') }}
                        <select v-model="scheduleForm.weekday" :class="[input, 'block']"><option v-for="n in 7" :key="n" :value="n">{{ weekdayName(n) }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.start') }}<input v-model="scheduleForm.start_time" type="time" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.end') }}<input v-model="scheduleForm.end_time" type="time" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.valid_from') }}<input v-model="scheduleForm.valid_from" type="date" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.valid_to') }}<input v-model="scheduleForm.valid_to" type="date" :class="[input, 'block']" /></label>
                    <button type="submit" :disabled="scheduleForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ editingId ? t('att.save') : t('att.add') }}</button>
                    <button v-if="editingId" type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="resetSchedule">{{ t('att.close') }}</button>
                    <div class="w-full"><InputError v-for="(e, k) in scheduleForm.errors" :key="k" :message="e" /></div>
                </form>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="s in schedules" :key="s.id" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-2 font-medium">{{ categoryName(s.category_id) }}</td>
                            <td>{{ weekdayName(s.weekday) }}</td>
                            <td dir="ltr" class="text-start">{{ s.start_time }}–{{ s.end_time }}</td>
                            <td class="text-slate-500">{{ s.valid_from }} → {{ s.valid_to ?? '…' }}</td>
                            <td class="text-end">
                                <IconButton icon="pencil" :label="t('att.edit')" plain size="sm" @click="editSchedule(s)" />
                                <IconButton icon="trash" :label="t('att.delete')" variant="danger" plain size="sm" @click="destroy('attendance.schedules.destroy', s.id)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Closures -->
            <section :class="card">
                <h2 class="mb-3 font-bold text-slate-900 dark:text-slate-100">{{ t('att.closures') }}</h2>
                <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="submitClosure">
                    <label class="text-xs text-slate-500">{{ t('att.start_date') }}<input v-model="closureForm.start_date" type="date" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.end_date') }}<input v-model="closureForm.end_date" type="date" :class="[input, 'block']" /></label>
                    <label class="flex-1 text-xs text-slate-500">{{ t('att.reason') }}<input v-model="closureForm.reason" type="text" maxlength="100" :class="[input, 'block w-full']" /></label>
                    <button type="submit" :disabled="closureForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.add') }}</button>
                    <div class="w-full"><InputError v-for="(e, k) in closureForm.errors" :key="k" :message="e" /></div>
                </form>
                <ul class="space-y-1 text-sm">
                    <li v-for="c in closures" :key="c.id" class="flex items-center justify-between border-t border-slate-100 pt-1 dark:border-slate-800">
                        <span><b>{{ c.reason }}</b> · {{ c.start_date }} → {{ c.end_date }}</span>
                        <IconButton icon="trash" :label="t('att.delete')" variant="danger" plain size="sm" @click="destroy('attendance.closures.destroy', c.id)" />
                    </li>
                </ul>
            </section>

            <!-- Pre-season targets -->
            <section :class="card">
                <h2 class="mb-3 font-bold text-slate-900 dark:text-slate-100">{{ t('att.targets') }}</h2>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="submitTarget">
                    <label class="text-xs text-slate-500">{{ t('att.category') }}
                        <select v-model="targetForm.category_id" :class="[input, 'block']"><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.season') }}
                        <select v-model="targetForm.season_start_year" :class="[input, 'block']"><option v-for="s in seasons" :key="s.start_year" :value="s.start_year">{{ s.label }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.target_count') }}<input v-model.number="targetForm.target_count" type="number" min="0" max="200" :class="[input, 'block w-24']" /></label>
                    <button type="submit" :disabled="targetForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                    <div class="w-full"><InputError v-for="(e, k) in targetForm.errors" :key="k" :message="e" /></div>
                </form>
            </section>

            <!-- Parent letter -->
            <form :class="[card, 'lg:col-span-2']" @submit.prevent="submitLetter">
                <h2 class="mb-1 font-bold text-slate-900 dark:text-slate-100">{{ t('att.letter.settings_title') }}</h2>
                <p class="text-xs text-slate-500">{{ t('att.letter.settings_help') }}</p>
                <ul class="mb-3 mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                    <li v-for="ph in placeholderTokens" :key="ph.key">
                        <code dir="ltr" class="rounded bg-slate-100 px-1 font-mono text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ ph.token }}</code>
                        {{ t(`att.letter.ph.${ph.key}`) }}
                    </li>
                </ul>
                <div class="grid gap-4 lg:grid-cols-3">
                    <div v-for="l in LOCALES" :key="l" class="space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t(`att.letter.in_${l}`) }}</p>
                        <label class="block text-xs text-slate-500">{{ t('att.letter.subject') }}
                            <input v-model="letterForm.letter.subject[l]" type="text" maxlength="150" :dir="l === 'ar' ? 'rtl' : 'ltr'" :placeholder="builtinLetter('subject', l)" :class="[input, 'mt-1 block w-full']" />
                        </label>
                        <label class="block text-xs text-slate-500">{{ t('att.letter.body') }}
                            <textarea v-model="letterForm.letter.body[l]" rows="8" maxlength="3000" :dir="l === 'ar' ? 'rtl' : 'ltr'" :placeholder="builtinLetter('body', l)" :class="[input, 'mt-1 block w-full']"></textarea>
                        </label>
                    </div>
                </div>
                <InputError v-for="(e, i) in letterErrors" :key="i" :message="e" />
                <button type="submit" :disabled="letterForm.processing" class="mt-3 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
            </form>

            <!-- Codes and points, rules, alerts -->
            <form class="space-y-4 lg:col-span-2" @submit.prevent="submitSettings">
                <section :class="card">
                    <h2 class="mb-1 font-bold text-slate-900 dark:text-slate-100">{{ t('att.codes') }}</h2>
                    <p class="mb-3 text-xs text-slate-500">{{ t('att.codes_help') }}</p>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[46rem] text-sm">
                            <thead>
                                <tr class="text-xs text-slate-500">
                                    <th class="py-1 text-start font-semibold">{{ t('att.col.status') }}</th>
                                    <th class="py-1 text-start font-semibold">{{ t('att.col.code') }}</th>
                                    <th class="py-1 text-start font-semibold">{{ t('att.col.color') }}</th>
                                    <th v-for="l in LOCALES" :key="l" class="py-1 text-start font-semibold">{{ t(`att.col.label_${l}`) }}</th>
                                    <th class="py-1 text-start font-semibold">{{ t('att.col.points') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in statuses" :key="s" class="border-t border-slate-100 align-top dark:border-slate-800">
                                    <td class="py-2 pe-2">
                                        <span class="inline-flex items-center gap-2 font-medium">
                                            <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: settingsForm.codes[s].color }"></span>
                                            {{ t(`att.status.${s}`) }}
                                        </span>
                                        <InputError :message="codeError(s)" />
                                        <InputError :message="colorError(s)" />
                                    </td>
                                    <td class="py-2 pe-2">
                                        <input v-model="settingsForm.codes[s].code" type="text" maxlength="3" dir="auto" :aria-label="`${t('att.col.code')} · ${t(`att.status.${s}`)}`" :class="[input, 'w-16 text-center font-mono uppercase']" />
                                    </td>
                                    <td class="py-2 pe-2">
                                        <input v-model="settingsForm.codes[s].color" type="color" :aria-label="`${t('att.col.color')} · ${t(`att.status.${s}`)}`" class="h-9 w-12 cursor-pointer rounded border border-slate-300 bg-transparent p-0.5 dark:border-slate-700" />
                                    </td>
                                    <td v-for="l in LOCALES" :key="l" class="py-2 pe-2">
                                        <input v-model="settingsForm.codes[s].label[l]" type="text" maxlength="40" :dir="l === 'ar' ? 'rtl' : 'ltr'" :placeholder="builtin(s, l)" :aria-label="`${t(`att.col.label_${l}`)} · ${t(`att.status.${s}`)}`" :class="[input, 'w-full min-w-[8rem]']" />
                                    </td>
                                    <td class="py-2">
                                        <input v-model.number="settingsForm.points[s]" type="number" step="0.25" min="-5" max="5" :aria-label="`${t('att.col.points')} · ${t(`att.status.${s}`)}`" :class="[input, 'w-20']" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section :class="[card, 'grid gap-4 md:grid-cols-2']">
                    <div>
                        <h2 class="mb-2 font-bold text-slate-900 dark:text-slate-100">{{ t('att.rules') }}</h2>
                        <label class="mb-2 block text-sm">{{ t('att.lates_per_unexcused') }}<input v-model.number="settingsForm.rules.lates_per_unexcused" type="number" min="0" max="20" :class="[input, 'mt-1 block w-24']" /></label>
                        <label class="block text-sm">{{ t('att.late_minutes_as_absent') }}<input v-model.number="settingsForm.rules.late_minutes_as_absent" type="number" min="0" max="240" :class="[input, 'mt-1 block w-24']" /></label>
                    </div>
                    <div>
                        <h2 class="mb-2 font-bold text-slate-900 dark:text-slate-100">{{ t('att.alerts') }}</h2>
                        <label class="mb-2 block text-sm">{{ t('att.min_score_pct') }}<input v-model.number="settingsForm.alerts.min_score_pct" type="number" min="0" max="100" :class="[input, 'mt-1 block w-24']" /></label>
                        <label class="block text-sm">{{ t('att.unexcused_streak') }}<input v-model.number="settingsForm.alerts.unexcused_streak" type="number" min="0" max="20" :class="[input, 'mt-1 block w-24']" /></label>
                    </div>
                </section>

                <section :class="card">
                    <h2 class="mb-1 font-bold text-slate-900 dark:text-slate-100">{{ t('att.roster_statuses') }}</h2>
                    <p class="mb-3 text-xs text-slate-500">{{ t('att.roster_statuses_settings_help') }}</p>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        <label v-for="s in playerStatuses" :key="s.id" class="inline-flex items-center gap-1.5">
                            <input v-model="settingsForm.roster_status_ids" type="checkbox" :value="s.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                            {{ s.name }}
                        </label>
                    </div>
                </section>

                <div>
                    <InputError v-for="(e, i) in otherErrors" :key="i" :message="e" />
                    <button type="submit" :disabled="settingsForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                </div>
            </form>

            <!-- Custom codes -->
            <section :class="[card, 'lg:col-span-2']">
                <h2 class="mb-1 font-bold text-slate-900 dark:text-slate-100">{{ t('att.custom.title') }}</h2>
                <p class="mb-3 text-xs text-slate-500">{{ t('att.custom.help') }}</p>
                <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="submitCustom">
                    <label class="text-xs text-slate-500">{{ t('att.col.code') }}
                        <input v-model="customForm.code" type="text" maxlength="3" dir="auto" :class="[input, 'block w-16 text-center font-mono uppercase']" />
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.col.color') }}
                        <input v-model="customForm.color" type="color" class="block h-9 w-12 cursor-pointer rounded border border-slate-300 bg-transparent p-0.5 dark:border-slate-700" />
                    </label>
                    <label v-for="l in LOCALES" :key="l" class="text-xs text-slate-500">{{ t(`att.col.label_${l}`) }}
                        <input v-model="customForm[`label_${l}`]" type="text" maxlength="40" :dir="l === 'ar' ? 'rtl' : 'ltr'" :class="[input, 'block w-36']" />
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.custom.behaviour_label') }}
                        <select v-model="customForm.behaviour" :class="[input, 'block']">
                            <option v-for="b in behaviours" :key="b" :value="b">{{ t(`att.custom.behaviour.${b}`) }}</option>
                        </select>
                    </label>
                    <button type="submit" :disabled="customForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ editingCustomId ? t('att.save') : t('att.custom.add') }}</button>
                    <button v-if="editingCustomId" type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="resetCustom">{{ t('att.close') }}</button>
                    <div class="w-full"><InputError v-for="(e, i) in customErrors" :key="i" :message="e" /></div>
                </form>
                <p v-if="!customStatuses.length" class="text-sm text-slate-500">{{ t('att.custom.none') }}</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr class="text-xs text-slate-500">
                                <th class="py-1 text-start font-semibold">{{ t('att.col.code') }}</th>
                                <th class="py-1 text-start font-semibold">{{ t('att.col.status') }}</th>
                                <th class="py-1 text-start font-semibold">{{ t('att.custom.behaviour_label') }}</th>
                                <th class="py-1 text-start font-semibold">{{ t('att.custom.state') }}</th>
                                <th class="py-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in customStatuses" :key="s.id" class="border-t border-slate-100 dark:border-slate-800" :class="s.is_active ? '' : 'opacity-60'">
                                <td class="py-2 pe-2">
                                    <span class="inline-flex items-center gap-2 font-mono font-semibold"><span class="h-3 w-3 rounded-full" :style="{ backgroundColor: s.color }"></span><bdi>{{ s.code }}</bdi></span>
                                </td>
                                <td class="py-2 pe-2 font-medium">{{ customName(s) }}</td>
                                <td class="py-2 pe-2 text-slate-600 dark:text-slate-300">{{ t(`att.custom.behaviour.${s.behaviour}`) }}</td>
                                <td class="py-2 pe-2 text-xs text-slate-500">{{ s.is_active ? t('att.custom.shown') : t('att.custom.hidden') }}</td>
                                <td class="whitespace-nowrap py-2 text-end">
                                    <IconButton icon="pencil" :label="t('att.edit')" plain size="sm" @click="editCustom(s)" />
                                    <IconButton :icon="s.is_active ? 'archive' : 'restore'" :label="s.is_active ? t('att.custom.hide') : t('att.custom.show')" :variant="s.is_active ? 'neutral' : 'success'" plain size="sm" @click="toggleCustom(s)" />
                                    <IconButton icon="trash" :label="t('att.delete')" variant="danger" plain size="sm" :disabled="s.used" :title="s.used ? t('att.error.code_in_use') : t('att.delete')" @click="destroyCustom(s)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
