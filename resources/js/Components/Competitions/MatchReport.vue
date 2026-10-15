<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import IconButton from '@/Components/IconButton.vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();
const props = defineProps({ gameId: Number, report: Object, canManage: Boolean });
const rules = props.report.rules;
const isSets = rules.scoring === 'sets';
const blank = (v) => (v === null || v === undefined ? '' : v);
const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

const periods = ref([]);
const et = ref({});
const shootout = ref({});
const typed = ref({});
const events = ref([]);
const players = ref([]);
const mvp = ref(null);
const notes = ref('');
// Local edit state, rebuilt from the server's report on mount and after each
// successful save (onSuccess page props; see Games/Show.vue): computed minutes,
// the reconciled score and the timeline show as stored, not as typed.
function load(report) {
    periods.value = Array.from({ length: rules.periods_count }, (_, i) => {
        const row = report.period_scores?.[i];
        return { us: blank(row?.[0]), them: blank(row?.[1]) };
    });
    et.value = {
        played: report.extra_time_played,
        us: blank(report.period_scores?.[rules.periods_count]?.[0]),
        them: blank(report.period_scores?.[rules.periods_count]?.[1]),
    };
    shootout.value = { us: blank(report.shootout_us), them: blank(report.shootout_them) };
    typed.value = { us: blank(report.our_score), them: blank(report.their_score) };
    events.value = report.events.map((e) => ({ ...e, minute: blank(e.minute), period: blank(e.period), player_id: blank(e.player_id), related_player_id: blank(e.related_player_id) }));
    players.value = report.players.map((p) => ({ ...p, minutes_played: blank(p.minutes_played), sets_played: blank(p.sets_played), rating: blank(p.rating), coach_note: p.coach_note ?? '' }));
    mvp.value = report.players.find((p) => p.is_mvp)?.player_id ?? null;
    notes.value = report.notes ?? '';
}
load(props.report);

const derived = computed(() => {
    const rows = [...periods.value, ...(et.value.played ? [et.value] : [])].filter((r) => r.us !== '' && r.them !== '');
    if (!rows.length) return null;
    if (isSets) return { us: rows.filter((r) => +r.us > +r.them).length, them: rows.filter((r) => +r.them > +r.us).length };
    return { us: rows.reduce((s, r) => s + +r.us, 0), them: rows.reduce((s, r) => s + +r.them, 0) };
});

const types = computed(() => (rules.rolling_subs ? ['goal', 'sanction'] : ['goal', 'sanction', 'substitution']));
const goalKinds = ['normal', 'penalty', 'free_kick', 'opponent_own_goal'];
const lineupPlayers = computed(() => props.report.players.map((p) => ({ id: p.player_id, label: `${p.shirt_number ?? ''} ${p.name}`.trim() })));
const sanctionLabel = (key) => {
    const label = t(`comp.sanction.${key}`);
    return label === `comp.sanction.${key}` ? key : label;
};
function addEvent(type) {
    events.value.push({
        type,
        subtype: type === 'goal' ? 'normal' : type === 'sanction' ? (rules.sanction_types[0]?.key ?? '') : null,
        period: '', minute: '', player_id: '', related_player_id: '',
    });
}
// Shown in minute order (set order when untimed); events without one stay at the end in entry order.
const timing = (e) => (rules.untimed ? e.period : e.minute);
const ordered = computed(() => events.value
    .map((e, index) => ({ e, index }))
    .sort((a, b) => (timing(a.e) === '' ? 1e9 : +timing(a.e)) - (timing(b.e) === '' ? 1e9 : +timing(b.e)) || a.index - b.index));

const form = useForm({});
function save() {
    form.transform(() => ({
        period_scores: [
            ...periods.value.map((r) => [num(r.us), num(r.them)]),
            ...(et.value.played ? [[num(et.value.us), num(et.value.them)]] : []),
        ],
        extra_time_played: !!et.value.played,
        our_score: num(typed.value.us),
        their_score: num(typed.value.them),
        shootout_us: num(shootout.value.us),
        shootout_them: num(shootout.value.them),
        events: events.value.map((e) => ({
            type: e.type, subtype: e.subtype || null, period: num(e.period), minute: num(e.minute),
            player_id: e.subtype === 'opponent_own_goal' ? null : num(e.player_id),
            related_player_id: e.subtype === 'opponent_own_goal' ? null : num(e.related_player_id),
        })),
        players: players.value.map((p) => ({
            player_id: p.player_id, played: !!p.played, minutes_played: num(p.minutes_played), sets_played: num(p.sets_played),
            rating: num(p.rating), coach_note: p.coach_note || null,
        })),
        mvp_player_id: mvp.value,
        notes: notes.value || null,
    })).put(route('games.report.update', props.gameId), {
        preserveScroll: true,
        onSuccess: (page) => load(page.props.report),
    });
}
const err = (key) => (form.errors[key] ? t(form.errors[key]) : '');
const icon = { goal: '⚽', sanction: '🟨', substitution: '🔁' };
</script>

