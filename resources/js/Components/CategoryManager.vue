<script setup>
import { computed, nextTick, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useCan } from '@/Composables/useCan';

/**
 * The one place finance categories are managed — opened from the
 * Transactions "more" menu and from Finance settings.
 *
 * One name field per language. Older categories hold their base `name` in no
 * language field. The form shows it in an empty field of its script: Arabic
 * text in the Arabic field; Latin text in the English field for built-in
 * categories (seeded in English) and the French field for ones the club typed,
 * else the other Latin one. The server keeps a base name (English, else
 * French, else Arabic) that fills any language left empty, and keeps the old
 * base when a save renames nothing (FinanceCategoryController). Edits go
 * through a form, never into the `categories` prop.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    // Every category, active or not, with transactions_count.
    categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const { can } = useCan();

const ARABIC = /[\u0600-\u06FF]/;
const DEFAULT_COLOR = { income: '#10b981', expense: '#ef4444' };

const languages = computed(() => [
    { key: 'name_ar', label: t('fincat.name_ar'), dir: 'rtl' },
    { key: 'name_fr', label: t('fincat.name_fr'), dir: 'ltr' },
    { key: 'name_en', label: t('fincat.name_en'), dir: 'ltr' },
]);

const groups = computed(() => ['income', 'expense'].map((type) => ({
    type,
    items: props.categories.filter((c) => c.type === type),
})));

const label = (c) => c.localized_name || c.name;
const used = (c) => Number(c.transactions_count) || 0;
const deletable = (c) => !c.is_system && used(c) === 0 && can('finance', 'delete');

// --- Add / edit: one form; `editing` is the category, null for a new one ---
const formOpen = ref(false);
const editing = ref(null);
const form = useForm({ type: 'income', name_ar: '', name_fr: '', name_en: '', color: DEFAULT_COLOR.income, is_active: true });

function fill(values) {
    Object.entries(values).forEach(([key, value]) => { form[key] = value; });
    form.clearErrors();
}

// The base shows in a field only when no field holds it yet, and only in an
// empty one: a save that renames nothing must send the names as they are.
function namesOf(c) {
    const names = { name_ar: c.name_ar || '', name_fr: c.name_fr || '', name_en: c.name_en || '' };
    const base = (c.name || '').trim();
    if (!base || Object.values(names).some((n) => n.trim() === base)) return names;
    const slots = ARABIC.test(base) ? ['name_ar'] : (c.is_system ? ['name_en', 'name_fr'] : ['name_fr', 'name_en']);
    const slot = slots.find((key) => !names[key]);
    if (slot) names[slot] = base;
    return names;
}

// Focus: the first name field when the form opens; back to the button that
// opened it (found again by key, the list re-renders) when it closes.
const root = ref(null);
const firstName = ref(null);
let opener = null;

function openForm(key) {
    opener = key;
    formOpen.value = true;
    nextTick(() => firstName.value?.focus());
}

function closeForm() {
    formOpen.value = false;
    nextTick(() => root.value?.querySelector(`[data-opener="${opener}"]`)?.focus());
}

function openAdd(type) {
    editing.value = null;
    fill({ type, name_ar: '', name_fr: '', name_en: '', color: DEFAULT_COLOR[type], is_active: true });
    openForm(`add-${type}`);
}

function openEdit(c) {
    editing.value = c;
    fill({ type: c.type, ...namesOf(c), color: c.color || DEFAULT_COLOR[c.type], is_active: c.is_active !== false });
    openForm(`edit-${c.id}`);
}

function save() {
    // preserveState: the page keeps this dialog (and its filters) open across the reload.
    const options = { preserveScroll: true, preserveState: true, onSuccess: closeForm };
    if (editing.value) form.put(route('finance.categories.update', editing.value.id), options);
    else form.post(route('finance.categories.store'), options);
}

// --- Delete ---
const deleting = ref(null);
function confirmDelete() {
    const c = deleting.value;
    deleting.value = null;
    router.delete(route('finance.categories.destroy', c.id), { preserveScroll: true, preserveState: true });
}

function close() {
    formOpen.value = false;
    emit('close');
}

// Escape (or the backdrop) leaves an open form first, then the manager.
function onModalClose() {
    if (formOpen.value) closeForm();
    else close();
}
</script>

<template>
    <!-- Not closeable under the delete confirm: Escape there must close only the confirm. -->
    <Modal :show="show" max-width="2xl" :closeable="!deleting" @close="onModalClose">
        <div ref="root" class="p-5 sm:p-6">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('fincat.title') }}</h2>
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ t('fincat.subtitle') }}</p>
                </div>
                <IconButton icon="close" :label="t('close')" variant="ghost" @click="close" />
            </div>

            <!-- Add / edit -->
            <form v-if="formOpen" class="space-y-4 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200 dark:bg-slate-800/50 dark:ring-slate-700" @submit.prevent="save">
                <p class="text-sm font-bold text-slate-700 dark:text-slate-200">
                    {{ editing ? t('fincat.edit') : t('fincat.add') }} · {{ form.type === 'income' ? t('income') : t('expense') }}
                </p>
                <InputError :message="form.errors.name" />
                <div class="grid gap-3 sm:grid-cols-3">
                    <label v-for="l in languages" :key="l.key" class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                        {{ l.label }}
                        <input v-model="form[l.key]" :ref="(el) => { if (l.key === 'name_ar') firstName = el; }" :dir="l.dir" maxlength="120"
                            class="mt-1 w-full rounded-lg border-slate-300 bg-white py-1.5 text-sm text-slate-900 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
                        <InputError :message="form.errors[l.key]" class="mt-1" />
                    </label>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('fincat.names_hint') }}</p>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.color" type="color" class="h-8 w-10 cursor-pointer rounded border-0 bg-transparent p-0" />
                        {{ t('fincat.color') }}
                    </label>
                    <label v-if="editing" class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                        {{ t('fincat.active') }}
                    </label>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <SecondaryButton type="button" @click="closeForm">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="form.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>

            <!-- Lists -->
            <div v-else class="grid gap-6 sm:grid-cols-2">
                <section v-for="g in groups" :key="g.type" class="min-w-0">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h3 class="text-xs font-bold uppercase tracking-wide"
                            :class="g.type === 'income' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
                            {{ g.type === 'income' ? t('income') : t('expense') }}
                        </h3>
                        <IconButton v-if="can('finance', 'add')" icon="plus" :label="t('fincat.add')" variant="primary" plain size="sm" :data-opener="`add-${g.type}`" @click="openAdd(g.type)" />
                    </div>
                    <p v-if="!g.items.length" class="py-4 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('fincat.empty') }}</p>
                    <ul v-else class="max-h-80 space-y-1.5 overflow-y-auto">
                        <li v-for="c in g.items" :key="c.id" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                            <span class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: c.color || '#94a3b8' }" aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100" :class="{ 'opacity-60': c.is_active === false }">{{ label(c) }}</p>
                                <p class="flex flex-wrap gap-x-2 text-[11px] text-slate-500 dark:text-slate-400">
                                    <span v-if="used(c)">{{ t('fincat.used_by', { count: used(c) }) }}</span>
                                    <span v-if="c.is_system">{{ t('fincat.built_in') }}</span>
                                    <span v-if="c.is_active === false">{{ t('fincat.inactive') }}</span>
                                </p>
                            </div>
                            <IconButton v-if="can('finance', 'edit')" icon="pencil" :label="t('edit')" plain size="sm" :data-opener="`edit-${c.id}`" @click="openEdit(c)" />
                            <IconButton v-if="deletable(c)" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleting = c" />
                        </li>
                    </ul>
                </section>
            </div>
            <p v-if="!formOpen" class="mt-4 text-xs text-slate-500 dark:text-slate-400">{{ t('fincat.delete_rule') }}</p>
        </div>
    </Modal>

    <ConfirmModal
        :show="!!deleting"
        :title="t('fincat.delete_title')"
        :message="deleting ? t('fincat.delete_message', { name: label(deleting) }) : ''"
        @confirm="confirmDelete"
        @cancel="deleting = null"
    />
</template>
