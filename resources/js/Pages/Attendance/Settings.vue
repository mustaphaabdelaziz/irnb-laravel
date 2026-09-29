<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    schedules: { type: Array, default: () => [] },
    closures: { type: Array, default: () => [] },
    targets: { type: Array, default: () => [] },
    seasons: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
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
const submitSettings = () => settingsForm.put(route('attendance.settings.update'), { preserveScroll: true });
// The built-in name in one language, shown as the placeholder of that language's field.
const builtin = (status, loc) => t(`att.status.${status}`, {}, { locale: loc });
const rowError = (status) => tr(settingsForm.errors[`codes.${status}.code`] ?? settingsForm.errors[`codes.${status}.color`]);
const otherErrors = computed(() => Object.entries(settingsForm.errors)
    .filter(([k]) => !/^codes\.[a-z_]+\.(code|color)$/.test(k))
    .map(([, e]) => tr(e)));
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
                                <button class="p-1 text-slate-400 hover:text-primary-600" :title="t('att.edit')" @click="editSchedule(s)"><Icon name="pencil" /></button>
                                <button class="p-1 text-slate-400 hover:text-rose-600" :title="t('att.delete')" @click="destroy('attendance.schedules.destroy', s.id)"><Icon name="trash" /></button>
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
                        <button class="p-1 text-slate-400 hover:text-rose-600" :title="t('att.delete')" @click="destroy('attendance.closures.destroy', c.id)"><Icon name="trash" /></button>
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
                                        <InputError :message="rowError(s)" />
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

                <div>
                    <InputError v-for="(e, i) in otherErrors" :key="i" :message="e" />
                    <button type="submit" :disabled="settingsForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
