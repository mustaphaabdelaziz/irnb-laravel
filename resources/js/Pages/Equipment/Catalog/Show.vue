<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { ref } from 'vue';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    catalog: Object,
    availableCount: Number,
});

const showAddItemModal = ref(false);
const showRentModal = ref(false);
const showReturnModal = ref(false);
const selectedItem = ref(null);
const lostItemId = ref(null);
const repairItemId = ref(null);

const addItemForm = useForm({
    catalog_id: props.catalog.id,
    unique_identifier: '',
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
    due_date: '',
    notes: '',
});

const returnForm = useForm({
    condition: 'Good',
    notes: '',
});

function addItem() {
    addItemForm.post(route('equipment.items.store'), {
        onSuccess: () => {
            showAddItemModal.value = false;
            addItemForm.reset('unique_identifier', 'notes');
        },
    });
}

function openRent(item) {
    selectedItem.value = item;
    rentForm.equipment_item_id = item.id;
    showRentModal.value = true;
}

function doRent() {
    rentForm.post(route('equipment.items.rent'), {
        onSuccess: () => {
            showRentModal.value = false;
            rentForm.reset('rentable_id', 'due_date', 'notes');
        },
    });
}

function openReturn(item) {
    selectedItem.value = item;
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

function markAsLost(itemId) {
    lostItemId.value = null;
    router.post(route('equipment.items.mark-lost', itemId), {}, {
        preserveState: false,
    });
}

const statusColor = (s) => {
    const map = { Available: 'emerald', Rented: 'amber', 'Under Repair': 'slate', Lost: 'rose', Retired: 'slate' };
    return map[s] || 'slate';
};
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
                <div class="flex gap-2">
                    <button @click="showAddItemModal = true" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 transition-colors">
                        + {{ t('add') }}
                    </button>
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
                        <p class="mt-1 text-sm font-semibold text-emerald-700">{{ availableCount }} / {{ catalog.items?.length || 0 }}</p>
                    </div>
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
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('identifier') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('condition') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('rented_to') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="item in catalog.items" :key="item.id">
                                <td class="px-4 py-3 font-mono text-sm text-slate-700 dark:text-slate-200">{{ item.unique_identifier }}</td>
                                <td class="px-4 py-3"><Badge :label="item.status" :color="statusColor(item.status)" /></td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ item.condition }}</td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                    <span v-if="item.status === 'Rented' && item.active_rental">
                                        {{ item.active_rental.rentable?.firstname }} {{ item.active_rental.rentable?.lastname }}
                                    </span>
                                    <span v-else>-</span>
                                </td>
                                <td class="px-4 py-3 text-end">
                                    <div class="flex items-center justify-end gap-2">
                                        <button v-if="item.status === 'Available'" @click="openRent(item)" class="text-sm text-amber-600 hover:text-amber-800">{{ t('rent') }}</button>
                                        <button v-if="item.status === 'Rented'" @click="openReturn(item)" class="text-sm text-emerald-600 hover:text-emerald-800">{{ t('return') }}</button>
                                        <button v-if="item.status === 'Available'" @click="repairItemId = item.id" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">🔧</button>
                                        <button v-if="['Available','Rented'].includes(item.status)" @click="lostItemId = item.id" class="text-sm text-rose-500 hover:text-rose-700">{{ t('lost') }}</button>
                                        <Link :href="route('equipment.items.history', item.id)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200" :title="t('history')">🕘</Link>
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
                            <TextInput v-model="addItemForm.unique_identifier" class="mt-1 w-full" required placeholder="e.g. BALL-001" />
                            <InputError :message="addItemForm.errors.unique_identifier" class="mt-1" />
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
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showAddItemModal = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <PrimaryButton :disabled="addItemForm.processing">{{ t('add') }}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Rent Modal -->
            <div v-if="showRentModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" @click.self="showRentModal = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('rent') }} — {{ selectedItem?.unique_identifier }}</h3>
                    <form @submit.prevent="doRent" class="mt-4 space-y-3">
                        <div>
                            <InputLabel :value="t('player') + ' ID'" />
                            <TextInput v-model="rentForm.rentable_id" type="number" class="mt-1 w-full" required placeholder="Player ID" />
                            <InputError :message="rentForm.errors.rentable_id" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel :value="t('due_date')" />
                            <TextInput v-model="rentForm.due_date" type="date" class="mt-1 w-full" required />
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
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('return') }} — {{ selectedItem?.unique_identifier }}</h3>
                    <form @submit.prevent="doReturn" class="mt-4 space-y-3">
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

        <ConfirmModal :show="!!repairItemId" :message="t('send_to_repair') + '?'" @confirm="sendToRepair(repairItemId)" @cancel="repairItemId = null" />
        <ConfirmModal :show="!!lostItemId" :message="t('mark_as_lost') + '?'" @confirm="markAsLost(lostItemId)" @cancel="lostItemId = null" />
    </AuthenticatedLayout>
</template>
