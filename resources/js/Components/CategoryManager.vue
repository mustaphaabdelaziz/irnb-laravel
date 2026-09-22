<script setup>
import Modal from '@/Components/Modal.vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();
const props = defineProps({
    show: Boolean,
    categories: { type: Array, default: () => [] },
});
defineEmits(['close']);

const LOCALES = [
    { key: 'name_ar', label: 'العربية', dir: 'rtl' },
    { key: 'name_fr', label: 'Français', dir: 'ltr' },
    { key: 'name_en', label: 'English', dir: 'ltr' },
];
const DEFAULT_COLOR = { income: '#10b981', expense: '#ef4444' };
const inputClass = 'w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 py-1 text-sm';

function blankDraft(type) {
    return { name: '', name_ar: '', name_fr: '', name_en: '', color: DEFAULT_COLOR[type], showTranslations: false };
}

const draft = ref({ income: blankDraft('income'), expense: blankDraft('expense') });
const editingId = ref(null);
const edit = ref({ name: '', name_ar: '', name_fr: '', name_en: '' });
const error = ref('');

function byType(type) {
    return props.categories.filter((c) => c.type === type);
}
function label(cat) {
    return cat.localized_name || cat.name;
}
function onError(errors) {
    error.value = Object.values(errors)[0] || t('save_failed');
}
function payload(cat, overrides = {}) {
    return {
        type: cat.type,
        name: cat.name,
        name_ar: cat.name_ar,
        name_fr: cat.name_fr,
        name_en: cat.name_en,
        color: cat.color,
        ...overrides,
    };
}

function add(type) {
    const d = draft.value[type];
    if (!d.name.trim()) return;
    router.post(route('finance.categories.store'), payload({ ...d, type }), {
        preserveScroll: true,
        onSuccess: () => {
            draft.value[type] = blankDraft(type);
            error.value = '';
        },
        onError,
    });
}
function toggleEdit(cat) {
    if (editingId.value === cat.id) {
        editingId.value = null;
        return;
    }
    editingId.value = cat.id;
    edit.value = { name: cat.name, name_ar: cat.name_ar || '', name_fr: cat.name_fr || '', name_en: cat.name_en || '' };
}
function saveEdit(cat) {
    if (!edit.value.name.trim()) return;
    router.put(route('finance.categories.update', cat.id), payload(cat, edit.value), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
            error.value = '';
        },
        onError,
    });
}
function saveColor(cat) {
    router.put(route('finance.categories.update', cat.id), payload(cat), {
        preserveScroll: true,
        onSuccess: () => { error.value = ''; },
        onError,
    });
}
function remove(cat) {
    router.delete(route('finance.categories.destroy', cat.id), {
        preserveScroll: true,
        // The shared toast already renders flash.error, and it translates the
        // key. Reading the raw prop here would print "flash.category_in_use".
        onSuccess: () => { error.value = ''; },
    });
}
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="$emit('close')">
        <div class="p-6">
            <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('manage_categories') }}</h2>
            <p v-if="error" class="mb-3 rounded-lg bg-rose-50 dark:bg-rose-900/30 px-3 py-2 text-sm text-rose-700 dark:text-rose-300">{{ error }}</p>
            <div class="grid gap-6 sm:grid-cols-2">
                <div v-for="type in ['income', 'expense']" :key="type">
                    <h3 class="mb-2 text-sm font-semibold uppercase text-slate-500">{{ t(type) }}</h3>
                    <ul class="space-y-2">
                        <li v-for="cat in byType(type)" :key="cat.id">
                            <div class="flex items-center gap-2">
                                <input type="color" v-model="cat.color" @change="saveColor(cat)" class="h-7 w-7 rounded border-0 bg-transparent p-0" />
                                <span class="flex-1 truncate text-sm text-slate-800 dark:text-slate-200">{{ label(cat) }}</span>
                                <button type="button" @click="toggleEdit(cat)" class="text-slate-400 hover:text-primary-600" :title="t('edit')">&#9998;</button>
                                <button type="button" @click="remove(cat)" class="text-rose-500 hover:text-rose-700" :title="t('delete')">&times;</button>
                            </div>
                            <div v-if="editingId === cat.id" class="mt-2 space-y-1.5 rounded-lg bg-slate-50 p-2 dark:bg-slate-800/50">
                                <label class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ t('default') }}
                                    <input v-model="edit.name" @keyup.enter="saveEdit(cat)" :class="['mt-0.5', inputClass]" required />
                                </label>
                                <label v-for="l in LOCALES" :key="l.key" class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ l.label }}
                                    <input v-model="edit[l.key]" @keyup.enter="saveEdit(cat)" :dir="l.dir" :class="['mt-0.5', inputClass]" />
                                </label>
                                <div class="flex justify-end gap-2 pt-1">
                                    <button type="button" @click="editingId = null" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">{{ t('cancel') }}</button>
                                    <button type="button" @click="saveEdit(cat)" class="rounded-lg bg-primary-600 px-3 py-1 text-sm font-medium text-white hover:bg-primary-700">{{ t('save') }}</button>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <div class="mt-2 flex items-center gap-2">
                        <input type="color" v-model="draft[type].color" class="h-7 w-7 rounded border-0 bg-transparent p-0" />
                        <input v-model="draft[type].name" @keyup.enter="add(type)" :placeholder="t('new_category')" :class="['flex-1', inputClass]" />
                        <button type="button" @click="add(type)" class="rounded-lg bg-primary-600 px-3 py-1 text-sm font-medium text-white hover:bg-primary-700">+</button>
                    </div>
                    <button type="button" @click="draft[type].showTranslations = !draft[type].showTranslations" class="mt-1 text-xs text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                        {{ draft[type].showTranslations ? '▾' : '▸' }} {{ t('translations') }}
                    </button>
                    <div v-if="draft[type].showTranslations" class="mt-1 space-y-1.5">
                        <input v-for="l in LOCALES" :key="l.key" v-model="draft[type][l.key]" @keyup.enter="add(type)" :dir="l.dir" :placeholder="l.label" :class="inputClass" />
                    </div>
                </div>
            </div>
            <p class="mt-4 text-xs text-slate-400">{{ t('category_in_use_hint') }}</p>
        </div>
    </Modal>
</template>