<template>
    <div class="space-y-6">
        <ul v-if="report.warnings.length" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            <li v-for="w in report.warnings" :key="w">{{ t(w) }}</li>
        </ul>

        <!-- 1. Score -->
        <section class="space-y-2">
            <h3 class="font-semibold">{{ t('comp.report_score') }}</h3>
            <div class="grid max-w-md grid-cols-[1fr_5rem_5rem] items-center gap-2 text-sm" dir="ltr">
                <span></span><span class="text-center text-xs uppercase text-slate-500">{{ t('comp.us') }}</span><span class="text-center text-xs uppercase text-slate-500">{{ t('comp.them') }}</span>
                <template v-for="(row, i) in periods" :key="i">
                    <span>{{ t(`comp.period_${rules.period_label}`) }} {{ i + 1 }}</span>
                    <input v-model="row.us" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="row.them" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                </template>
                <template v-if="rules.has_extra_time">
                    <label class="flex items-center gap-2"><input v-model="et.played" :disabled="!canManage" type="checkbox" class="rounded" /> {{ t('comp.extra_time') }}</label>
                    <input v-model="et.us" :disabled="!canManage || !et.played" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="et.them" :disabled="!canManage || !et.played" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                </template>
                <template v-if="rules.has_shootout">
                    <span>{{ t('comp.shootout') }}</span>
                    <input v-model="shootout.us" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="shootout.them" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                </template>
                <span class="font-semibold">{{ t('comp.final_score') }}</span>
                <template v-if="derived">
                    <span class="text-center text-lg font-bold">{{ derived.us }}</span><span class="text-center text-lg font-bold">{{ derived.them }}</span>
                </template>
                <template v-else>
                    <input v-model="typed.us" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="typed.them" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                </template>
            </div>
            <InputError :message="err('our_score')" />
        </section>

        <!-- 2. Timeline -->
        <section class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="font-semibold">{{ t('comp.timeline') }}</h3>
                <template v-if="canManage">
                    <SecondaryButton v-for="type in types" :key="type" @click="addEvent(type)">+ {{ t(`comp.event_${type}`) }}</SecondaryButton>
                </template>
            </div>
            <div v-for="{ e, index } in ordered" :key="index" class="flex flex-wrap items-start gap-2 rounded-lg bg-slate-50 p-2 text-sm dark:bg-slate-800/60">
                <span class="w-6 text-center">{{ icon[e.type] }}</span>
                <!-- Untimed formats (sets, no clock): the period/set number is the timing. -->
                <div v-if="rules.untimed">
                    <select v-model="e.period" :disabled="!canManage" :aria-label="t(`comp.period_${rules.period_label}`)" class="rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t(`comp.period_${rules.period_label}`) }}</option>
                        <option v-for="n in rules.periods_count" :key="n" :value="n">{{ t(`comp.period_${rules.period_label}`) }} {{ n }}</option>
                    </select>
                    <InputError :message="err(`events.${index}.period`)" />
                </div>
                <div v-else>
                    <input v-model="e.minute" :disabled="!canManage" type="number" min="0" :placeholder="t('comp.minute')" class="w-20 rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <InputError :message="err(`events.${index}.minute`)" />
                </div>
                <select v-if="e.type === 'goal'" v-model="e.subtype" :disabled="!canManage" class="rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option v-for="k in goalKinds" :key="k" :value="k">{{ t(`comp.goal_${k}`) }}</option>
                </select>
                <select v-if="e.type === 'sanction'" v-model="e.subtype" :disabled="!canManage" class="rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option v-for="s in rules.sanction_types" :key="s.key" :value="s.key">{{ sanctionLabel(s.key) }}</option>
                </select>
                <div v-if="e.subtype !== 'opponent_own_goal'">
                    <select v-model="e.player_id" :disabled="!canManage" class="rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t(e.type === 'substitution' ? 'comp.player_in' : e.type === 'goal' ? 'comp.scorer' : 'comp.player') }}</option>
                        <option v-for="p in lineupPlayers" :key="p.id" :value="p.id">{{ p.label }}</option>
                    </select>
                    <InputError :message="err(`events.${index}.player_id`)" />
                </div>
                <div v-if="e.type !== 'sanction' && e.subtype !== 'opponent_own_goal'">
                    <select v-model="e.related_player_id" :disabled="!canManage" class="rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t(e.type === 'substitution' ? 'comp.player_out' : 'comp.assist') }}</option>
                        <option v-for="p in lineupPlayers" :key="p.id" :value="p.id">{{ p.label }}</option>
                    </select>
                    <InputError :message="err(`events.${index}.related_player_id`)" />
                </div>
                <InputError :message="err(`events.${index}.subtype`) || err(`events.${index}.type`)" />
                <IconButton v-if="canManage" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="events.splice(index, 1)" />
            </div>
            <p v-if="!events.length" class="text-sm text-slate-500">{{ t('comp.no_events') }}</p>
        </section>

        <!-- 3. Player grid -->
        <section class="space-y-2">
            <h3 class="font-semibold">{{ t('comp.player_grid') }}</h3>
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead class="text-xs uppercase text-slate-500"><tr>
                    <th class="py-1 text-start">{{ t('comp.player') }}</th>
                    <th v-if="rules.rolling_subs" class="py-1">{{ t('comp.played') }}</th>
                    <th class="py-1">{{ isSets ? t('comp.sets_played') : t('comp.minutes') }}</th>
                    <th class="py-1">{{ t('comp.rating') }}</th>
                    <th class="py-1 text-start">{{ t('comp.coach_note') }}</th>
                    <th class="py-1">{{ t('comp.mvp_short') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="(p, i) in players" :key="p.player_id">
                        <td class="py-1"><span class="font-mono text-slate-500">{{ p.shirt_number }}</span> {{ p.name }}
                            <span class="text-xs text-slate-400">· {{ t(p.role === 'starter' ? 'comp.starter' : 'comp.substitute') }}</span></td>
                        <td v-if="rules.rolling_subs" class="py-1 text-center"><input v-model="p.played" :disabled="!canManage" type="checkbox" class="rounded" /></td>
                        <td class="py-1 text-center">
                            <input v-if="isSets" v-model="p.sets_played" :disabled="!canManage" type="number" min="0" :max="rules.periods_count" class="w-16 rounded border-slate-300 text-center text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <input v-else-if="rules.rolling_subs" v-model="p.minutes_played" :disabled="!canManage" type="number" min="0" class="w-16 rounded border-slate-300 text-center text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <span v-else class="font-mono">{{ p.minutes_played ?? '—' }}</span>
                            <InputError :message="err(`players.${i}.minutes_played`) || err(`players.${i}.sets_played`)" />
                        </td>
                        <td class="py-1 text-center"><input v-model="p.rating" :disabled="!canManage" type="number" min="1" max="10" class="w-14 rounded border-slate-300 text-center text-sm dark:border-slate-700 dark:bg-slate-800" /></td>
                        <td class="py-1"><input v-model="p.coach_note" :disabled="!canManage" class="w-full rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" /></td>
                        <td class="py-1 text-center"><input v-model="mvp" :value="p.player_id" :disabled="!canManage" type="radio" name="mvp" /></td>
                    </tr>
                </tbody>
            </table>
            <p class="text-xs text-slate-500">{{ t('comp.ratings_private') }}</p>
        </section>

        <!-- 4. Notes -->
        <label class="block text-sm">{{ t('notes') }}
            <textarea v-model="notes" :disabled="!canManage" rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
        </label>

        <div class="flex flex-wrap items-center gap-3">
            <PrimaryButton v-if="canManage" :disabled="form.processing" @click="save">{{ t('comp.save_report') }}</PrimaryButton>
            <a v-if="report.status === 'played'" :href="route('games.report-sheet', gameId)" target="_blank" class="text-sm text-primary-600 hover:underline">{{ t('comp.report_sheet_pdf') }}</a>
        </div>
    </div>
</template>
