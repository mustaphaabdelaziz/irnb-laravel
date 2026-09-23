<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

/**
 * Take back one open rental or assignment, in full or in part. Shared by the
 * catalog page and the equipment-out page so both return the same way.
 * Renders nothing while `rental` is null.
 */
const props = defineProps({
    rental: { type: Object, default: null }, // { id, quantity, returned_quantity }
    title: { type: String, default: '' },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const CONDITIONS = ['New', 'Good', 'Fair', 'Poor', 'Damaged'];

const form = useForm({
    quantity: 1,
    condition: 'Good',
    return_date: new Date().toISOString().slice(0, 10),
    notes: '',
});

const outstanding = (r) => (r?.quantity ?? 1) - (r?.returned_quantity ?? 0);

// Default to bringing back everything still out, which is the common case.
watch(() => props.rental, (rental) => {
    if (!rental) return;
    form.reset();
    form.clearErrors();
    form.quantity = outstanding(rental);
}, { immediate: true });

function submit() {
    form.post(route('equipment.rentals.return', props.rental.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <div v-if="rental" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="emit('close')">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                {{ t('return') }}<span v-if="title"> — {{ title }}</span>
            </h3>
            <form @submit.prevent="submit" class="mt-4 space-y-3">
                <!-- Units can come back in instalments; the rental stays open until all are in. -->
                <div v-if="(rental.quantity ?? 1) > 1">
                    <InputLabel :value="t('equipment.quantity')" />
                    <TextInput v-model="form.quantity" type="number" min="1" :max="outstanding(rental)" class="mt-1 w-full" required />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ rental.returned_quantity ?? 0 }} / {{ rental.quantity }} {{ t('equipment.returned_of') }}
                    </p>
                    <InputError :message="form.errors.quantity" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('return_date')" />
                    <TextInput v-model="form.return_date" type="date" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('condition')" />
                    <div class="mt-2 grid grid-cols-5 gap-2">
                        <button v-for="c in CONDITIONS" :key="c" type="button" @click="form.condition = c"
                            :class="form.condition === c ? 'bg-primary-100 dark:bg-primary-500/25 border-primary-500 text-primary-800 dark:text-primary-100' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300'"
                            class="rounded-lg border px-2 py-2 text-xs font-medium text-center transition-colors">{{ t(c.toLowerCase()) }}</button>
                    </div>
                </div>
                <div>
                    <InputLabel :value="t('notes')" />
                    <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="emit('close')" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                    <PrimaryButton :disabled="form.processing">{{ t('return') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </div>
</template>
