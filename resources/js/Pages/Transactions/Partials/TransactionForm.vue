<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
import { useFormatMoney } from '@/Composables/useFormatMoney';

const { t } = useI18n();
const props = defineProps({
    transaction: { type: Object, default: null },
    financeCategories: { type: Array, default: () => [] },
    financeAccounts: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    clubCcp: { type: Object, default: null },
});

const isEdit = !!props.transaction;

const form = useForm({
    // An untitled (legacy/automatic) transaction pre-fills its generated label.
    title: props.transaction?.title || props.transaction?.display_title || '',
    transaction_type: props.transaction?.transaction_type || 'income',
    finance_category_id: props.transaction?.finance_category_id || '',
    amount: props.transaction?.amount || '',
    transaction_date: props.transaction?.transaction_date ? String(props.transaction.transaction_date).slice(0, 10) : new Date().toISOString().slice(0, 10),
    description: props.transaction?.description || '',
    payment_method: props.transaction?.payment_method || 'cash',
    payment_account: props.transaction?.payment_account || '',
    payment_ccp_key: props.transaction?.payment_ccp_key || '',
    payment_bank_name: props.transaction?.payment_bank_name || '',
    payment_holder: props.transaction?.payment_holder || '',
    payment_reference: props.transaction?.payment_reference || '',
    finance_account_id: props.transaction?.finance_account_id || props.financeAccounts[0]?.id || '',
    status: props.transaction?.status || 'Paid',
    related_entity_id: props.transaction?.related_entity_id || '',
    receipt: null,
});

const categoryOptions = computed(() => props.financeCategories.filter((c) => c.type === form.transaction_type));
// Name first; membership ID, category and birth year tell two people with the
// same name apart. keywords lets the full name (father's name) match too.
const playerOptions = computed(() => props.players.map((p) => ({
    value: p.id,
    label: p.name,
    description: [p.membership_id, p.category, p.birth_year].filter(Boolean).join(' · '),
    keywords: p.fullname,
})));
const selectedPlayer = computed(() => props.players.find((p) => String(p.id) === String(form.related_entity_id)) || null);
const { formatMoney } = useFormatMoney();
const { accountLabel } = useFinanceAccountLabel();

watch(() => form.related_entity_id, (playerId) => {
    const player = props.players.find((item) => String(item.id) === String(playerId));
    if (player?.default_finance_account_id) {
        form.finance_account_id = player.default_finance_account_id;
    }
});

// reset category when the type flips and the selection no longer matches
function onTypeChange() {
    const stillValid = categoryOptions.value.some((c) => String(c.id) === String(form.finance_category_id));
    if (!stillValid) form.finance_category_id = '';
}

function useClubCcp() {
    if (!props.clubCcp) return;
    form.payment_account = props.clubCcp.accountNumber || props.clubCcp.account_number || '';
    form.payment_ccp_key = props.clubCcp.key || '';
    form.payment_holder = props.clubCcp.holder || '';
}

