<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Modal from '@/Components/Modal.vue';
import TextInput from '@/Components/TextInput.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({ activity: Object });

const canEdit = computed(() => can('activities', 'edit'));
const confirm = ref(null); // { kind: 'activity' | 'photo' | 'file', id }
const lightbox = ref(null);
const captionEdit = ref(null);
const captionText = ref('');

const photoForm = useForm({ photos: [] });
const fileForm = useForm({ file: null });

function uploadPhotos(event) {
    photoForm.photos = Array.from(event.target.files);
    photoForm.post(route('activities.photos.store', props.activity.id), { forceFormData: true, preserveScroll: true, onFinish: () => { event.target.value = ''; photoForm.reset(); } });
}

function uploadFile(event) {
    fileForm.file = event.target.files[0];
    fileForm.post(route('activities.files.store', props.activity.id), { forceFormData: true, preserveScroll: true, onFinish: () => { event.target.value = ''; fileForm.reset(); } });
}

function toggleReport(photo, event) {
    router.put(route('activities.photos.update', [props.activity.id, photo.id]), { in_report: !photo.in_report }, {
        preserveScroll: true,
        // Put the box back to what the server says (another photo may have taken the mark).
        onFinish: () => {
            const current = props.activity.photos.find((p) => p.id === photo.id);
            event.target.checked = Boolean(current?.in_report);
        },
    });
}

function saveCaption(photo) {
    router.put(route('activities.photos.update', [props.activity.id, photo.id]), { caption: captionText.value || null }, { preserveScroll: true, onSuccess: () => { captionEdit.value = null; } });
}

function doDelete() {
    const c = confirm.value;
    const done = { preserveScroll: true, onFinish: () => { confirm.value = null; } };
    if (c.kind === 'activity') router.delete(route('activities.destroy', props.activity.id), done);
    if (c.kind === 'photo') router.delete(route('activities.photos.destroy', [props.activity.id, c.id]), done);
    if (c.kind === 'file') router.delete(route('activities.files.destroy', [props.activity.id, c.id]), done);
}

