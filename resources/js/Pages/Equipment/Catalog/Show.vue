<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { ref, watch, computed } from 'vue';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    catalog: Object,
    availableCount: Number,
    totalQuantity: Number,
    storageLocations: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
});

// Count-tracked catalogs (dossards, balls) get the quantity-first workflow;
// serialized ones keep the original one-row-per-unit screens.
const isSerialized = computed(() => !!props.catalog.requires_serial);

// Rent dropdown: show full name + membership id (and birthdate) instead of a raw id.
const playerOptions = computed(() => props.players.map((p) => ({
    value: p.id,
    label: `${p.fullname || ''} — ${p.membership_id || ''}${p.birthdate ? ' (' + p.birthdate + ')' : ''}`.trim(),
})));

const userOptions = computed(() => props.users.map((u) => ({ value: u.id, label: u.fullname })));

const rentableOptions = computed(() =>
    rentForm.rentable_type === 'User' ? userOptions.value : playerOptions.value);

// How many units sit in each condition, so a count-tracked catalog can show
// "Good 15 / Fair 2 / Damaged 3" without inventing per-unit identities.
const conditionBreakdown = computed(() => {
    const totals = {};
    for (const item of props.catalog.items ?? []) {
        const key = item.condition || 'Good';
        totals[key] = (totals[key] ?? 0) + (item.quantity ?? 1);
    }
    return Object.entries(totals).map(([condition, count]) => ({ condition, count }));
});

const unitsOut = computed(() => (props.totalQuantity ?? 0) - (props.availableCount ?? 0));

const showAddItemModal = ref(false);
const showRentModal = ref(false);
const showReturnModal = ref(false);
const selectedItem = ref(null);
const lostItemId = ref(null);
const foundItemId = ref(null);
const repairItemId = ref(null);
const fixedItemId = ref(null);

const serialPreview = ref('');

