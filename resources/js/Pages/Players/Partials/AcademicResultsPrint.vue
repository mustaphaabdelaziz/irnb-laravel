<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

/**
 * "Print academic results": pick a category (or all) and a school year, then
 * open the PDF — one sheet per category, students ranked by year average.
 */
const props = defineProps({
    categories: { type: Array, default: () => [] },
    // The list's current category filter, preselected when the window opens.
    categoryId: { type: [Number, String], default: '' },
    currentSchoolYear: { type: Number, default: null },
});

const { t } = useI18n();

const show = ref(false);
const categoryId = ref('');
const schoolYear = ref(null);

const top = computed(() => props.currentSchoolYear ?? new Date().getFullYear());
const yearOptions = computed(() => Array.from({ length: 12 }, (_, i) => top.value - i));

function open() {
    categoryId.value = props.categoryId || '';
    schoolYear.value = top.value;
    show.value = true;
}

const href = computed(() => route('players.academic-results', {
    school_year: schoolYear.value,
    ...(categoryId.value ? { category_id: categoryId.value } : {}),
}));
</script>

<template>
    <button type="button" @click="open"
        class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800">
        <Icon name="print" /> {{ t('print_academic_results') }}
    </button>

    <Modal :show="show" @close="show = false" max-width="md">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('print_academic_results') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('print_academic_results_hint') }}</p>

            <div class="mt-4 space-y-4">
                <div>
                    <InputLabel :value="t('category')" />
                    <select v-model="categoryId" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">{{ t('all_categories') }}</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.localized_name || cat.name }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('academic_year')" />
                    <select v-model.number="schoolYear" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}/{{ y + 1 }}</option>
                    </select>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="show = false">{{ t('cancel') }}</SecondaryButton>
                <a :href="href" target="_blank" @click="show = false"
                    class="inline-flex items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    <Icon name="print" /> {{ t('print') }}
                </a>
            </div>
        </div>
    </Modal>
</template>
