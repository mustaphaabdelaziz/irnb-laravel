<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps({
    player: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    positions: { type: Array, default: () => [] },
    jobs: { type: Array, default: () => [] },
    wilayas: { type: Array, default: () => [] },
    communes: { type: Object, default: () => ({}) },
    nextSequenceByYear: { type: Object, default: () => ({}) },
    defaultJoinYear: { type: Number, default: () => new Date().getFullYear() },
});

const isEdit = !!props.player;
const p = props.player || {};

const form = useForm({
    firstname: p.firstname || '',
    lastname: p.lastname || '',
    father: p.father || '',
    grandfather: p.grandfather || '',
    nickname: p.nickname || '',
    birthdate: p.birthdate ? String(p.birthdate).split('T')[0] : '',
    gender: p.gender || 'Male',
    health_blood_group_rhesus: p.health_blood_group_rhesus || '',
    phone: p.phones?.[0] || '',
    email: p.email || '',
    state: p.state || '',
    city: p.city || '',
    is_student: p.is_student ?? true,
    category_id: p.category_id || '',
    position_id: p.position_id || '',
    member_job_id: p.member_job_id || '',
    join_year: p.join_year || props.defaultJoinYear,
    team: p.team || '',
    skill_level: p.skill_level || '',
    picture: null,
    health_medical_conditions: p.health_medical_conditions || '',
    emergency_contact_name: p.emergency_contacts?.[0]?.name || '',
    emergency_contact_phone: p.emergency_contacts?.[0]?.phones?.[0] || '',
    emergency_contact_relationship: p.emergency_contacts?.[0]?.relationship || '',
    archived: p.archived || false,
});

// --- Membership id preview ---
const pad5 = (n) => String(n).padStart(5, '0');
const membershipPreview = computed(() => {
    if (isEdit) return p.membership_id || '';
    const seq = props.nextSequenceByYear[form.join_year] ?? 1;
    return `${form.join_year}-${pad5(seq)}`;
});

// --- Wilaya -> city dependency ---
const wilayaOptions = computed(() => {
    const opts = props.wilayas.map((w) => ({ value: w.name, label: `${w.name} — ${w.ar_name}` }));
    if (form.state && !opts.some((o) => o.value === form.state)) opts.unshift({ value: form.state, label: form.state });
    return opts;
});
const selectedWilayaId = computed(() => props.wilayas.find((w) => w.name === form.state)?.id ?? null);
const cityList = computed(() => (selectedWilayaId.value != null ? (props.communes[selectedWilayaId.value] || []) : []));
const hasCityList = computed(() => cityList.value.length > 0);
const cityOptions = computed(() => {
    const opts = cityList.value.map((c) => ({ value: c, label: c }));
    if (form.city && !opts.some((o) => o.value === form.city)) opts.unshift({ value: form.city, label: form.city });
    return opts;
});
// reset city when wilaya changes (fires only on change, not initial mount)
watch(() => form.state, () => { form.city = ''; });

// --- Job gated on worker ---
watch(() => form.is_student, (student) => { if (student) form.member_job_id = ''; });

// --- Image preview ---
const previewUrl = ref(null);
function setPicture(file) {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    form.picture = file || null;
    previewUrl.value = file ? URL.createObjectURL(file) : null;
}
function onFileChange(e) { setPicture(e.target.files?.[0]); }
function onDrop(e) { setPicture(e.dataTransfer.files?.[0]); }
function clearPicture() { setPicture(null); }
const shownImage = computed(() => previewUrl.value || (isEdit ? p.picture_url : null));
onBeforeUnmount(() => { if (previewUrl.value) URL.revokeObjectURL(previewUrl.value); });

function submit() {
    form.transform((data) => ({
        ...(isEdit ? { _method: 'put' } : {}),
        firstname: data.firstname,
        lastname: data.lastname,
        father: data.father,
        grandfather: data.grandfather,
        nickname: data.nickname,
        birthdate: data.birthdate || null,
        gender: data.gender,
        health_blood_group_rhesus: data.health_blood_group_rhesus || null,
        phones: data.phone ? [data.phone] : [],
        email: data.email || null,
        state: data.state || null,
        city: data.city || null,
        is_student: data.is_student,
        category_id: data.category_id || null,
        position_id: data.position_id || null,
        member_job_id: data.member_job_id || null,
        join_year: data.join_year || null,
        team: data.team || null,
        skill_level: data.skill_level || null,
        picture: data.picture,
        health_medical_conditions: data.health_medical_conditions || null,
        emergency_contacts: data.emergency_contact_name ? [{
            name: data.emergency_contact_name,
            relationship: data.emergency_contact_relationship || null,
            phones: data.emergency_contact_phone ? [data.emergency_contact_phone] : [],
        }] : [],
        ...(isEdit ? { archived: data.archived } : {}),
    })).post(isEdit ? route('players.update', p.id) : route('players.store'), { forceFormData: true });
}

