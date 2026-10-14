<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { useFormatMoney } from '@/Composables/useFormatMoney';

// accounts: [{ id, label, balance }] — active accounts the money can move between.
const props = defineProps({
    show: { type: Boolean, default: false },
    accounts: { type: Array, default: () => [] },
    fromId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const form = useForm({
    from_account_id: '',
    to_account_id: '',
    amount: '',
    transfer_date: new Date().toISOString().slice(0, 10),
    reference: '',
    notes: '',
});

watch(() => props.show, (open) => {
    if (!open) return;
    form.reset();
    form.clearErrors();
    form.from_account_id = props.fromId ?? '';
});

const byId = (id) => props.accounts.find((a) => String(a.id) === String(id));
const from = computed(() => byId(form.from_account_id));
const to = computed(() => byId(form.to_account_id));
const amount = computed(() => Number(form.amount) || 0);
const tooMuch = computed(() => !!from.value && amount.value > Number(from.value.balance));
const destinations = computed(() => props.accounts.filter((a) => String(a.id) !== String(form.from_account_id)));

function submit() {
    form.post(route('finance.transfers.store'), { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form class="space-y-4 p-6" @submit.prevent="submit">
            <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('caisses.move_money') }}</h2>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('caisses.from') }}</span>
                <select v-model="form.from_account_id" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="" disabled>—</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }} · {{ formatMoney(a.balance) }}</option>
                </select>
                <InputError :message="form.errors.from_account_id" class="mt-1" />
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('caisses.to') }}</span>
                <select v-model="form.to_account_id" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="" disabled>—</option>
                    <option v-for="a in destinations" :key="a.id" :value="a.id">{{ a.label }} · {{ formatMoney(a.balance) }}</option>
                </select>
                <InputError :message="form.errors.to_account_id" class="mt-1" />
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('amount') }}</span>
                    <input v-model="form.amount" type="number" min="0.01" step="0.01" required class="w-full rounded-lg border-slate-200 bg-white text-sm tabular-nums dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="tooMuch" class="mt-1 text-xs text-rose-600">{{ t('caisses.not_enough') }}</p>
                    <InputError :message="form.errors.amount" class="mt-1" />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('date') }}</span>
                    <input v-model="form.transfer_date" type="date" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <InputError :message="form.errors.transfer_date" class="mt-1" />
                </label>
            </div>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('notes') }}</span>
                <input v-model="form.notes" type="text" maxlength="1000" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>

            <div v-if="from && to && amount > 0" class="rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                <p class="mb-1 text-xs font-semibold text-slate-500">{{ t('caisses.after_move') }}</p>
                <p class="tabular-nums">{{ from.label }}: {{ formatMoney(from.balance) }} → <strong>{{ formatMoney(Number(from.balance) - amount) }}</strong></p>
                <p class="tabular-nums">{{ to.label }}: {{ formatMoney(to.balance) }} → <strong>{{ formatMoney(Number(to.balance) + amount) }}</strong></p>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="emit('close')">{{ t('cancel') }}</button>
                <button type="submit" :disabled="form.processing || tooMuch" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('caisses.move_money') }}</button>
            </div>
        </form>
    </Modal>
</template>
