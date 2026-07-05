<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { ref, computed } from 'vue';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    player: Object,
    totalDebt: Number,
});

const subscriptions = computed(() => props.player?.player_subscriptions ?? []);
const transactions = computed(() => subscriptions.value.map(s => s.transaction).filter(Boolean));

const showDeleteModal = ref(false);
const showPaymentModal = ref(false);

const paymentForm = useForm({
    amount: '',
    player_subscription_id: '',
    payment_method: 'cash',
    category: 'subscription',
    description: '',
});

function submitPayment() {
    paymentForm.post(route('players.transactions.store', props.player.id), {
        onSuccess: () => {
            showPaymentModal.value = false;
            paymentForm.reset();
        },
    });
}

function deletePlayer() {
    router.delete(route('players.destroy', props.player.id));
}

function paymentStatus(sub) {
    const remaining = parseFloat(sub.remaining_amount ?? 0);
    const paid = parseFloat(sub.amount_paid ?? 0);
    if (remaining <= 0) return 'paid';
    if (paid > 0) return 'partial';
    return 'unpaid';
}

const statusColor = (s) => {
    if (s === 'paid') return 'emerald';
    if (s === 'partial') return 'amber';
    if (s === 'exempt') return 'slate';
    return 'rose';
};

function formatDate(val) {
    if (!val) return '-';
    return new Date(val).toLocaleDateString();
}
</script>