async function fetchSerialPreview() {
    serialPreview.value = '…';
    try {
        const params = new URLSearchParams({
            catalog_id: props.catalog.id,
            purchase_date: addItemForm.purchase_date,
        });
        const res = await fetch(`/equipment/items/preview-serial?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        serialPreview.value = res.ok ? (await res.json()).serial : '';
    } catch {
        serialPreview.value = '';
    }
}

function openAddItem() {
    showAddItemModal.value = true;
    fetchSerialPreview();
}

const addItemForm = useForm({
    catalog_id: props.catalog.id,
    designation: '',
    purchase_date: new Date().toISOString().slice(0, 10),
    condition: 'New',
    location: '',
    purchase_price: props.catalog.purchase_price || '',
    notes: '',
});

const rentForm = useForm({
    equipment_item_id: '',
    rentable_type: 'Player',
    rentable_id: '',
    // 'rental' is a temporary loan with a due date; 'assignment' is equipment
    // given to someone to work with, open-ended.
    type: 'rental',
    quantity: 1,
    checkout_date: new Date().toISOString().slice(0, 10),
    due_date: '',
    notes: '',
});

const returnForm = useForm({
    quantity: 1,
    condition: 'Good',
    return_date: new Date().toISOString().slice(0, 10),
    notes: '',
});

const showReceiveModal = ref(false);

const receiveForm = useForm({
    catalog_id: props.catalog.id,
    quantity: 1,
    unit_price: props.catalog.purchase_price || '',
    purchase_date: new Date().toISOString().slice(0, 10),
    condition: 'New',
    location: '',
    branch_ids: [],
    // Ticked by default: most stock arriving is bought. Unticking covers
    // donations, found items and an opening inventory.
    record_expense: true,
    received_via: 'purchase',
    notes: '',
});

function doReceive() {
    receiveForm
        .transform((data) => ({
            ...data,
            received_via: data.record_expense ? 'purchase' : data.received_via,
        }))
        .post(route('equipment.stock.receive'), {
            onSuccess: () => {
                showReceiveModal.value = false;
                receiveForm.reset('notes', 'quantity');
            },
        });
}

const showSplitModal = ref(false);

const splitForm = useForm({
    quantity: 1,
    condition: 'Damaged',
    notes: '',
});

function openSplit(item) {
    selectedItem.value = item;
    splitForm.quantity = 1;
    splitForm.condition = 'Damaged';
    splitForm.notes = '';
    splitForm.clearErrors();
    showSplitModal.value = true;
}

function doSplit() {
    splitForm.post(route('equipment.stock.split', selectedItem.value.id), {
        onSuccess: () => { showSplitModal.value = false; },
    });
}

/**
 * Soft warning only. Clubs lend across branches constantly, so this never
 * blocks — a hard block would just train people to untag equipment.
 */
const crossBranchWarning = computed(() => {
    if (rentForm.rentable_type !== 'Player' || !rentForm.rentable_id) return null;

    const lotBranches = (selectedItem.value?.branches ?? []).map((b) => b.id);
    if (lotBranches.length === 0) return null; // club-wide gear suits everyone

    const player = props.players.find((p) => p.id === rentForm.rentable_id);
    const playerBranches = player?.branch_ids ?? [];
    if (playerBranches.length === 0) return null;

    const shares = playerBranches.some((id) => lotBranches.includes(id));
    return shares ? null : t('equipment.cross_branch_warning');
});

const showEditModal = ref(false);
const deleteItemId = ref(null);

const editItemForm = useForm({
    designation: '',
    purchase_date: '',
    condition: 'Good',
    location: '',
    notes: '',
});

const editLocationOptions = computed(() => {
    const opts = [...props.storageLocations];
    const current = editItemForm.location;
    if (current && !opts.includes(current)) opts.unshift(current);
    return opts;
});

function openEdit(item) {
    selectedItem.value = item;
    editItemForm.designation = item.designation || '';
    editItemForm.purchase_date = item.purchase_date ? String(item.purchase_date).slice(0, 10) : '';
    editItemForm.condition = item.condition || 'Good';
    editItemForm.location = item.location || '';
    editItemForm.notes = item.notes || '';
    editItemForm.clearErrors();
    showEditModal.value = true;
}

function submitEdit() {
    editItemForm.put(route('equipment.items.update', selectedItem.value.id), {
        onSuccess: () => { showEditModal.value = false; },
    });
}

function deleteItem() {
    const id = deleteItemId.value;
    deleteItemId.value = null;
    router.delete(route('equipment.items.destroy', id), { preserveState: false });
}

function addItem() {
    addItemForm.post(route('equipment.items.store'), {
        onSuccess: () => {
            showAddItemModal.value = false;
            addItemForm.reset('notes', 'designation');
        },
    });
}

function openRent(item) {
    selectedItem.value = item;
    rentForm.equipment_item_id = item.id;
    rentForm.quantity = 1;
    rentForm.clearErrors();
    showRentModal.value = true;
}

function doRent() {
    rentForm.post(route('equipment.items.rent'), {
        onSuccess: () => {
            showRentModal.value = false;
            rentForm.reset('rentable_id', 'due_date', 'notes', 'quantity');
        },
    });
}

function openReturn(item) {
    selectedItem.value = item;
    // Default to bringing back everything still out, which is the common case.
    returnForm.quantity = item.active_rental?.quantity
        ? item.active_rental.quantity - (item.active_rental.returned_quantity ?? 0)
        : 1;
    returnForm.clearErrors();
    showReturnModal.value = true;
}

function doReturn() {
    const rental = selectedItem.value?.active_rental;
    if (!rental) return;
    returnForm.post(route('equipment.rentals.return', rental.id), {
        onSuccess: () => {
            showReturnModal.value = false;
        },
    });
}

function sendToRepair(itemId) {
    repairItemId.value = null;
    router.post(route('equipment.items.repair', itemId), {}, {
        preserveState: false,
    });
}

function completeRepair(itemId) {
    fixedItemId.value = null;
    router.post(route('equipment.items.complete-repair', itemId), {}, {
        preserveState: false,
    });
}

function markAsLost(itemId) {
    lostItemId.value = null;
    router.post(route('equipment.items.mark-lost', itemId), {}, {
        preserveState: false,
    });
}

function markAsFound(itemId) {
    foundItemId.value = null;
    router.post(route('equipment.items.mark-found', itemId), {}, {
        preserveState: false,
    });
}

watch(() => addItemForm.purchase_date, () => {
    if (showAddItemModal.value) fetchSerialPreview();
});

// An assignment is open-ended; the backend discards a due date on one, so
// don't leave a stale value sitting in the form.
watch(() => rentForm.type, (type) => {
    if (type === 'assignment') rentForm.due_date = '';
});

// Switching between a player and a staff member invalidates the chosen id.
watch(() => rentForm.rentable_type, () => { rentForm.rentable_id = ''; });

const statusColor = (s) => {
    const map = { Available: 'emerald', Rented: 'amber', 'Under Repair': 'slate', Lost: 'rose', Retired: 'slate' };
    return map[s] || 'slate';
};

// Translate an enum value (status/condition) via its lowercased, underscored i18n key,
// falling back to the raw value if no key exists.
const stateLabel = (v) => {
    if (!v) return '—';
    const key = String(v).toLowerCase().replaceAll(' ', '_');
    const translated = t(key);
    return translated === key ? v : translated;
};

// --- Item import (CSV + Excel; Excel converted in-browser, then the CSV pipeline) ---
const showImport = ref(false);
const importForm = useForm({ file: null });
const importConverting = ref(false);

async function onImportFile(e) {
    const file = e.target.files?.[0];
    importForm.clearErrors();
    if (!file) { importForm.file = null; return; }
    if (!/\.(xlsx|xls)$/i.test(file.name)) { importForm.file = file; return; }
    importConverting.value = true;
    try {
        const XLSX = await import('xlsx');
        const wb = XLSX.read(await file.arrayBuffer(), { type: 'array' });
        const csv = XLSX.utils.sheet_to_csv(wb.Sheets[wb.SheetNames[0]]);
        importForm.file = new File([csv], file.name.replace(/\.(xlsx|xls)$/i, '.csv'), { type: 'text/csv' });
    } catch (err) {
        importForm.file = null;
        importForm.setError('file', t('excel_parse_failed'));
    } finally {
        importConverting.value = false;
    }
}

function submitImport() {
    importForm.post(route('equipment.items.import', props.catalog.id), {
        forceFormData: true,
        onSuccess: () => { showImport.value = false; importForm.reset(); },
    });
}
</script>

<template>
    <Head :title="catalog.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('equipment.catalogs.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </Link>
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ catalog.name }}</h1>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button @click="showReceiveModal = true" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 transition-colors">
                        + {{ t('equipment.receive_stock') }}
                    </button>
                    <!-- Serialized catalogs keep the one-unit-at-a-time flow, which generates a serial. -->
                    <button v-if="isSerialized" @click="openAddItem" class="inline-flex items-center rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        + {{ t('add') }}
                    </button>
                    <button @click="showImport = true" class="inline-flex items-center rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        {{ t('import') }}
                    </button>
                    <a :href="route('equipment.items.export', catalog.id)" class="inline-flex items-center rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        {{ t('export') }}
                    </a>
                    <Link :href="route('equipment.catalogs.edit', catalog.id)" class="inline-flex items-center rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        {{ t('edit') }}
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Catalog info -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('category') }}</p>
                        <p class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">{{ catalog.category }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('brand') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ catalog.brand || '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('price') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ formatMoney(catalog.purchase_price) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('available') }} / {{ t('total') }}</p>
                        <!-- Units, not rows: one lot can hold 100 dossards. -->
                        <p class="mt-1 text-sm font-semibold text-emerald-700">
                            {{ availableCount }} / {{ totalQuantity }}
                            <span class="font-normal text-slate-400">{{ t('equipment.units') }}</span>
                        </p>
                    </div>
                </div>

                <!-- Condition strip: the operational truth for counted stock. -->
                <div v-if="conditionBreakdown.length" class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 dark:border-slate-800 pt-4">
                    <span v-for="row in conditionBreakdown" :key="row.condition"
                        class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 dark:bg-slate-800 px-3 py-1 text-xs">
                        <span class="text-slate-500 dark:text-slate-400">{{ stateLabel(row.condition) }}</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100">{{ row.count }}</span>
                    </span>
                    <span v-if="unitsOut > 0" class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 dark:bg-amber-500/20 px-3 py-1 text-xs">
                        <span class="text-amber-700 dark:text-amber-300">{{ t('rented') }}</span>
                        <span class="font-semibold text-amber-900 dark:text-amber-200">{{ unitsOut }}</span>
                    </span>
                </div>

                <p v-if="catalog.description" class="mt-4 text-sm text-slate-600 dark:text-slate-300">{{ catalog.description }}</p>
            </div>

            <!-- Items table -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="border-b border-slate-100 dark:border-slate-800 px-5 py-4">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('items') }}</h3>
                </div>
                <div v-if="!catalog.items?.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th v-if="isSerialized" class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('identifier') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('designation') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('equipment.quantity') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('condition') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('branch') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('rented_to') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="item in catalog.items" :key="item.id">
                                <td v-if="isSerialized" class="px-4 py-3 font-mono text-sm text-slate-700 dark:text-slate-200">{{ item.unique_identifier }}</td>
                                <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ item.designation || '—' }}</td>
                                <td class="px-4 py-3 text-end text-sm">
                                    <span class="font-semibold text-slate-900 dark:text-slate-100">{{ item.available_quantity ?? 0 }}</span>
                                    <span class="text-slate-400"> / {{ item.quantity ?? 1 }}</span>
                                </td>
                                <td class="px-4 py-3"><Badge :label="stateLabel(item.status)" :color="statusColor(item.status)" /></td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ stateLabel(item.condition) }}</td>
                                <td class="px-4 py-3 text-sm">
                                    <!-- No tags means club-wide, not missing data. -->
                                    <span v-if="!item.branches?.length" class="text-xs text-slate-400">{{ t('equipment.all_branches') }}</span>
                                    <span v-else class="flex flex-wrap gap-1">
                                        <span v-for="b in item.branches" :key="b.id"
                                            class="rounded bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 text-xs text-slate-600 dark:text-slate-300">
                                            {{ b.localized_name || b.name }}
                                        </span>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                    <span v-if="item.active_rental">
                                        {{ item.active_rental.rentable?.firstname || item.active_rental.rentable?.name }}
                                        {{ item.active_rental.rentable?.lastname }}
                                        <span v-if="(item.active_rental.quantity ?? 1) > 1" class="text-xs text-slate-400">
                                            ({{ item.active_rental.quantity - (item.active_rental.returned_quantity ?? 0) }})
                                        </span>
                                    </span>
                                    <span v-else>-</span>
                                </td>
                                <td class="px-4 py-3 text-end">
                                    <div class="flex items-center justify-end gap-2">
                                        <!-- Driven by available units, not status: a lot of 20 with 10 out is still lendable. -->
                                        <button v-if="(item.available_quantity ?? 0) > 0" @click="openRent(item)" class="text-sm text-amber-600 hover:text-amber-800">{{ t('rent') }}</button>
                                        <button v-if="item.active_rental" @click="openReturn(item)" class="text-sm text-emerald-600 hover:text-emerald-800">{{ t('return') }}</button>
                                        <button v-if="(item.quantity ?? 1) > 1 && (item.available_quantity ?? 0) > 0" @click="openSplit(item)"
                                            :title="t('equipment.mark_damaged')" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">⚖️</button>
                                        <button v-if="item.status === 'Available'" @click="repairItemId = item.id" :title="t('send_to_repair')" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">🔧</button>
                                        <button v-if="item.status === 'Under Repair'" @click="fixedItemId = item.id" class="text-sm text-emerald-600 hover:text-emerald-800">{{ t('mark_fixed') }}</button>
                                        <button v-if="['Available','Rented'].includes(item.status)" @click="lostItemId = item.id" class="text-sm text-rose-500 hover:text-rose-700">{{ t('lost') }}</button>
                                        <button v-if="item.status === 'Lost'" @click="foundItemId = item.id" class="text-sm text-emerald-600 hover:text-emerald-800">{{ t('restore') }}</button>
                                        <Link :href="route('equipment.items.history', item.id)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200" :title="t('history')">🕘</Link>
                                        <button @click="openEdit(item)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200" :title="t('edit')">✏️</button>
                                        <button v-if="!item.active_rental" @click="deleteItemId = item.id" class="text-sm text-rose-500 hover:text-rose-700" :title="t('delete')">🗑️</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Add Item Modal -->
        <Teleport to="body">
            <div v-if="showAddItemModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" @click.self="showAddItemModal = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('add') }} {{ t('items') }}</h3>
                    <form @submit.prevent="addItem" class="mt-4 space-y-3">
                        <div>
                            <InputLabel value="ID / Serial" />
                            <div class="mt-1 flex items-center rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 px-3 py-2 font-mono text-sm text-slate-700 dark:text-slate-200">
                                {{ serialPreview || '—' }}
                            </div>
                            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ t('assigned_on_save') }}</p>
                        </div>
                        <div>
                            <InputLabel :value="t('designation')" />
                            <TextInput v-model="addItemForm.designation" class="mt-1 w-full" placeholder="e.g. T-shirt n° 10" />
                            <InputError :message="addItemForm.errors.designation" class="mt-1" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel :value="t('purchase_date')" />
                                <TextInput v-model="addItemForm.purchase_date" type="date" class="mt-1 w-full" />
                            </div>
                            <div>
                                <InputLabel :value="t('condition')" />
                                <select v-model="addItemForm.condition" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    <option v-for="c in ['New','Good','Fair','Poor','Damaged']" :key="c" :value="c">{{ c }}</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <InputLabel :value="t('price')" />
                            <TextInput v-model="addItemForm.purchase_price" type="number" step="0.01" min="0" class="mt-1 w-full" />
                        </div>
                        <div>
                            <InputLabel :value="t('notes')" />
                            <textarea v-model="addItemForm.notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                        </div>
                        <div>
                            <InputLabel :value="t('location')" />
                            <select v-model="addItemForm.location" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">—</option>
                                <option v-for="loc in storageLocations" :key="loc" :value="loc">{{ loc }}</option>
                            </select>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showAddItemModal = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <PrimaryButton :disabled="addItemForm.processing">{{ t('add') }}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Item Modal -->
            <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" @click.self="showEditModal = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('edit') }} — {{ selectedItem?.unique_identifier }}</h3>
                    <form @submit.prevent="submitEdit" class="mt-4 space-y-3">
                        <div>
                            <InputLabel :value="t('designation')" />
                            <TextInput v-model="editItemForm.designation" class="mt-1 w-full" />
                            <InputError :message="editItemForm.errors.designation" class="mt-1" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel :value="t('purchase_date')" />
                                <TextInput v-model="editItemForm.purchase_date" type="date" class="mt-1 w-full" />
                                <InputError :message="editItemForm.errors.purchase_date" class="mt-1" />
                            </div>
                            <div>
                                <InputLabel :value="t('condition')" />
                                <select v-model="editItemForm.condition" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    <option v-for="c in ['New','Good','Fair','Poor','Damaged']" :key="c" :value="c">{{ c }}</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <InputLabel :value="t('location')" />
                            <select v-model="editItemForm.location" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">—</option>
                                <option v-for="loc in editLocationOptions" :key="loc" :value="loc">{{ loc }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel :value="t('notes')" />
                            <textarea v-model="editItemForm.notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showEditModal = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <PrimaryButton :disabled="editItemForm.processing">{{ t('save') }}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Rent Modal -->
            <div v-if="showRentModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" @click.self="showRentModal = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                        {{ t('rent') }} — {{ selectedItem?.unique_identifier || selectedItem?.designation || catalog.name }}
                    </h3>
                    <form @submit.prevent="doRent" class="mt-4 space-y-3">
                        <!-- A rental comes back by a date; an assignment is open-ended. -->
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" @click="rentForm.type = 'rental'"
                                :class="rentForm.type === 'rental' ? 'bg-primary-100 border-primary-500 text-primary-800' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300'"
                                class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors">{{ t('equipment.rental') }}</button>
                            <button type="button" @click="rentForm.type = 'assignment'"
                                :class="rentForm.type === 'assignment' ? 'bg-primary-100 border-primary-500 text-primary-800' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300'"
                                class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors">{{ t('equipment.assignment') }}</button>
                        </div>
                        <p v-if="rentForm.type === 'assignment'" class="text-xs text-slate-500 dark:text-slate-400">{{ t('equipment.assignment_hint') }}</p>

                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" @click="rentForm.rentable_type = 'Player'"
                                :class="rentForm.rentable_type === 'Player' ? 'bg-slate-100 dark:bg-slate-800 border-slate-400' : 'border-slate-200 dark:border-slate-800'"
                                class="rounded-lg border px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{{ t('player') }}</button>
                            <button type="button" @click="rentForm.rentable_type = 'User'"
                                :class="rentForm.rentable_type === 'User' ? 'bg-slate-100 dark:bg-slate-800 border-slate-400' : 'border-slate-200 dark:border-slate-800'"
                                class="rounded-lg border px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{{ t('user') }}</button>
                        </div>

                        <div>
                            <InputLabel :value="rentForm.rentable_type === 'User' ? t('user') : t('player')" />
                            <SearchableSelect v-model="rentForm.rentable_id" :options="rentableOptions" :placeholder="t('select_player')" />
                            <InputError :message="rentForm.errors.rentable_id" class="mt-1" />
                        </div>

                        <!-- Soft warning: never blocks. Clubs lend across branches constantly. -->
                        <p v-if="crossBranchWarning" class="rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                            ⚠ {{ crossBranchWarning }}
                        </p>

                        <div v-if="(selectedItem?.quantity ?? 1) > 1">
                            <InputLabel :value="t('equipment.quantity')" />
                            <TextInput v-model="rentForm.quantity" type="number" min="1" :max="selectedItem?.available_quantity" class="mt-1 w-full" required />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ selectedItem?.available_quantity }} {{ t('equipment.available_of_total') }}
                            </p>
                            <InputError :message="rentForm.errors.quantity" class="mt-1" />
                        </div>

                        <div class="grid gap-3" :class="rentForm.type === 'rental' ? 'sm:grid-cols-2' : ''">
                            <div>
                                <InputLabel :value="t('date')" />
                                <TextInput v-model="rentForm.checkout_date" type="date" class="mt-1 w-full" />
                            </div>
                            <div v-if="rentForm.type === 'rental'">
                                <InputLabel :value="t('due_date')" />
                                <TextInput v-model="rentForm.due_date" type="date" class="mt-1 w-full" />
                                <InputError :message="rentForm.errors.due_date" class="mt-1" />
                            </div>
                        </div>
                        <div>
                            <InputLabel :value="t('notes')" />
                            <textarea v-model="rentForm.notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showRentModal = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <PrimaryButton :disabled="rentForm.processing">{{ t('rent') }}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Return Modal -->
            <div v-if="showReturnModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" @click.self="showReturnModal = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                        {{ t('return') }} — {{ selectedItem?.unique_identifier || selectedItem?.designation || catalog.name }}
                    </h3>
                    <form @submit.prevent="doReturn" class="mt-4 space-y-3">
                        <!-- Units can come back in instalments; the rental stays open until all are in. -->
                        <div v-if="(selectedItem?.active_rental?.quantity ?? 1) > 1">
                            <InputLabel :value="t('equipment.quantity')" />
                            <TextInput v-model="returnForm.quantity" type="number" min="1"
                                :max="selectedItem.active_rental.quantity - (selectedItem.active_rental.returned_quantity ?? 0)"
                                class="mt-1 w-full" required />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ selectedItem.active_rental.returned_quantity ?? 0 }} / {{ selectedItem.active_rental.quantity }}
                                {{ t('equipment.returned_of') }}
                            </p>
                            <InputError :message="returnForm.errors.quantity" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel :value="t('date')" />
                            <TextInput v-model="returnForm.return_date" type="date" class="mt-1 w-full" />
                        </div>
                        <div>
                            <InputLabel :value="t('condition')" />
                            <div class="mt-2 grid grid-cols-5 gap-2">
                                <button v-for="c in ['New','Good','Fair','Poor','Damaged']" :key="c" type="button"
                                    @click="returnForm.condition = c"
                                    :class="returnForm.condition === c ? 'bg-primary-100 border-primary-500 text-primary-800' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300'"
                                    class="rounded-lg border px-2 py-2 text-xs font-medium text-center transition-colors">{{ c }}</button>
                            </div>
                        </div>
                        <div>
                            <InputLabel :value="t('notes')" />
                            <textarea v-model="returnForm.notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showReturnModal = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <PrimaryButton :disabled="returnForm.processing">{{ t('return') }}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Import items modal -->
        <Teleport to="body">
            <div v-if="showImport" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="showImport = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('import') }} — {{ catalog.name }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('import_items_hint') }}</p>
                    <form @submit.prevent="submitImport" class="mt-4 space-y-4">
                        <input
                            type="file"
                            accept=".csv,.xlsx,.xls,text/csv"
                            @change="onImportFile"
                            required
                            class="w-full text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100"
                        />
                        <p v-if="importConverting" class="text-sm text-slate-500 dark:text-slate-400">{{ t('converting_excel') }}</p>
                        <p v-if="importForm.errors.file" class="text-sm text-rose-600">{{ importForm.errors.file }}</p>
                        <div class="flex items-center justify-between gap-3 pt-2">
                            <a :href="route('equipment.items.import.template')" class="text-sm font-medium text-primary-600 hover:underline">{{ t('download_template') }}</a>
                            <div class="flex gap-2">
                                <button type="button" @click="showImport = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                                <button type="submit" :disabled="importForm.processing || importConverting || !importForm.file" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">{{ t('import') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <ConfirmModal :show="!!repairItemId" :message="t('send_to_repair') + '?'" @confirm="sendToRepair(repairItemId)" @cancel="repairItemId = null" />
        <ConfirmModal :show="!!fixedItemId" :message="t('mark_fixed_confirm')" @confirm="completeRepair(fixedItemId)" @cancel="fixedItemId = null" />
        <!-- Receive stock: the only path that can spend money, and only if asked to. -->
        <Teleport to="body">
            <div v-if="showReceiveModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="showReceiveModal = false">
                <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('equipment.receive_stock') }} — {{ catalog.name }}</h3>
                    <form @submit.prevent="doReceive" class="mt-4 space-y-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel :value="t('equipment.quantity')" />
                                <TextInput v-model="receiveForm.quantity" type="number" min="1" class="mt-1 w-full" required />
                                <InputError :message="receiveForm.errors.quantity" class="mt-1" />
                            </div>
                            <div>
                                <InputLabel :value="t('equipment.unit_price')" />
                                <TextInput v-model="receiveForm.unit_price" type="number" step="0.01" min="0" class="mt-1 w-full" />
                                <InputError :message="receiveForm.errors.unit_price" class="mt-1" />
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel :value="t('date')" />
                                <TextInput v-model="receiveForm.purchase_date" type="date" class="mt-1 w-full" required />
                            </div>
                            <div>
                                <InputLabel :value="t('condition')" />
                                <select v-model="receiveForm.condition" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    <option v-for="c in ['New','Good','Fair','Poor','Damaged']" :key="c" :value="c">{{ stateLabel(c) }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <InputLabel :value="t('location')" />
                            <select v-model="receiveForm.location" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">—</option>
                                <option v-for="loc in storageLocations" :key="loc" :value="loc">{{ loc }}</option>
                            </select>
                        </div>

                        <div v-if="branches.length">
                            <InputLabel :value="t('branch')" />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <label v-for="b in branches" :key="b.id"
                                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm"
                                    :class="receiveForm.branch_ids.includes(b.id) ? 'border-primary-500 bg-primary-50 text-primary-800' : 'border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300'">
                                    <input type="checkbox" :value="b.id" v-model="receiveForm.branch_ids" class="hidden" />
                                    {{ b.name }}
                                </label>
                            </div>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('equipment.branch_hint') }}</p>
                        </div>

                        <label class="flex items-start gap-3 rounded-lg bg-slate-50 dark:bg-slate-800/50 p-3">
                            <input type="checkbox" v-model="receiveForm.record_expense"
                                class="mt-0.5 rounded border-slate-300 dark:border-slate-600 text-primary-600 focus:ring-primary-500" />
                            <span>
                                <span class="block text-sm font-medium text-slate-900 dark:text-slate-100">{{ t('equipment.record_as_expense') }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('equipment.record_as_expense_hint') }}</span>
                            </span>
                        </label>

                        <!-- Only asked when no money changed hands, so the reason is captured. -->
                        <div v-if="!receiveForm.record_expense">
                            <InputLabel :value="t('equipment.received_via')" />
                            <select v-model="receiveForm.received_via" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="donation">{{ t('donation') }}</option>
                                <option value="opening_balance">{{ t('opening_balance') }}</option>
                                <option value="adjustment">{{ t('adjustment') }}</option>
                            </select>
                        </div>

                        <div>
                            <InputLabel :value="t('notes')" />
                            <textarea v-model="receiveForm.notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showReceiveModal = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <PrimaryButton :disabled="receiveForm.processing">{{ t('save') }}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Split: reclassify part of a lot without touching the rest. -->
            <div v-if="showSplitModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="showSplitModal = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('equipment.mark_damaged') }}</h3>
                    <form @submit.prevent="doSplit" class="mt-4 space-y-3">
                        <div>
                            <InputLabel :value="t('equipment.quantity')" />
                            <TextInput v-model="splitForm.quantity" type="number" min="1" :max="selectedItem?.available_quantity" class="mt-1 w-full" required />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ selectedItem?.available_quantity }} {{ t('equipment.available_of_total') }}
                            </p>
                            <InputError :message="splitForm.errors.quantity" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel :value="t('condition')" />
                            <div class="mt-2 grid grid-cols-5 gap-2">
                                <button v-for="c in ['New','Good','Fair','Poor','Damaged']" :key="c" type="button"
                                    @click="splitForm.condition = c"
                                    :class="splitForm.condition === c ? 'bg-primary-100 border-primary-500 text-primary-800' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300'"
                                    class="rounded-lg border px-2 py-2 text-xs font-medium text-center transition-colors">{{ stateLabel(c) }}</button>
                            </div>
                        </div>
                        <div>
                            <InputLabel :value="t('notes')" />
                            <textarea v-model="splitForm.notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showSplitModal = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <PrimaryButton :disabled="splitForm.processing">{{ t('save') }}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <ConfirmModal :show="!!lostItemId" :message="t('mark_as_lost') + '?'" @confirm="markAsLost(lostItemId)" @cancel="lostItemId = null" />
        <ConfirmModal :show="!!foundItemId" :message="t('restore_item') + '?'" @confirm="markAsFound(foundItemId)" @cancel="foundItemId = null" />
        <ConfirmModal :show="!!deleteItemId" :message="t('are_you_sure')" @confirm="deleteItem" @cancel="deleteItemId = null" />
    </AuthenticatedLayout>
</template>
