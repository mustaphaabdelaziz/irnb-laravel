<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    player: Object,
    categories: Array,
    positions: Array,
    jobs: Array,
    states: Array,
});

const form = useForm({
    _method: 'put',
    firstname: props.player.firstname || '',
    lastname: props.player.lastname || '',
    father: props.player.father || '',
    grandfather: props.player.grandfather || '',
    nickname: props.player.nickname || '',
    birthdate: props.player.birthdate ? String(props.player.birthdate).split('T')[0] : '',
    gender: props.player.gender || 'Male',
    health_blood_group_rhesus: props.player.health_blood_group_rhesus || '',
    phone: props.player.phones?.[0] || '',
    email: props.player.email || '',
    city: props.player.city || '',
    state: props.player.state || '',
    is_student: props.player.is_student ?? true,
    category_id: props.player.category_id || '',
    position_id: props.player.position_id || '',
    member_job_id: props.player.member_job_id || '',
    picture: null,
    health_medical_conditions: props.player.health_medical_conditions || '',
    emergency_contact_name: props.player.emergency_contacts?.[0]?.name || '',
    emergency_contact_phone: props.player.emergency_contacts?.[0]?.phones?.[0] || '',
    emergency_contact_relationship: props.player.emergency_contacts?.[0]?.relationship || '',
    archived: props.player.archived || false,
});

function submit() {
    form.transform(data => ({
        _method: 'put',
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
        city: data.city || null,
        state: data.state || null,
        is_student: data.is_student,
        category_id: data.category_id || null,
        position_id: data.position_id || null,
        member_job_id: data.member_job_id || null,
        picture: data.picture,
        health_medical_conditions: data.health_medical_conditions || null,
        emergency_contacts: data.emergency_contact_name ? [{
            name: data.emergency_contact_name,
            relationship: data.emergency_contact_relationship || null,
            phones: data.emergency_contact_phone ? [data.emergency_contact_phone] : [],
        }] : [],
        archived: data.archived,
    })).post(route('players.update', props.player.id), { forceFormData: true });
}
</script>

<template>
    <Head :title="t('edit_player')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('players.show', player.id)" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('edit_player') }}</h1>
            </div>
        </template>

        <form @submit.prevent="submit" class="space-y-6">
            <!-- Personal Info -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('basic_info') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <InputLabel :value="t('firstname')" />
                        <TextInput v-model="form.firstname" class="mt-1 w-full" required />
                        <InputError :message="form.errors.firstname" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('lastname')" />
                        <TextInput v-model="form.lastname" class="mt-1 w-full" required />
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
                        <TextInput v-model="form.birthdate" type="date" class="mt-1 w-full" required />
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
                    </div>
                    <div>
                        <InputLabel :value="t('city')" />
                        <TextInput v-model="form.city" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('state')" />
                        <select v-model="form.state" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="">-</option>
                            <option v-for="s in states" :key="s" :value="s">{{ s }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Role & Position -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('role_and_position') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <InputLabel :value="t('category')" />
                        <select v-model="form.category_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" required>
                            <option value="">{{ t('select_category') }}</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel :value="t('position')" />
                        <select v-model="form.position_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="">-</option>
                            <option v-for="pos in positions" :key="pos.id" :value="pos.id">{{ pos.abbreviation }} - {{ pos.name }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel :value="t('job')" />
                        <select v-model="form.member_job_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="">-</option>
                            <option v-for="job in jobs" :key="job.id" :value="job.id">{{ job.name }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel :value="t('status')" />
                        <select :value="form.is_student ? 'student' : 'worker'" @change="form.is_student = $event.target.value === 'student'" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="student">{{ t('student') }}</option>
                            <option value="worker">{{ t('worker') }}</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2 pt-6">
                        <input type="checkbox" v-model="form.archived" :id="'archived'" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                        <label :for="'archived'" class="text-sm text-slate-700 dark:text-slate-200">{{ t('archived') }}</label>
                    </div>
                </div>
            </div>

            <!-- Health & Emergency -->
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
                <div v-if="player.picture_url" class="mb-3">
                    <img :src="player.picture_url" :alt="player.firstname" class="h-24 w-24 rounded-lg object-cover" />
                </div>
                <input
                    type="file"
                    accept="image/*"
                    @change="form.picture = $event.target.files[0]"
                    class="text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100"
                />
                <InputError :message="form.errors.picture" class="mt-1" />
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3">
                <Link :href="route('players.show', player.id)">
                    <SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton>
                </Link>
                <PrimaryButton :disabled="form.processing">{{ t('save_changes') }}</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
