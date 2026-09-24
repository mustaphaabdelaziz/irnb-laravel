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
import RentalTypeBadge from '@/Components/RentalTypeBadge.vue';
import Icon from '@/Components/Icon.vue';
import PlayerFieldRow from '@/Components/PlayerFieldRow.vue';
import AcademicSection from '@/Pages/Players/Partials/AcademicSection.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
import { ref, computed, watch } from 'vue';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { formatFileNumber, fileDrawer } from '@/lib/fileNumber';

const { t } = useI18n();
const { statusLabel } = useStatusLabel();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    player: Object,
    totalDebt: Number,
    transactions: { type: Array, default: () => [] },
    availableSubscriptions: { type: Array, default: () => [] },
    financeAccounts: { type: Array, default: () => [] },
    defaultFinanceAccountId: { type: [Number, String], default: '' },
    fileDrawerSize: { type: Number, default: 100 },
    certificateThresholds: { type: Object, default: () => ({}) },
    currentSchoolYear: { type: Number, default: null },
});

const subscriptions = computed(() => props.player?.player_subscriptions ?? []);
const transactions = computed(() => props.transactions ?? []);
const { accountLabel } = useFinanceAccountLabel();

// Only translate a known value — an unexpected gender string (legacy data,
// a future value the interface hasn't been taught yet) is shown as-is
// rather than silently rendered blank by an unmatched t() key.
const genderLabel = computed(() => {
    const g = (props.player?.gender || '').toLowerCase();

    return ['male', 'female'].includes(g) ? t(g) : props.player?.gender;
});

// Equipment the player holds or held. Assignments (work kit) and rentals are
// listed apart so a loan is never mistaken for kit given to work with.
const equipmentRentals = computed(() => [...(props.player?.equipment_rentals ?? [])]
    .sort((a, b) => String(b.checkout_date).localeCompare(String(a.checkout_date))));
const equipmentGroups = computed(() => [
    { key: 'assigned', label: t('equipment.assignments_tab'), rows: equipmentRentals.value.filter((r) => !r.return_date && r.type === 'assignment') },
    { key: 'rented', label: t('equipment.rentals_tab'), rows: equipmentRentals.value.filter((r) => !r.return_date && r.type !== 'assignment') },
    { key: 'returned', label: t('equipment.past_items'), rows: equipmentRentals.value.filter((r) => r.return_date) },
].filter((group) => group.rows.length));

// Manual/previous debts = obligation lines with no subscription plan attached.
const manualDebts = computed(() =>
    subscriptions.value.filter(s => !s.subscription_id && !s.subscription)
);

// Selectable in the payment form: the whole catalog (mandatory + optional, assigned
// or not) minus fully-paid ones. Exempt subs stay so they can be un-exempted.
const payableSubscriptions = computed(() =>
    props.availableSubscriptions.filter(s => parseFloat(s.remaining_amount ?? 0) > 0 || s.is_exempt)
);

// One picker lists both catalog subscriptions and still-owing manual debts. Each
// option carries a stable key so the form knows which id to send on submit.
const payableOptions = computed(() => [
    ...payableSubscriptions.value.map(s => ({
        key: `sub:${s.subscription_id}`,
        kind: 'sub',
        subscription_id: s.subscription_id,
        default_finance_account_id: s.default_finance_account_id,
        is_exempt: !!s.is_exempt,
        text: `${s.name} (${s.year})${s.is_mandatory ? '' : ' — ' + t('optional')} — ${t('remaining')}: ${formatMoney(s.remaining_amount)}`,
    })),
    ...manualDebts.value
        .filter(d => parseFloat(d.remaining_amount ?? 0) > 0)
        .map(d => ({
            key: `debt:${d.id}`,
            kind: 'debt',
            player_subscription_id: d.id,
            is_exempt: !!d.is_exempt,
            text: `${d.label || t('previous_debt')} (${d.year}) — ${t('remaining')}: ${formatMoney(d.remaining_amount)}`,
        })),
]);

