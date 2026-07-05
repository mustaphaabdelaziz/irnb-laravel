<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    meeting: { type: Object, required: true },
    members: { type: Array, default: () => [] },
    allMembers: { type: Array, default: () => [] },
});
const { t } = useI18n();

function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const form = useForm({
    title: props.meeting.title,
    type: props.meeting.type,
    meeting_date: toLocalInput(props.meeting.meeting_date),
    location: props.meeting.location || '',
    status: props.meeting.status,
    quorum_required: props.meeting.quorum_required,
    agenda: props.meeting.agenda?.length ? [...props.meeting.agenda] : [''],
    minutes: props.meeting.minutes || '',
    decisions: props.meeting.decisions?.length ? [...props.meeting.decisions] : [''],
});
function saveMeeting() {
    form.transform((d) => ({ ...d, agenda: d.agenda.filter((x) => x && x.trim()), decisions: d.decisions.filter((x) => x && x.trim()) }))
        .put(route('board.meetings.update', props.meeting.id), { preserveScroll: true });
}

// Minutes file attachment (PDF / Word / photo). Multipart, so POST not PUT.
const fileForm = useForm({ attachment: null });
function uploadAttachment() {
    if (!fileForm.attachment) return;
    fileForm.post(route('board.meetings.attachment', props.meeting.id), {
        preserveScroll: true, forceFormData: true, onSuccess: () => fileForm.reset(),
    });
}
function removeAttachment() {
    if (window.confirm(t('confirm_delete'))) {
        router.delete(route('board.meetings.attachment.delete', props.meeting.id), { preserveScroll: true });
    }
}
const attachmentName = computed(() => (props.meeting.attachment_filename || '').split('/').pop());

// Attendance
const attendance = reactive({});
props.members.forEach((m) => { attendance[m.id] = m.attendance || 'present'; });
const presentCount = computed(() => Object.values(attendance).filter((s) => s === 'present').length);
function saveAttendance() {
    const rows = Object.entries(attendance).map(([id, status]) => ({ board_member_id: Number(id), status }));
    router.put(route('board.meetings.attendance', props.meeting.id), { attendances: rows }, { preserveScroll: true });
}

// Tasks
const taskForm = useForm({ title: '', board_member_id: null, due_date: '', priority: 'medium', status: 'not_started', progress: 0, board_meeting_id: props.meeting.id });
function addTask() {
    taskForm.post(route('board.tasks.store'), { preserveScroll: true, onSuccess: () => taskForm.reset('title', 'board_member_id', 'due_date') });
}
function deleteTask(tk) { if (window.confirm(t('confirm_delete'))) router.delete(route('board.tasks.destroy', tk.id), { preserveScroll: true }); }

const attStyle = {
    present: 'bg-emerald-500 text-white',
    absent: 'bg-rose-500 text-white',
    excused: 'bg-amber-500 text-white',
};
</script>