function submit() {
    form.transform((data) => ({
        ...data,
        related_entity_id: data.related_entity_id || null,
        related_entity_type: data.related_entity_id ? 'Player' : null,
        ...(isEdit ? { _method: 'put' } : {}),
    })).post(isEdit ? route('transactions.update', props.transaction.id) : route('transactions.store'), { forceFormData: true });
}
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-2xl space-y-6">
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 space-y-4">
            <div>
                <InputLabel :value="t('title')" />
                <TextInput v-model="form.title" class="mt-1 w-full" maxlength="150" required :placeholder="t('transaction_title_placeholder')" />
                <InputError :message="form.errors.title" class="mt-1" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel :value="t('type')" />
                    <select v-model="form.transaction_type" @change="onTypeChange" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm" required>
                        <option value="income">{{ t('income') }}</option>
                        <option value="expense">{{ t('expense') }}</option>
                    </select>
                    <InputError :message="form.errors.transaction_type" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('category')" />
                    <select v-model="form.finance_category_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm" required>
                        <option value="" disabled>-</option>
                        <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.localized_name || c.name }}</option>
                    </select>
                    <InputError :message="form.errors.finance_category_id" class="mt-1" />
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
                    <InputLabel :value="form.transaction_type === 'income' ? t('destination_register') : t('source_register')" />
                    <select v-model="form.finance_account_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm" required>
                        <option value="" disabled>{{ t('select_cash_register') }}</option>
                        <option v-for="account in financeAccounts" :key="account.id" :value="account.id">
                            {{ accountLabel(account) }}
                        </option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ form.transaction_type === 'income' ? t('income_register_hint') : t('expense_register_hint') }}
                    </p>
                    <InputError :message="form.errors.finance_account_id" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('payment_method')" />
                    <select v-model="form.payment_method" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm">
                        <option value="cash">{{ t('cash') }}</option>
                        <option value="bank">{{ t('bank_transfer') }}</option>
                        <option value="ccp">CCP</option>
                        <option value="baridimob">BaridiMob</option>
                        <option value="other">{{ t('other') }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('status')" />
                    <select v-model="form.status" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm">
                        <option value="Paid">{{ t('paid') }}</option>
                        <option value="Partial">{{ t('partial') }}</option>
                        <option value="Unpaid">{{ t('unpaid') }}</option>
                        <option value="Exempt">{{ t('exempt') }}</option>
                    </select>
                    <InputError :message="form.errors.status" class="mt-1" />
                </div>
            </div>

            <!-- CCP details -->
            <div v-if="form.payment_method === 'ccp'" class="rounded-xl bg-slate-50 dark:bg-slate-950 p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ t('ccp_details') }}</p>
                    <button v-if="clubCcp" type="button" @click="useClubCcp" class="text-xs font-medium text-primary-600 hover:underline">{{ t('use_club_ccp') }}</button>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div><InputLabel :value="t('ccp_number')" /><TextInput v-model="form.payment_account" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('ccp_key')" /><TextInput v-model="form.payment_ccp_key" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('holder')" /><TextInput v-model="form.payment_holder" class="mt-1 w-full" /></div>
                </div>
            </div>

            <!-- Bank details -->
            <div v-if="form.payment_method === 'bank'" class="rounded-xl bg-slate-50 dark:bg-slate-950 p-4 space-y-3">
                <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ t('bank_details') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><InputLabel :value="t('bank_name')" /><TextInput v-model="form.payment_bank_name" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('account_number')" /><TextInput v-model="form.payment_account" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('holder')" /><TextInput v-model="form.payment_holder" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="t('reference')" /><TextInput v-model="form.payment_reference" class="mt-1 w-full" /></div>
                </div>
            </div>

            <div v-if="players.length">
                <InputLabel :value="t('player')" />
                <SearchableSelect v-model="form.related_entity_id" :options="playerOptions" :placeholder="t('search_player')" />
                <!-- Confirms the right person before saving: photo, IDs, category, debt. -->
                <div v-if="selectedPlayer" class="mt-2 flex items-center gap-3 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200 dark:bg-slate-950 dark:ring-slate-800">
                    <img v-if="selectedPlayer.picture_url" :src="selectedPlayer.picture_url" alt="" class="h-12 w-12 shrink-0 rounded-full object-cover" />
                    <span v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary-100 text-lg font-bold text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">{{ selectedPlayer.name.charAt(0) }}</span>
                    <div class="min-w-0 flex-1 text-sm">
                        <p class="truncate font-semibold text-slate-900 dark:text-slate-100">{{ selectedPlayer.fullname || selectedPlayer.name }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            <span class="font-mono">{{ selectedPlayer.membership_id }}</span>
                            <span v-if="selectedPlayer.category"> · {{ selectedPlayer.category }}</span>
                            <span v-if="selectedPlayer.birth_year"> · {{ t('born_in') }} {{ selectedPlayer.birth_year }}</span>
                        </p>
                        <p v-if="selectedPlayer.branches?.length" class="truncate text-xs text-slate-500 dark:text-slate-400">{{ selectedPlayer.branches.join(', ') }}</p>
                    </div>
                    <div class="shrink-0 text-end text-xs">
                        <p class="text-slate-500 dark:text-slate-400">{{ t('outstanding_debt') }}</p>
                        <p class="font-semibold" :class="selectedPlayer.outstanding_debt > 0 ? 'text-rose-600' : 'text-emerald-600'">{{ formatMoney(selectedPlayer.outstanding_debt) }}</p>
                        <a :href="route('players.show', selectedPlayer.id)" target="_blank" class="text-primary-600 hover:underline">{{ t('view_player') }} ↗</a>
                    </div>
                </div>
                <InputError :message="form.errors.related_entity_id" class="mt-1" />
            </div>

            <div>
                <InputLabel :value="t('description')" />
                <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm" />
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

        <div class="flex items-center justify-end gap-3">
            <Link :href="route('transactions.index')"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('save') }}</PrimaryButton>
        </div>
    </form>
</template>