const showDeleteModal = ref(false);
const showPaymentModal = ref(false);

const paymentForm = useForm({
    amount: '',
    subscription_id: '',
    player_subscription_id: '',
    payment_method: 'cash',
    finance_account_id: props.defaultFinanceAccountId || '',
    category: 'subscription',
    description: '',
    is_exempt: false,
});

const selectedPayableKey = ref('');
const selectedPayable = computed(() => payableOptions.value.find(o => o.key === selectedPayableKey.value) || null);

// Hide the amount while Exempt is ticked — exemption records no payment. Exempt only
// applies to catalog subscriptions here; manual-debt exemption is set on the debt itself.
const showAmountField = computed(() =>
    !(paymentForm.category === 'subscription' && selectedPayable.value?.kind === 'sub' && paymentForm.is_exempt)
);

watch(() => paymentForm.category, (category) => {
    if (category !== 'subscription') {
        selectedPayableKey.value = '';
        paymentForm.subscription_id = '';
        paymentForm.player_subscription_id = '';
        paymentForm.is_exempt = false;
        paymentForm.finance_account_id = props.defaultFinanceAccountId || '';
    }
});

// When an obligation is picked, route its id and automatically select the register
// assigned to the subscription's single branch/category pair.
watch(selectedPayableKey, () => {
    const opt = selectedPayable.value;
    paymentForm.subscription_id = opt?.kind === 'sub' ? opt.subscription_id : '';
    paymentForm.player_subscription_id = opt?.kind === 'debt' ? opt.player_subscription_id : '';
    paymentForm.is_exempt = opt ? !!opt.is_exempt : false;
    paymentForm.finance_account_id = opt?.kind === 'sub' && opt.default_finance_account_id
        ? opt.default_finance_account_id
        : (props.defaultFinanceAccountId || '');
});

function submitPayment() {
    paymentForm.post(route('players.transactions.store', props.player.id), {
        onSuccess: () => {
            showPaymentModal.value = false;
            paymentForm.reset();
            selectedPayableKey.value = '';
        },
    });
}

// --- Add a manual/previous debt (obligation with no subscription plan) ---
const showAddDebtModal = ref(false);
const debtForm = useForm({ label: '', amount_owed: '', year: new Date().getFullYear(), due_date: '', is_exempt: false });

function submitAddDebt() {
    debtForm.post(route('players.subscriptions.store', props.player.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddDebtModal.value = false;
            debtForm.reset();
        },
    });
}

function deletePlayer() {
    router.delete(route('players.destroy', props.player.id));
}

function paymentStatus(sub) {
    if (sub.is_exempt) return 'exempt';
    const remaining = parseFloat(sub.remaining_amount ?? 0);
    const paid = parseFloat(sub.amount_paid ?? 0);
    if (remaining <= 0) return 'paid';
    if (paid > 0) return 'partial';
    return 'unpaid';
}

// Edit a payment = archive the original + record a new one (handled server-side).
const showEditModal = ref(false);
const editingId = ref(null);
const editForm = useForm({ amount: '', payment_method: 'cash', finance_account_id: '', description: '' });

function openEdit(tx) {
    editingId.value = tx.id;
    editForm.amount = tx.amount;
    editForm.payment_method = tx.payment_method || 'cash';
    editForm.finance_account_id = tx.finance_account_id || props.defaultFinanceAccountId || '';
    editForm.description = tx.description || '';
    editForm.clearErrors();
    showEditModal.value = true;
}

function submitEdit() {
    editForm.put(route('players.transactions.update', [props.player.id, editingId.value]), {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            editForm.reset();
            editingId.value = null;
        },
    });
}

const showRemoveModal = ref(false);
const removingId = ref(null);

function askRemove(tx) {
    removingId.value = tx.id;
    showRemoveModal.value = true;
}

function confirmRemove() {
    router.delete(route('players.transactions.destroy', [props.player.id, removingId.value]), {
        preserveScroll: true,
        onSuccess: () => {
            showRemoveModal.value = false;
            removingId.value = null;
        },
    });
}