<template>
    <Head :title="meeting.title" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('board.meetings')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"><Icon name="back" /></Link>
                <h1 class="truncate text-lg font-bold text-slate-900 dark:text-slate-100">{{ meeting.title }}</h1>
                <a :href="route('board.meetings.minutes', meeting.id)" target="_blank" class="ms-auto inline-flex items-center gap-1.5 rounded-xl bg-white px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="print" /> {{ t('minutes') }}</a>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Left: details + minutes -->
            <div class="space-y-6 lg:col-span-2">
                <!-- Core fields -->
                <section class="card space-y-4 p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm sm:col-span-2"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('title') }}</span>
                            <input v-model="form.title" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('meeting_type') }}</span>
                            <select v-model="form.type" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="ordinary">{{ t('ordinary') }}</option><option value="extraordinary">{{ t('extraordinary') }}</option><option value="general_assembly">{{ t('general_assembly') }}</option>
                            </select></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('status') }}</span>
                            <select v-model="form.status" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="scheduled">{{ t('scheduled') }}</option><option value="held">{{ t('held') }}</option><option value="cancelled">{{ t('cancelled') }}</option>
                            </select></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('date') }}</span>
                            <input v-model="form.meeting_date" type="datetime-local" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('location') }}</span>
                            <input v-model="form.location" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    </div>
                    <div>
                        <span class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">{{ t('agenda') }}</span>
                        <div class="space-y-2">
                            <div v-for="(item, i) in form.agenda" :key="i" class="flex items-center gap-2">
                                <span class="text-xs font-bold text-slate-400">{{ i + 1 }}</span>
                                <input v-model="form.agenda[i]" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                                <button type="button" @click="form.agenda.splice(i, 1)" class="text-slate-300 hover:text-rose-500"><Icon name="xcircle" /></button>
                            </div>
                        </div>
                        <button type="button" @click="form.agenda.push('')" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary-600"><Icon name="plus" /> {{ t('add_item') }}</button>
                    </div>
                </section>

                <!-- Minutes + decisions -->
                <section class="card space-y-4 p-5">
                    <div>
                        <span class="mb-1 block text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('minutes') }}</span>
                        <textarea v-model="form.minutes" rows="6" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                    </div>

                    <!-- Minutes file (PDF / Word / photo) -->
                    <div>
                        <span class="mb-1 block text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('minutes_file') }}</span>
                        <div v-if="meeting.attachment_url" class="mb-2 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                            <Icon name="document" class="text-primary-500" />
                            <a :href="meeting.attachment_url" target="_blank" class="min-w-0 flex-1 truncate text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">{{ attachmentName || t('view_file') }}</a>
                            <button type="button" @click="removeAttachment" class="text-slate-300 hover:text-rose-500" :title="t('remove')"><Icon name="xcircle" /></button>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="file" accept=".pdf,.doc,.docx,image/*" @change="fileForm.attachment = $event.target.files[0]"
                                class="block w-full max-w-xs text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-primary-700 hover:file:bg-primary-100 dark:text-slate-400 dark:file:bg-primary-500/10 dark:file:text-primary-300" />
                            <button type="button" @click="uploadAttachment" :disabled="!fileForm.attachment || fileForm.processing"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-40 dark:bg-slate-100 dark:text-slate-900"><Icon name="upload" /> {{ t('upload') }}</button>
                        </div>
                        <p v-if="fileForm.errors.attachment" class="mt-1 text-xs text-rose-500">{{ fileForm.errors.attachment }}</p>
                    </div>
                    <div>
                        <span class="mb-1 block text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('decisions') }}</span>
                        <div class="space-y-2">
                            <div v-for="(d, i) in form.decisions" :key="i" class="flex items-center gap-2">
                                <Icon name="check" class="text-emerald-500" />
                                <input v-model="form.decisions[i]" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                                <button type="button" @click="form.decisions.splice(i, 1)" class="text-slate-300 hover:text-rose-500"><Icon name="xcircle" /></button>
                            </div>
                        </div>
                        <button type="button" @click="form.decisions.push('')" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary-600"><Icon name="plus" /> {{ t('add_item') }}</button>
                    </div>
                    <div class="flex justify-end">
                        <button @click="saveMeeting" :disabled="form.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('save_minutes') }}</button>
                    </div>
                </section>

                <!-- Tasks from this meeting -->
                <section class="card p-5">
                    <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('action_items') }}</p>
                    <ul class="space-y-2">
                        <li v-for="tk in meeting.tasks" :key="tk.id" class="flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ tk.title }}</span>
                                <span class="text-xs text-slate-400">{{ tk.member?.name || t('unassigned') }} · {{ t(tk.status) }}</span>
                            </span>
                            <span class="text-xs font-bold text-slate-500">{{ tk.progress }}%</span>
                            <button @click="deleteTask(tk)" class="text-slate-300 hover:text-rose-500"><Icon name="xcircle" /></button>
                        </li>
                    </ul>
                    <form @submit.prevent="addTask" class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                        <input v-model="taskForm.title" :placeholder="t('task_title')" required class="flex-1 rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                        <select v-model="taskForm.board_member_id" class="rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option :value="null">{{ t('unassigned') }}</option>
                            <option v-for="m in allMembers" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                        <input v-model="taskForm.due_date" type="date" class="rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                        <button class="rounded-xl bg-primary-600 px-3 py-2 text-sm font-bold text-white hover:bg-primary-700"><Icon name="plus" /></button>
                    </form>
                </section>
            </div>

            <!-- Right: attendance -->
            <div class="space-y-6">
                <section class="card p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('attendance') }}</p>
                        <span class="text-xs font-semibold text-slate-400">{{ presentCount }}<span v-if="meeting.quorum_required">/{{ meeting.quorum_required }}</span></span>
                    </div>
                    <ul class="space-y-2">
                        <li v-for="m in members" :key="m.id" class="flex items-center justify-between gap-2">
                            <span class="min-w-0 truncate text-sm text-slate-700 dark:text-slate-200">{{ m.name }} <span class="text-xs text-slate-400">{{ t(m.role) }}</span></span>
                            <div class="flex shrink-0 gap-1">
                                <button v-for="s in ['present', 'absent', 'excused']" :key="s" @click="attendance[m.id] = s"
                                    class="rounded-md px-2 py-0.5 text-xs font-bold transition-colors"
                                    :class="attendance[m.id] === s ? attStyle[s] : 'bg-slate-100 text-slate-400 dark:bg-slate-800'">{{ t(s).charAt(0) }}</button>
                            </div>
                        </li>
                    </ul>
                    <p v-if="!members.length" class="py-4 text-center text-xs text-slate-400">{{ t('no_data') }}</p>
                    <button v-if="members.length" @click="saveAttendance" class="mt-3 w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900">{{ t('save_attendance') }}</button>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
