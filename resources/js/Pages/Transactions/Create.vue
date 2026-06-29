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
    transaction: { type: Object, default: null },
    players: { type: Array, default: () => [] },
});

const isEdit = !!props.transaction;

const form = useForm({
    transaction_type: props.transaction?.transaction_type || 'income',
    category: props.transaction?.category || 'subscription',
    amount: props.transaction?.amount || '',
    transaction_date: props.transaction?.transaction_date
        ? String(props.transaction.transaction_date).slice(0, 10)
        : new Date().toISOString().slice(0, 10),
    description: props.transaction?.description || '',
    payment_method: props.transaction?.payment_method || 'cash',
    status: props.transaction?.status || 'Paid',
    related_entity_id: props.transaction?.related_entity_id || '',
    receipt: null,
});

function submit() {
    form.transform((data) => ({
        ...data,
        amount: data.amount,
        related_entity_id: data.related_entity_id || null,
        related_entity_type: data.related_entity_id ? 'Player' : null,
        ...(isEdit ? { _method: 'put' } : {}),
    })).post(
        isEdit ? route('transactions.update', props.transaction.id) : route('transactions.store'),
        { forceFormData: true },
    );
}
</script>

<template>
    <Head :title="isEdit ? t('edit') : t('add_transaction')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('transactions.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ isEdit ? t('edit') : t('add_transaction') }}</h1>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-2xl space-y-6">
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('type')" />
                            <select v-model="form.transaction_type" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" required>
                                <option value="income">{{ t('income') }}</option>
                                <option value="expense">{{ t('expense') }}</option>
                            </select>
                            <InputError :message="form.errors.transaction_type" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel :value="t('category')" />
                            <select v-model="form.category" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" required>
                                <option value="subscription">{{ t('subscription') }}</option>
                                <option value="donation">{{ t('donation') }}</option>
                                <option value="equipment">{{ t('equipment') }}</option>
                                <option value="salary">{{ t('job') }}</option>
                                <option value="debt_payment">{{ t('debt') }}</option>
                                <option value="other">{{ t('other') }}</option>
                            </select>
                            <InputError :message="form.errors.category" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('amount')" />
                            <TextInput v-model="form.amount" type="number" step="0.01" min="0" class="mt-1 w-full" required />
                            <InputError :message="form.errors.amount" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel :value="t('date')" />
                            <TextInput v-model="form.transaction_date" type="date" class="mt-1 w-full" required />
                            <InputError :message="form.errors.transaction_date" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('payment_method')" />
                            <select v-model="form.payment_method" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="cash">{{ t('cash') }}</option>
                                <option value="bank">{{ t('bank_transfer') }}</option>
                                <option value="ccp">CCP</option>
                                <option value="other">{{ t('other') }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel :value="t('status')" />
                            <select v-model="form.status" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="Paid">{{ t('paid') }}</option>
                                <option value="Partial">{{ t('partial') }}</option>
                                <option value="Unpaid">{{ t('unpaid') }}</option>
                                <option value="Exempt">{{ t('exempt') }}</option>
                            </select>
                            <InputError :message="form.errors.status" class="mt-1" />
                        </div>
                    </div>

                    <div v-if="players.length">
                        <InputLabel :value="t('player')" />
                        <select v-model="form.related_entity_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="">-</option>
                            <option v-for="p in players" :key="p.id" :value="p.id">{{ p.fullname || `${p.firstname} ${p.lastname}` }}</option>
                        </select>
                    </div>

                    <div>
                        <InputLabel :value="t('description')" />
                        <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                        <InputError :message="form.errors.description" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel :value="t('receipt')" />
                        <div v-if="transaction?.receipt_url" class="mb-2">
                            <a :href="transaction.receipt_url" target="_blank" class="text-sm font-medium text-primary-600 hover:underline">{{ t('view') }} ↗</a>
                        </div>
                        <input type="file" accept="image/*,application/pdf" @change="form.receipt = $event.target.files[0]"
                            class="text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100" />
                        <InputError :message="form.errors.receipt" class="mt-1" />
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <Link :href="route('transactions.index')">
                    <SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton>
                </Link>
                <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