const statusColor = (s) => {
    if (s === 'paid') return 'emerald';
    if (s === 'partial') return 'amber';
    if (s === 'exempt') return 'slate';
    return 'rose';
};

// --- Edit / remove a subscription obligation line ---
const showSubEditModal = ref(false);
const editingSubId = ref(null);
const editingSubIsManual = ref(false);
const subForm = useForm({ label: '', year: '', amount_owed: '', is_exempt: false, due_date: '', discount_type: '', discount_value: '' });

function openSubEdit(sub) {
    editingSubId.value = sub.id;
    // Label and year are only editable on manual debts (no attached subscription plan).
    editingSubIsManual.value = !sub.subscription_id && !sub.subscription;
    subForm.label = sub.label || '';
    subForm.year = sub.subscription?.year || sub.year || '';
    subForm.amount_owed = sub.amount_owed;
    subForm.is_exempt = !!sub.is_exempt;
    subForm.due_date = sub.due_date ? String(sub.due_date).slice(0, 10) : '';
    subForm.discount_type = sub.discount_type || '';
    subForm.discount_value = sub.discount_value ?? '';
    subForm.clearErrors();
    showSubEditModal.value = true;
}

// Mirror the server's clamp so the previewed net matches what will be saved.
const subEditDiscount = computed(() => {
    const owed = parseFloat(subForm.amount_owed) || 0;
    const value = parseFloat(subForm.discount_value) || 0;
    if (!subForm.discount_type || value <= 0) return 0;
    const raw = subForm.discount_type === 'percent' ? (owed * value) / 100 : value;
    return Math.round(Math.min(Math.max(raw, 0), owed) * 100) / 100;
});

const subEditNetOwed = computed(() => Math.max(0, (parseFloat(subForm.amount_owed) || 0) - subEditDiscount.value));

// A discount label for the obligations table, e.g. "-20% (400)" or "-400".
function discountLabel(sub) {
    const amount = parseFloat(sub.discount_amount ?? 0);
    if (!sub.discount_type || amount <= 0) return null;
    return sub.discount_type === 'percent'
        ? `-${parseFloat(sub.discount_value)}% (${formatMoney(amount)})`
        : `-${formatMoney(amount)}`;
}

function submitSubEdit() {
    subForm.put(route('players.subscriptions.update', [props.player.id, editingSubId.value]), {
        preserveScroll: true,
        onSuccess: () => {
            showSubEditModal.value = false;
            subForm.reset();
            editingSubId.value = null;
        },
    });
}

const showSubRemoveModal = ref(false);
const removingSubId = ref(null);

function askRemoveSub(sub) {
    removingSubId.value = sub.id;
    showSubRemoveModal.value = true;
}