<template>
    <Head :title="player.fullname || `${player.firstname} ${player.lastname}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('players.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ player.fullname || `${player.firstname} ${player.lastname}` }}</h1>
                <Badge v-if="player.archived" :label="t('archived')" color="slate" />
            </div>
        </template>

        <div class="space-y-6">
            <!-- Info cards row -->
            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Personal info -->
                <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 lg:col-span-2">
                    <div class="flex items-start justify-between">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('basic_info') }}</h2>
                        <Link :href="route('players.edit', player.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('edit') }}</Link>
                    </div>
                    <div class="mt-4 flex items-center gap-4">
                        <img v-if="player.picture_url" :src="player.picture_url" :alt="player.fullname || player.firstname" class="h-20 w-20 shrink-0 rounded-xl object-cover ring-1 ring-slate-200 dark:ring-slate-700" />
                        <div v-else class="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-2xl font-bold text-primary-600 ring-1 ring-primary-100 dark:bg-primary-500/10 dark:text-primary-300">
                            {{ (player.firstname || player.fullname || '?').charAt(0).toUpperCase() }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-lg font-bold text-slate-900 dark:text-slate-100">{{ player.fullname || `${player.firstname} ${player.lastname}` }}</p>
                            <p class="font-mono text-sm text-slate-500 dark:text-slate-400">{{ player.membership_id }}</p>
                        </div>
                    </div>
                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('membership_id') }}</dt><dd class="font-mono text-sm">{{ player.membership_id }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('date_of_birth') }}</dt><dd class="text-sm">{{ formatDate(player.birthdate) }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('gender') }}</dt><dd class="text-sm">{{ player.gender?.toLowerCase() === 'female' ? t('female') : t('male') }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('blood_group') }}</dt><dd class="text-sm">{{ player.health_blood_group_rhesus || '-' }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('phone') }}</dt><dd class="text-sm">{{ player.phones?.[0] || '-' }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('email') }}</dt><dd class="text-sm">{{ player.email || '-' }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('city') }}, {{ t('state') }}</dt><dd class="text-sm">{{ [player.city, player.state].filter(Boolean).join(', ') || '-' }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('category') }}</dt><dd class="text-sm">{{ player.category?.name || '-' }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('position') }}</dt><dd class="text-sm">{{ player.position?.abbreviation || '-' }} {{ player.position?.name || '' }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('status') }}</dt><dd class="text-sm">{{ player.is_student ? t('student') : t('worker') }}</dd></div>
                    </dl>
                </div>

                <!-- Debt summary -->
                <div class="space-y-4">
                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('outstanding_debt') }}</p>
                        <p class="mt-2 text-3xl font-bold" :class="totalDebt > 0 ? 'text-rose-700' : 'text-emerald-700'">
                            {{ formatMoney(totalDebt) }} <span class="text-base font-normal text-slate-400 dark:text-slate-500">DZD</span>
                        </p>
                        <button
                            @click="showPaymentModal = true"
                            class="mt-4 w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 transition-colors"
                        >
                            {{ t('add_payment') }}
                        </button>
                    </div>

                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <div class="flex justify-between">
                            <Link :href="route('players.edit', player.id)" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-center text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                {{ t('edit_player') }}
                            </Link>
                        </div>
                        <a :href="route('players.card', player.id)" target="_blank" class="mt-2 block w-full rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-center text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            🪪 {{ t('member_card') }}
                        </a>
                        <button
                            @click="showDeleteModal = true"
                            class="mt-2 w-full rounded-lg border border-rose-300 px-4 py-2 text-center text-sm font-medium text-rose-700 hover:bg-rose-50 transition-colors"
                        >
                            {{ t('delete') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Subscriptions -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="border-b border-slate-100 dark:border-slate-800 px-5 py-4">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('subscriptions') }}</h3>
                </div>
                <div v-if="!subscriptions.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_data') }}</div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('subscription') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('year') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('amount') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('amount_paid') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('remaining') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="sub in subscriptions" :key="sub.id">
                                <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ sub.subscription?.name || '-' }}</td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ sub.subscription?.year || '-' }}</td>
                                <td class="px-4 py-3 text-end text-sm">{{ formatMoney(sub.amount_owed) }}</td>
                                <td class="px-4 py-3 text-end text-sm text-emerald-700">{{ formatMoney(sub.amount_paid) }}</td>
                                <td class="px-4 py-3 text-end text-sm" :class="parseFloat(sub.remaining_amount) > 0 ? 'text-rose-700 font-semibold' : 'text-slate-500 dark:text-slate-400'">
                                    {{ formatMoney(sub.remaining_amount) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge :label="paymentStatus(sub)" :color="statusColor(paymentStatus(sub))" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Transaction history -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="border-b border-slate-100 dark:border-slate-800 px-5 py-4">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('transaction_history') }}</h3>
                </div>
                <div v-if="!transactions.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_transactions') }}</div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('date') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('category') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('payment_method') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="tx in transactions" :key="tx.id">
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ formatDate(tx.transaction_date) }}</td>
                                <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ tx.category }}</td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ tx.payment_method || '-' }}</td>
                                <td class="px-4 py-3 text-end text-sm font-semibold"
                                    :class="tx.transaction_type === 'income' ? 'text-emerald-700' : 'text-rose-700'">
                                    {{ tx.transaction_type === 'income' ? '+' : '-' }}{{ formatMoney(tx.amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Payment modal -->
        <Modal :show="showPaymentModal" @close="showPaymentModal = false" max-width="md">
            <form @submit.prevent="submitPayment" class="p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('add_payment') }}</h3>
                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel :value="t('subscription')" />
                        <select v-model="paymentForm.player_subscription_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="">{{ t('select_subscription') }}</option>
                            <option v-for="sub in subscriptions" :key="sub.id" :value="sub.id">
                                {{ sub.subscription?.name }} ({{ sub.subscription?.year }}) — {{ t('remaining') }}: {{ formatMoney(sub.remaining_amount) }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <InputLabel :value="t('amount')" />
                        <TextInput v-model="paymentForm.amount" type="number" step="0.01" min="0" class="mt-1 w-full" required />
                        <InputError :message="paymentForm.errors.amount" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('payment_method')" />
                        <select v-model="paymentForm.payment_method" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="cash">{{ t('cash') }}</option>
                            <option value="ccp">{{ t('ccp') }}</option>
                            <option value="baridimob">{{ t('baridimob') }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel :value="t('description')" />
                        <textarea v-model="paymentForm.description" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="showPaymentModal = false">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="paymentForm.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="showDeleteModal"
            :title="t('delete')"
            :message="t('delete_player_warning')"
            @confirm="deletePlayer"
            @cancel="showDeleteModal = false"
        />
    </AuthenticatedLayout>
</template>