const a = computed(() => props.activity);
const dates = computed(() => {
    const base = a.value.ends_on && a.value.ends_on !== a.value.starts_on ? `${a.value.starts_on} → ${a.value.ends_on}` : a.value.starts_on;
    return a.value.date_label ? `${a.value.date_label} (${base})` : base;
});
const size = (n) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.ceil(n / 1024)} KB`);
const card = 'rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
</script>

<template>
    <Head :title="a.title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="route('activities.index')" class="text-sm text-slate-500 hover:underline dark:text-slate-400">{{ t('activities') }}</Link>
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ a.title }}</h1>
                </div>
                <div class="flex gap-2">
                    <IconButton v-if="canEdit" icon="pencil" :label="t('edit')" :href="route('activities.edit', a.id)" />
                    <IconButton v-if="can('activities', 'delete')" icon="trash" :label="t('delete')" variant="danger" @click="confirm = { kind: 'activity' }" />
                </div>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section :class="card">
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.type') }}</dt><dd class="font-medium">{{ a.type?.name }}</dd></div>
                        <div><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.dates') }}</dt><dd class="font-medium">{{ dates }}</dd></div>
                        <div v-if="a.organizer"><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.organizer') }}</dt><dd class="font-medium">{{ a.organizer }}</dd></div>
                        <div v-if="a.result"><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.result') }}</dt><dd class="font-medium">{{ a.result }}</dd></div>
                        <div v-if="a.location"><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.location') }}</dt><dd class="font-medium">{{ a.location }}</dd></div>
                        <div v-if="a.partners"><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.partners') }}</dt><dd class="font-medium">{{ a.partners }}</dd></div>
                        <div v-if="a.branch"><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.branch') }}</dt><dd class="font-medium">{{ a.branch.localized_name }}</dd></div>
                        <div v-if="a.categories.length"><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.categories') }}</dt><dd class="font-medium">{{ a.categories.map((c) => c.localized_name).join('، ') }}</dd></div>
                        <div v-if="a.responsible"><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.responsible') }}</dt><dd class="font-medium">{{ a.responsible.name }}</dd></div>
                        <div v-if="a.cost !== null"><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.cost') }}</dt><dd class="font-medium">{{ a.cost }}</dd></div>
                    </dl>
                    <p v-if="a.description" class="mt-4 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ a.description }}</p>
                </section>

                <section :class="card">
                    <h2 class="mb-2 font-semibold">{{ t('activities.report') }}</h2>
                    <p v-if="a.report" class="whitespace-pre-line text-sm leading-relaxed text-slate-700 dark:text-slate-300">{{ a.report }}</p>
                    <p v-else class="text-sm text-slate-400">{{ t('activities.no_report') }}</p>
                </section>

                <section :class="card">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-semibold">{{ t('activities.photos') }}</h2>
                        <label v-if="canEdit" class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-700">
                            <Icon name="upload" class="h-4 w-4" /> {{ t('activities.add_photos') }}
                            <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" :disabled="photoForm.processing" @change="uploadPhotos" />
                        </label>
                    </div>
                    <p v-if="photoForm.errors.photos || Object.keys(photoForm.errors).length" class="mb-2 text-sm text-red-600 dark:text-red-400">{{ Object.values(photoForm.errors)[0] }}</p>
                    <ul v-if="a.photos.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <li v-for="p in a.photos" :key="p.id" class="group relative overflow-hidden rounded-xl ring-1 ring-slate-200 dark:ring-slate-800" :class="p.in_report ? 'ring-2 ring-primary-500' : ''">
                            <button type="button" class="block w-full" @click="lightbox = p"><img :src="p.thumb_url" :alt="p.caption || ''" loading="lazy" class="h-32 w-full object-cover" /></button>
                            <div class="space-y-1 p-2 text-xs">
                                <div v-if="captionEdit === p.id" class="flex gap-1">
                                    <TextInput v-model="captionText" class="w-full text-xs" @keyup.enter="saveCaption(p)" />
                                    <IconButton icon="save" :label="t('save')" plain size="sm" @click="saveCaption(p)" />
                                </div>
                                <p v-else class="truncate text-slate-600 dark:text-slate-300">{{ p.caption || '—' }}</p>
                                <div v-if="canEdit" class="flex items-center justify-between">
                                    <label class="flex items-center gap-1" :title="t('activities.report_photo_hint')">
                                        <input type="checkbox" :checked="p.in_report" class="rounded border-slate-300" @change="toggleReport(p, $event)" />
                                        {{ t('activities.report_photo') }}
                                    </label>
                                    <span class="flex">
                                        <IconButton icon="pencil" :label="t('activities.caption')" plain size="sm" @click="captionEdit = p.id; captionText = p.caption || ''" />
                                        <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="confirm = { kind: 'photo', id: p.id }" />
                                    </span>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-400">{{ t('activities.no_photos') }}</p>
                </section>
            </div>

            <div class="space-y-6">
                <section :class="card">
                    <h2 class="mb-3 font-semibold">{{ t('activities.participants') }} · {{ a.participants_count }}</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('activities.beneficiaries') }}: <strong>{{ a.beneficiaries_count }}</strong></p>
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('activities.external_volunteers') }}: <strong>{{ a.external_volunteers_count }}</strong></p>
                    <div v-if="a.board_members.length" class="mt-3">
                        <h3 class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('activities.board_members') }}</h3>
                        <ul class="mt-1 space-y-0.5 text-sm"><li v-for="m in a.board_members" :key="m.id">{{ m.name }}</li></ul>
                    </div>
                    <div v-if="a.players.length" class="mt-3">
                        <h3 class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('activities.players') }}</h3>
                        <ul class="mt-1 space-y-0.5 text-sm">
                            <li v-for="p in a.players" :key="p.id">
                                <Link v-if="can('players', 'view')" :href="route('players.show', p.id)" class="hover:underline">{{ p.name }}</Link>
                                <span v-else>{{ p.name }}</span>
                            </li>
                        </ul>
                    </div>
                </section>

                <section :class="card">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="font-semibold">{{ t('activities.files') }}</h2>
                        <label v-if="canEdit" class="inline-flex h-7 min-w-7 cursor-pointer items-center justify-center rounded-lg px-1.5 text-primary-600 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-primary-500/10" :title="t('activities.add_file')">
                            <Icon name="upload" class="h-4 w-4" />
                            <span class="sr-only">{{ t('activities.add_file') }}</span>
                            <input type="file" accept=".pdf,.doc,.docx,image/jpeg,image/png,image/webp" class="hidden" :disabled="fileForm.processing" @change="uploadFile" />
                        </label>
                    </div>
                    <p v-if="fileForm.errors.file" class="mb-2 text-sm text-red-600 dark:text-red-400">{{ fileForm.errors.file }}</p>
                    <ul v-if="a.files.length" class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <li v-for="f in a.files" :key="f.id" class="flex items-center justify-between gap-2 py-2">
                            <a :href="route('activities.files.show', [a.id, f.id])" target="_blank" class="truncate hover:underline">{{ f.original_name }}</a>
                            <span class="flex shrink-0 items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ size(f.size) }}
                                <IconButton v-if="canEdit" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="confirm = { kind: 'file', id: f.id }" />
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-400">{{ t('activities.no_files') }}</p>
                </section>
            </div>
        </div>

        <Modal :show="!!lightbox" max-width="2xl" @close="lightbox = null">
            <figure v-if="lightbox" class="bg-black">
                <img :src="lightbox.url" :alt="lightbox.caption || ''" class="mx-auto max-h-[80vh] object-contain" />
                <figcaption v-if="lightbox.caption" class="p-3 text-center text-sm text-white">{{ lightbox.caption }}</figcaption>
            </figure>
        </Modal>

        <ConfirmModal :show="!!confirm" :message="t('are_you_sure')" @confirm="doDelete" @cancel="confirm = null" />
    </AuthenticatedLayout>
</template>