function confirmRemoveSub() {
    router.delete(route('players.subscriptions.destroy', [props.player.id, removingSubId.value]), {
        preserveScroll: true,
        onSuccess: () => {
            showSubRemoveModal.value = false;
            removingSubId.value = null;
        },
    });
}

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
                    <dl class="mt-5 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        <PlayerFieldRow icon="idcard" :label="t('membership_id')" :value="player.membership_id" mono />
                        <PlayerFieldRow icon="folder" :label="t('file_number')" mono>
                            {{ formatFileNumber(player.file_number) }}
                            <span v-if="player.file_number" class="text-xs font-sans text-slate-500 dark:text-slate-400">
                                · {{ t('drawer') }} {{ fileDrawer(player.file_number, fileDrawerSize) }}
                            </span>
                        </PlayerFieldRow>
                        <PlayerFieldRow icon="calendar" :label="t('birthdate')">
                            {{ formatDate(player.birthdate) }}
                            <span v-if="player.age != null" class="ms-1 text-slate-500 dark:text-slate-400">({{ t('age_years', { age: player.age }) }})</span>
                        </PlayerFieldRow>
                        <PlayerFieldRow icon="user" :label="t('gender')" :value="genderLabel" />
                        <PlayerFieldRow icon="positions" :label="t('main_position')">
                            {{ player.position?.abbreviation || '—' }}
                            <span v-if="player.position?.name" class="text-slate-500 dark:text-slate-400">{{ player.position.name }}</span>
                            <span v-if="player.other_positions?.length" class="text-slate-500 dark:text-slate-400">
                                ·
                                <template v-for="(p, index) in player.other_positions" :key="p.id"><span :title="p.name">{{ p.abbreviation }}</span>{{ index < player.other_positions.length - 1 ? ', ' : '' }}</template>
                            </span>
                        </PlayerFieldRow>
                        <PlayerFieldRow icon="categories" :label="t('category')" :value="player.category?.localized_name || player.category?.name" />
                        <PlayerFieldRow icon="flag" :label="t('membership_status')" :value="player.status?.localized_name || player.status?.name" />
                        <PlayerFieldRow icon="location" :label="t('state')" :value="[player.city, player.wilaya?.localized_name || player.wilaya?.name || player.state].filter(Boolean).join(', ')" />
                        <PlayerFieldRow icon="phone" :label="t('phone')" :value="player.phones?.[0]" />
                        <PlayerFieldRow icon="mail" :label="t('email')" :value="player.email" />
                        <PlayerFieldRow icon="jobs" :label="t('job')" :value="player.is_student ? t('student') : (player.member_job?.localized_name || player.member_job?.name)" />
                        <PlayerFieldRow icon="drop" :label="t('blood_group')" :value="player.health_blood_group_rhesus" />
                        <PlayerFieldRow icon="calendar" :label="t('join_year')" :value="player.join_year" />
                        <PlayerFieldRow icon="home" :label="t('branches')">
                            <span v-if="player.branches?.length" class="flex flex-wrap gap-1">
                                <span v-for="b in player.branches" :key="b.id" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">{{ b.localized_name || b.name }}</span>
                            </span>
                            <span v-else>—</span>
                        </PlayerFieldRow>
                    </dl>

                    <div v-if="player.emergency_contacts?.length || player.health_medical_conditions" class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <template v-if="player.emergency_contacts?.length">
                            <p class="mb-2 text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('emergency_contacts') }}</p>
                            <ul class="space-y-1">
                                <li v-for="contact in player.emergency_contacts" :key="contact.id" class="flex flex-wrap items-center gap-2 text-sm">
                                    <Icon name="phone" class="text-slate-400" />
                                    <span class="font-medium text-slate-800 dark:text-slate-200">{{ contact.name }}</span>
                                    <span v-if="contact.relationship" class="text-xs text-slate-500">{{ contact.relationship }}</span>
                                    <span class="font-mono text-xs text-slate-600 dark:text-slate-300">{{ (contact.phones || [])[0] }}</span>
                                </li>
                            </ul>
                        </template>
                        <p v-if="player.health_medical_conditions" class="mt-3 text-sm">
                            <span class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('medical_notes') }}:</span>
                            {{ player.health_medical_conditions }}
                        </p>
                    </div>
                </div>

                <!-- Debt summary -->
                <div class="space-y-4">
                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('outstanding_debt') }}</p>
                        <p class="mt-2 text-3xl font-bold" :class="totalDebt > 0 ? 'text-rose-700 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400'">
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
                            <Icon name="idcard" /> {{ t('member_card') }}
                        </a>
                        <a :href="route('players.label', player.id)" target="_blank" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                            <Icon name="print" /> {{ t('print_folder_label') }}
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

            <!-- Studies: only students carry an education section -->
            <AcademicSection v-if="player.is_student" :player="player" :certificate-thresholds="certificateThresholds" :current-school-year="currentSchoolYear" />

            <!-- Subscriptions -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 px-5 py-4">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('subscriptions') }}</h3>
                    <button type="button" @click="showAddDebtModal = true" class="rounded-md px-3 py-1.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700 dark:hover:bg-primary-900/30">
                        {{ t('add_previous_debt') }}
                    </button>
                </div>
                <div v-if="!subscriptions.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_data') }}</div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('subscription') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('year') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('amount') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('discount') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('amount_paid') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('remaining') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="sub in subscriptions" :key="sub.id">
                                <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">
                                    {{ sub.subscription?.name || sub.label || '-' }}
                                    <span v-if="sub.is_legacy" class="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('previous_debt') }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ sub.subscription?.year || sub.year || '-' }}</td>
                                <td class="px-4 py-3 text-end text-sm">{{ formatMoney(sub.amount_owed) }}</td>
                                <td class="px-4 py-3 text-end text-sm">
                                    <span v-if="discountLabel(sub)" class="text-amber-700 dark:text-amber-400">{{ discountLabel(sub) }}</span>
                                    <span v-else class="text-slate-400 dark:text-slate-500">—</span>
                                </td>
                                <td class="px-4 py-3 text-end text-sm text-emerald-700">{{ formatMoney(sub.amount_paid) }}</td>
                                <td class="px-4 py-3 text-end text-sm" :class="parseFloat(sub.remaining_amount) > 0 ? 'text-rose-700 font-semibold' : 'text-slate-500 dark:text-slate-400'">
                                    {{ formatMoney(sub.remaining_amount) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge :label="t(paymentStatus(sub))" :color="statusColor(paymentStatus(sub))" />
                                </td>
                                <td class="px-4 py-3 text-end whitespace-nowrap">
                                    <button type="button" @click="openSubEdit(sub)" class="rounded-md px-2 py-1 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700 dark:hover:bg-primary-900/30">
                                        {{ t('edit') }}
                                    </button>
                                    <button type="button" @click="askRemoveSub(sub)" class="ms-2 rounded-md px-2 py-1 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-300 hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-800 dark:hover:bg-rose-900/30">
                                        {{ t('remove') }}
                                    </button>
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
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('title') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('payment_method') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('cash_register') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('amount') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="tx in transactions" :key="tx.id">
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ formatDate(tx.transaction_date) }}</td>
                                <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ tx.display_title }}</td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ statusLabel('payment_method', tx.payment_method) }}</td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ accountLabel(tx.finance_account) }}</td>
                                <td class="px-4 py-3 text-end text-sm font-semibold"
                                    :class="tx.transaction_type === 'income' ? 'text-emerald-700' : 'text-rose-700'">
                                    {{ tx.transaction_type === 'income' ? '+' : '-' }}{{ formatMoney(tx.amount) }}
                                </td>
                                <td class="px-4 py-3 text-end whitespace-nowrap">
                                    <button type="button" @click="openEdit(tx)" class="rounded-md px-2 py-1 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700 dark:hover:bg-primary-900/30">
                                        {{ t('edit') }}
                                    </button>
                                    <button type="button" @click="askRemove(tx)" class="ms-2 rounded-md px-2 py-1 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-300 hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-800 dark:hover:bg-rose-900/30">
                                        {{ t('remove') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Equipment: work assignments and rentals, current then returned -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="border-b border-slate-100 dark:border-slate-800 px-5 py-4">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('equipment') }}</h3>
                </div>
                <div v-if="!equipmentRentals.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_data') }}</div>
                <div v-else class="divide-y divide-slate-100 dark:divide-slate-800">
                    <section v-for="group in equipmentGroups" :key="group.key" class="px-5 py-4">
                        <p class="mb-2 text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ group.label }} ({{ group.rows.length }})</p>
                        <ul class="space-y-2">
                            <li v-for="r in group.rows" :key="r.id" class="flex flex-wrap items-center gap-2 text-sm">
                                <RentalTypeBadge :type="r.type" />
                                <Link v-if="r.equipment_item?.catalog" :href="route('equipment.catalogs.show', r.equipment_item.catalog.id)" class="font-medium text-slate-800 hover:text-primary-600 dark:text-slate-200">{{ r.equipment_item.catalog.name }}</Link>
                                <span v-if="r.equipment_item?.unique_identifier" class="font-mono text-xs text-slate-400">{{ r.equipment_item.unique_identifier }}</span>
                                <span v-if="(r.quantity ?? 1) > 1" class="text-xs text-slate-500">× {{ r.return_date ? r.quantity : r.quantity - (r.returned_quantity ?? 0) }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ t('equipment.out_since') }} {{ formatDate(r.checkout_date) }}</span>
                                <span v-if="r.return_date" class="text-xs text-slate-500 dark:text-slate-400">· {{ t('equipment.returned_on') }} {{ formatDate(r.return_date) }}</span>
                                <Badge v-else-if="r.is_overdue" :label="t('overdue')" color="rose" />
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>

        <!-- Payment modal -->
        <Modal :show="showPaymentModal" @close="showPaymentModal = false" max-width="md">
            <form @submit.prevent="submitPayment" class="p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('add_payment') }}</h3>
                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel :value="t('payment_category')" />
                        <select v-model="paymentForm.category" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="subscription">{{ t('subscription') }}</option>
                            <option value="donation">{{ t('donation') }}</option>
                            <option value="debt_payment">{{ t('debt_payment') }}</option>
                        </select>
                    </div>
                    <div v-if="paymentForm.category === 'subscription'">
                        <InputLabel :value="t('subscription')" />
                        <select v-model="selectedPayableKey" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="">{{ t('select_subscription') }}</option>
                            <option v-for="opt in payableOptions" :key="opt.key" :value="opt.key">{{ opt.text }}</option>
                        </select>
                        <InputError :message="paymentForm.errors.subscription_id" class="mt-1" />
                    </div>
                    <label v-if="paymentForm.category === 'subscription' && selectedPayable?.kind === 'sub'" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input type="checkbox" v-model="paymentForm.is_exempt" class="rounded border-slate-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                        {{ t('exempt') }} — {{ t('exempt_hint') }}
                    </label>
                    <div v-if="showAmountField">
                        <InputLabel :value="t('amount')" />
                        <TextInput v-model="paymentForm.amount" type="number" step="0.01" min="0" class="mt-1 w-full" :required="showAmountField" />
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
                        <InputLabel :value="t('destination_register')" />
                        <select v-model="paymentForm.finance_account_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" :required="showAmountField">
                            <option value="" disabled>{{ t('select_cash_register') }}</option>
                            <option v-for="account in financeAccounts" :key="account.id" :value="account.id">
                                {{ accountLabel(account) }}
                            </option>
                        </select>
                        <InputError :message="paymentForm.errors.finance_account_id" class="mt-1" />
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

        <!-- Edit payment modal -->
        <Modal :show="showEditModal" @close="showEditModal = false" max-width="md">
            <form @submit.prevent="submitEdit" class="p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('edit_payment') }}</h3>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('edit_payment_hint') }}</p>
                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel :value="t('amount')" />
                        <TextInput v-model="editForm.amount" type="number" step="0.01" min="0" class="mt-1 w-full" required />
                        <InputError :message="editForm.errors.amount" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('payment_method')" />
                        <select v-model="editForm.payment_method" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="cash">{{ t('cash') }}</option>
                            <option value="ccp">{{ t('ccp') }}</option>
                            <option value="baridimob">{{ t('baridimob') }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel :value="t('destination_register')" />
                        <select v-model="editForm.finance_account_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" required>
                            <option value="" disabled>{{ t('select_cash_register') }}</option>
                            <option v-for="account in financeAccounts" :key="account.id" :value="account.id">
                                {{ accountLabel(account) }}
                            </option>
                        </select>
                        <InputError :message="editForm.errors.finance_account_id" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('description')" />
                        <textarea v-model="editForm.description" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="showEditModal = false">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="editForm.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="showRemoveModal"
            :title="t('remove')"
            :message="t('remove_payment_warning')"
            @confirm="confirmRemove"
            @cancel="showRemoveModal = false"
        />

        <!-- Edit subscription obligation modal -->
        <Modal :show="showSubEditModal" @close="showSubEditModal = false" max-width="md">
            <form @submit.prevent="submitSubEdit" class="p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ editingSubIsManual ? t('edit_previous_debt') : t('edit_subscription') }}</h3>
                <div class="mt-4 space-y-4">
                    <div v-if="editingSubIsManual">
                        <InputLabel :value="t('label')" />
                        <TextInput v-model="subForm.label" type="text" class="mt-1 w-full" />
                        <InputError :message="subForm.errors.label" class="mt-1" />
                    </div>
                    <div v-if="editingSubIsManual">
                        <InputLabel :value="t('year')" />
                        <TextInput v-model="subForm.year" type="number" min="1900" max="2999" class="mt-1 w-full" />
                        <InputError :message="subForm.errors.year" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('amount_owed')" />
                        <TextInput v-model="subForm.amount_owed" type="number" step="0.01" min="0" class="mt-1 w-full" required />
                        <InputError :message="subForm.errors.amount_owed" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('due_date')" />
                        <TextInput v-model="subForm.due_date" type="date" class="mt-1 w-full" />
                        <InputError :message="subForm.errors.due_date" class="mt-1" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('discount')" />
                            <select v-model="subForm.discount_type" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">{{ t('no_discount') }}</option>
                                <option value="percent">{{ t('discount_percent') }}</option>
                                <option value="amount">{{ t('discount_amount') }}</option>
                            </select>
                            <InputError :message="subForm.errors.discount_type" class="mt-1" />
                        </div>
                        <div v-if="subForm.discount_type">
                            <InputLabel :value="subForm.discount_type === 'percent' ? '%' : t('amount')" />
                            <TextInput v-model="subForm.discount_value" type="number" step="0.01" min="0"
                                :max="subForm.discount_type === 'percent' ? 100 : undefined" class="mt-1 w-full" />
                            <InputError :message="subForm.errors.discount_value" class="mt-1" />
                        </div>
                    </div>
                    <p v-if="subEditDiscount > 0" class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        {{ t('discount') }}: −{{ formatMoney(subEditDiscount) }} · <span class="font-semibold">{{ t('net_owed') }}: {{ formatMoney(subEditNetOwed) }}</span>
                    </p>
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input type="checkbox" v-model="subForm.is_exempt" class="rounded border-slate-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                        {{ t('exempt') }}
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="showSubEditModal = false">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="subForm.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="showSubRemoveModal"
            :title="t('remove')"
            :message="t('remove_subscription_warning')"
            @confirm="confirmRemoveSub"
            @cancel="showSubRemoveModal = false"
        />

        <!-- Add previous / manual debt modal -->
        <Modal :show="showAddDebtModal" @close="showAddDebtModal = false" max-width="md">
            <form @submit.prevent="submitAddDebt" class="p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('add_previous_debt') }}</h3>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('previous_debt_hint') }}</p>
                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel :value="t('label')" />
                        <TextInput v-model="debtForm.label" type="text" class="mt-1 w-full" required />
                        <InputError :message="debtForm.errors.label" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('amount_owed')" />
                        <TextInput v-model="debtForm.amount_owed" type="number" step="0.01" min="0.01" class="mt-1 w-full" required />
                        <InputError :message="debtForm.errors.amount_owed" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('year')" />
                        <TextInput v-model="debtForm.year" type="number" min="1900" max="2999" class="mt-1 w-full" required />
                        <InputError :message="debtForm.errors.year" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('due_date')" />
                        <TextInput v-model="debtForm.due_date" type="date" class="mt-1 w-full" />
                        <InputError :message="debtForm.errors.due_date" class="mt-1" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input type="checkbox" v-model="debtForm.is_exempt" class="rounded border-slate-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                        {{ t('exempt') }}
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="showAddDebtModal = false">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="debtForm.processing">{{ t('save') }}</PrimaryButton>
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
