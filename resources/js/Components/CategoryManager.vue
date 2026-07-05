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

const draft = ref({ income: { name: '', color: '#10b981' }, expense: { name: '', color: '#ef4444' } });

function byType(type) {
    return props.categories.filter((c) => c.type === type);
}
function add(type) {
    if (!draft.value[type].name.trim()) return;
    router.post(route('finance.categories.store'), { type, name: draft.value[type].name, color: draft.value[type].color }, {
        preserveScroll: true,
        onSuccess: () => { draft.value[type] = { name: '', color: type === 'income' ? '#10b981' : '#ef4444' }; },
    });
}
function save(cat) {
    router.put(route('finance.categories.update', cat.id), { type: cat.type, name: cat.name, color: cat.color, is_active: cat.is_active }, { preserveScroll: true });
}
function remove(cat) {
    router.delete(route('finance.categories.destroy', cat.id), { preserveScroll: true });
}
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="$emit('close')">
        <div class="p-6">
            <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('manage_categories') }}</h2>
            <div class="grid gap-6 sm:grid-cols-2">
                <div v-for="type in ['income', 'expense']" :key="type">
                    <h3 class="mb-2 text-sm font-semibold uppercase text-slate-500">{{ t(type) }}</h3>
                    <ul class="space-y-2">
                        <li v-for="cat in byType(type)" :key="cat.id" class="flex items-center gap-2">
                            <input type="color" v-model="cat.color" @change="save(cat)" class="h-7 w-7 rounded border-0 bg-transparent p-0" />
                            <input v-model="cat.name" @blur="save(cat)" class="flex-1 rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 py-1 text-sm" />
                            <button type="button" @click="remove(cat)" class="text-rose-500 hover:text-rose-700" :title="t('delete')">&times;</button>
                        </li>
                    </ul>
                    <div class="mt-2 flex items-center gap-2">
                        <input type="color" v-model="draft[type].color" class="h-7 w-7 rounded border-0 bg-transparent p-0" />
                        <input v-model="draft[type].name" @keyup.enter="add(type)" :placeholder="t('new_category')" class="flex-1 rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 py-1 text-sm" />
                        <button type="button" @click="add(type)" class="rounded-lg bg-primary-600 px-3 py-1 text-sm font-medium text-white hover:bg-primary-700">+</button>
                    </div>
                </div>
            </div>
            <p class="mt-4 text-xs text-slate-400">{{ t('category_in_use_hint') }}</p>
        </div>
    </Modal>
</template>