const cancelHref = computed(() => (isEdit ? route('players.show', p.id) : route('players.index')));
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <!-- Basic info -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('basic_info') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <InputLabel :value="t('firstname') + ' *'" />
                    <TextInput v-model="form.firstname" class="mt-1 w-full" required />
                    <InputError :message="form.errors.firstname" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('lastname')" />
                    <TextInput v-model="form.lastname" class="mt-1 w-full" />
                    <InputError :message="form.errors.lastname" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('nickname')" />
                    <TextInput v-model="form.nickname" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('father')" />
                    <TextInput v-model="form.father" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('grandfather')" />
                    <TextInput v-model="form.grandfather" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('date_of_birth')" />
                    <TextInput v-model="form.birthdate" type="date" class="mt-1 w-full" />
                    <InputError :message="form.errors.birthdate" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('gender')" />
                    <select v-model="form.gender" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="Male">{{ t('male') }}</option>
                        <option value="Female">{{ t('female') }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('blood_group')" />
                    <select v-model="form.health_blood_group_rhesus" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">-</option>
                        <option v-for="bg in ['A+','A-','B+','B-','AB+','AB-','O+','O-']" :key="bg" :value="bg">{{ bg }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('phone')" />
                    <TextInput v-model="form.phone" type="tel" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('email')" />
                    <TextInput v-model="form.email" type="email" class="mt-1 w-full" />
                    <InputError :message="form.errors.email" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('state')" />
                    <SearchableSelect v-model="form.state" :options="wilayaOptions" :placeholder="t('search_wilaya')" />
                    <InputError :message="form.errors.state" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('city')" />
                    <SearchableSelect v-if="hasCityList" v-model="form.city" :options="cityOptions" :placeholder="t('search_city')" />
                    <TextInput v-else v-model="form.city" class="mt-1 w-full" />
                    <InputError :message="form.errors.city" class="mt-1" />
                </div>
            </div>
        </div>

        <!-- Club details -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('role_and_position') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <InputLabel :value="t('membership_id')" />
                    <TextInput :model-value="membershipPreview" class="mt-1 w-full bg-slate-50 dark:bg-slate-950" readonly disabled />
                    <p class="mt-1 text-xs text-slate-400">{{ t('auto_generated') }}</p>
                </div>
                <div>
                    <InputLabel :value="t('join_year')" />
                    <TextInput v-model="form.join_year" type="number" min="1900" :max="defaultJoinYear + 1" class="mt-1 w-full" />
                    <InputError :message="form.errors.join_year" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('category')" />
                    <select v-model="form.category_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">{{ t('select_category') }}</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                    </select>
                    <InputError :message="form.errors.category_id" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('position')" />
                    <select v-model="form.position_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">-</option>
                        <option v-for="pos in positions" :key="pos.id" :value="pos.id">{{ pos.abbreviation }} - {{ pos.name }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('team')" />
                    <TextInput v-model="form.team" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('skill_level')" />
                    <select v-model="form.skill_level" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">-</option>
                        <option v-for="n in 10" :key="n" :value="n">{{ n }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('status')" />
                    <select :value="form.is_student ? 'student' : 'worker'" @change="form.is_student = $event.target.value === 'student'" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="student">{{ t('student') }}</option>
                        <option value="worker">{{ t('worker') }}</option>
                    </select>
                </div>
                <div v-if="!form.is_student">
                    <InputLabel :value="t('job')" />
                    <select v-model="form.member_job_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">-</option>
                        <option v-for="job in jobs" :key="job.id" :value="job.id">{{ job.name }}</option>
                    </select>
                </div>
                <div v-if="isEdit" class="flex items-center gap-2 pt-6">
                    <input type="checkbox" v-model="form.archived" id="archived" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                    <label for="archived" class="text-sm text-slate-700 dark:text-slate-200">{{ t('archived') }}</label>
                </div>
            </div>
        </div>

        <!-- Health & emergency -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('health_information') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <InputLabel :value="t('medical_conditions')" />
                    <textarea v-model="form.health_medical_conditions" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                </div>
                <div>
                    <InputLabel :value="t('emergency_contact') + ' - ' + t('name')" />
                    <TextInput v-model="form.emergency_contact_name" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('emergency_contact') + ' - ' + t('phone')" />
                    <TextInput v-model="form.emergency_contact_phone" type="tel" class="mt-1 w-full" />
                </div>
            </div>
        </div>

        <!-- Picture -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('picture') }}</h2>
            <div class="flex items-center gap-4">
                <img v-if="shownImage" :src="shownImage" alt="" class="h-24 w-24 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-slate-700" />
                <div v-else class="flex h-24 w-24 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-400 text-xs">{{ t('picture') }}</div>
                <div class="flex-1" @drop.prevent="onDrop" @dragover.prevent>
                    <label class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 dark:border-slate-700 px-4 py-4 text-center hover:border-primary-400">
                        <span class="text-sm text-slate-500 dark:text-slate-400">{{ t('drag_drop_image') }}</span>
                        <input type="file" accept="image/*" class="hidden" @change="onFileChange" />
                    </label>
                    <button v-if="previewUrl" type="button" @click="clearPicture" class="mt-2 text-xs text-rose-500 hover:text-rose-700">{{ t('remove') }}</button>
                    <InputError :message="form.errors.picture" class="mt-1" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="cancelHref"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('save') }}</PrimaryButton>
        </div>
    </form>
</template>
