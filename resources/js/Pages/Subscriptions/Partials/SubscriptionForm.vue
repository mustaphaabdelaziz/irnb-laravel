<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    categories: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    seasons: { type: Array, default: () => [] },
    currentSeason: { type: Number, required: true },
    subscription: { type: Object, default: null },
    submitLabel: { type: String, required: true },
});

const isEdit = !!props.subscription;
const sub = props.subscription ?? {};

// Per-category overrides keyed by category id. A blank input means "use the
// default price", so only real numbers are pre-filled.
const categoryPrices = {};
for (const cat of props.categories) {
    const pivot = sub.categories?.find((c) => c.id === cat.id)?.pivot;
    categoryPrices[cat.id] = {
        amount_student: pivot?.amount_student ?? '',
        amount_worker: pivot?.amount_worker ?? '',
    };
}

const form = useForm({
    kind: sub.kind || 'annual',
    name: sub.name || '',
    year: sub.year || props.currentSeason,
    amount_student: sub.amount_student ?? '',
    amount_worker: sub.amount_worker ?? '',
    is_mandatory: sub.is_mandatory ?? true,
    category_ids: sub.categories?.map((c) => c.id) || [],
    category_prices: categoryPrices,
    branch_ids: sub.branches?.map((b) => b.id) || [],
});

const isAnnual = computed(() => form.kind === 'annual');
const chosenCategories = computed(() => props.categories.filter((c) => form.category_ids.includes(c.id)));

function submit() {
    // Send overrides only for the categories actually chosen.
    const payload = (data) => ({
        ...data,
        category_prices: Object.fromEntries(data.category_ids.map((id) => [id, data.category_prices[id]])),
    });

    if (isEdit) {
        form.transform(payload).put(route('subscriptions.update', props.subscription.id));
    } else {
        form.transform(payload).post(route('subscriptions.store'));
    }
}

const kinds = [
    { value: 'annual', label: 'subscription_kind_annual', hint: 'subscription_kind_annual_hint' },
    { value: 'exceptional', label: 'subscription_kind_exceptional', hint: 'subscription_kind_exceptional_hint' },
];
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-2xl space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="space-y-5">
                <!-- Kind -->
                <fieldset>
                    <legend class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ t('subscription_kind') }}</legend>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <label
                            v-for="k in kinds" :key="k.value"
                            class="flex cursor-pointer gap-3 rounded-xl p-3 ring-1 transition-colors"
                            :class="form.kind === k.value
                                ? 'bg-primary-50 ring-primary-400 dark:bg-primary-900/20 dark:ring-primary-600'
                                : 'ring-slate-200 hover:bg-slate-50 dark:ring-slate-700 dark:hover:bg-slate-800'"
                        >
                            <input v-model="form.kind" type="radio" :value="k.value" class="mt-0.5 border-slate-300 text-primary-600 focus:ring-primary-500" />
                            <span>
                                <span class="block text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t(k.label) }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ t(k.hint) }}</span>
                            </span>
                        </label>
                    </div>
                    <InputError :message="form.errors.kind" class="mt-1" />
                </fieldset>

                <div>
                    <InputLabel :value="t('name')" />
                    <TextInput v-model="form.name" class="mt-1 w-full" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>

                <div v-if="isAnnual">
                    <InputLabel :value="t('season')" />
                    <select v-model.number="form.year" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900" required>
                        <option v-for="s in seasons" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                    <InputError :message="form.errors.year" class="mt-1" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel :value="t('student_pricing')" />
                        <TextInput v-model="form.amount_student" type="number" step="0.01" min="0" class="mt-1 w-full" required />
                        <InputError :message="form.errors.amount_student" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('worker_pricing')" />
                        <TextInput v-model="form.amount_worker" type="number" step="0.01" min="0" class="mt-1 w-full" required />
                        <InputError :message="form.errors.amount_worker" class="mt-1" />
                    </div>
                </div>

                <label v-if="isAnnual" class="flex items-center gap-2">
                    <input type="checkbox" v-model="form.is_mandatory" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                    <span class="text-sm text-slate-700 dark:text-slate-200">{{ t('mandatory') }}</span>
                </label>
                <p v-else class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('subscription_exceptional_not_debt') }}</p>

                <!-- Categories + their own prices -->
                <div>
                    <InputLabel :value="t('categories')" />
                    <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">{{ t('select_category') }} ({{ t('all') }})</p>
                    <div class="flex flex-wrap gap-3">
                        <label v-for="cat in categories" :key="cat.id" class="flex items-center gap-2">
                            <input type="checkbox" :value="cat.id" v-model="form.category_ids"
                                class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                            <span class="text-sm">{{ cat.localized_name || cat.name }}</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.category_ids" class="mt-1" />

                    <div v-if="chosenCategories.length" class="mt-4 overflow-hidden rounded-xl ring-1 ring-slate-200 dark:ring-slate-800">
                        <p class="bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-950 dark:text-slate-400">{{ t('category_price_hint') }}</p>
                        <div class="divide-y divide-slate-100 dark:divide-slate-800">
                            <div v-for="cat in chosenCategories" :key="cat.id" class="grid items-center gap-2 px-3 py-2 sm:grid-cols-3">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ cat.localized_name || cat.name }}</span>
                                <label class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ t('student_pricing') }}
                                    <TextInput v-model="form.category_prices[cat.id].amount_student" type="number" step="0.01" min="0"
                                        class="mt-0.5 w-full py-1.5 text-sm" :placeholder="String(form.amount_student || '')" />
                                    <InputError :message="form.errors[`category_prices.${cat.id}.amount_student`]" class="mt-1" />
                                </label>
                                <label class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ t('worker_pricing') }}
                                    <TextInput v-model="form.category_prices[cat.id].amount_worker" type="number" step="0.01" min="0"
                                        class="mt-0.5 w-full py-1.5 text-sm" :placeholder="String(form.amount_worker || '')" />
                                    <InputError :message="form.errors[`category_prices.${cat.id}.amount_worker`]" class="mt-1" />
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="branches.length">
                    <InputLabel :value="t('branches')" />
                    <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">{{ t('subscription_branch_hint') }}</p>
                    <div class="flex flex-wrap gap-3">
                        <label v-for="b in branches" :key="b.id" class="flex items-center gap-2">
                            <input type="checkbox" :value="b.id" v-model="form.branch_ids"
                                class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                            <span class="text-sm">{{ b.localized_name || b.name }}</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.branch_ids" class="mt-1" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="route('subscriptions.index')">
                <SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton>
            </Link>
            <PrimaryButton :disabled="form.processing">{{ submitLabel }}</PrimaryButton>
        </div>
    </form>
</template>
