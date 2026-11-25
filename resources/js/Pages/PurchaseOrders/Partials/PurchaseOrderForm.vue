<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import IconButton from '@/Components/IconButton.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();
const { statusLabel } = useStatusLabel();
const props = defineProps({
    order: { type: Object, default: null },
    // Sent or received: supplier, designations and quantities are locked; only unit prices change.
    pricesOnly: { type: Boolean, default: false },
    suppliers: { type: Array, default: () => [] },
    catalogs: { type: Array, default: () => [] },
});
const isEdit = !!props.order;
const day = (value) => (value ? String(value).slice(0, 10) : '');
const METHODS = ['bank', 'ccp', 'cheque', 'cash', 'baridimob', 'other'];

let nextKey = 0;
const makeLine = (l = {}) => ({
    key: nextKey++,
    id: l.id ?? null,
    equipment_catalog_id: l.equipment_catalog_id ?? '',
    designation: l.designation ?? '',
    quantity: l.quantity ?? 1,
    unit_price: l.unit_price ?? '',
});

const form = useForm({
    order_date: day(props.order?.order_date) || localToday(),
    supplier_id: props.order?.supplier_id ?? '',
    requested_by: props.order?.requested_by ?? '',
    delivery_mode: props.order?.delivery_mode ?? '',
    payment_mode: props.order?.payment_mode ?? '',
    observation: props.order?.observation ?? '',
    lines: (props.order?.lines?.length ? props.order.lines : [{}]).map(makeLine),
});

const supplierOptions = computed(() => props.suppliers.map((s) => ({
    value: s.id, label: s.name, description: [s.address, s.phone].filter(Boolean).join(' · '),
})));
const catalogOptions = computed(() => props.catalogs.map((c) => ({ value: c.id, label: c.name })));
const catalogName = (id) => props.catalogs.find((c) => String(c.id) === String(id))?.name ?? '';

// Picking a catalog item fills the designation, unless the user typed their own.
function pickCatalog(row, id) {
    const previous = catalogName(row.equipment_catalog_id);
    row.equipment_catalog_id = id;
    if (id && (row.designation === '' || row.designation === previous)) row.designation = catalogName(id);
}
const addLine = () => form.lines.push(makeLine());
const removeLine = (index) => form.lines.splice(index, 1);
const amount = (row) => (row.unit_price === '' || row.unit_price === null ? null : Number(row.quantity || 0) * Number(row.unit_price));
const total = computed(() => (form.lines.every((r) => amount(r) !== null) ? form.lines.reduce((sum, r) => sum + amount(r), 0) : null));
const lineError = (index, field) => form.errors[`lines.${index}.${field}`];
const blank = (v) => (v === '' ? null : v);

function submit() {
    if (props.pricesOnly) {
        form.transform((data) => ({ lines: data.lines.map((r) => ({ id: r.id, unit_price: blank(r.unit_price) })) }));
    } else {
        form.transform((data) => ({
            order_date: data.order_date,
            supplier_id: blank(data.supplier_id),
            requested_by: blank(data.requested_by),
            delivery_mode: blank(data.delivery_mode),
            payment_mode: blank(data.payment_mode),
            observation: blank(data.observation),
            lines: data.lines.map((r) => ({
                equipment_catalog_id: blank(r.equipment_catalog_id),
                designation: r.designation,
                quantity: r.quantity,
                unit_price: blank(r.unit_price),
            })),
        }));
    }
    isEdit ? form.put(route('purchase-orders.update', props.order.id)) : form.post(route('purchase-orders.store'));
}
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-4xl space-y-6">
        <p v-if="pricesOnly" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ t('pur.prices_only_hint') }}</p>

        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <InputLabel :value="t('pur.supplier')" />
                    <SearchableSelect v-model="form.supplier_id" :options="supplierOptions" :placeholder="t('pur.choose_supplier')" :disabled="pricesOnly" class="mt-1" />
                    <InputError :message="form.errors.supplier_id" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('date')" />
                    <TextInput v-model="form.order_date" type="date" required :disabled="pricesOnly" class="mt-1 w-full" />
                    <InputError :message="form.errors.order_date" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('pur.requested_by')" />
                    <TextInput v-model="form.requested_by" maxlength="200" :disabled="pricesOnly" class="mt-1 w-full" />
                    <InputError :message="form.errors.requested_by" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('pur.delivery_mode')" />
                    <TextInput v-model="form.delivery_mode" maxlength="200" :disabled="pricesOnly" class="mt-1 w-full" />
                    <InputError :message="form.errors.delivery_mode" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('pur.payment_mode')" />
                    <select v-model="form.payment_mode" :disabled="pricesOnly" class="mt-1 w-full rounded-lg border-slate-300 shadow-sm disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900">
                        <option value="">—</option>
                        <option v-for="m in METHODS" :key="m" :value="m">{{ statusLabel('payment_method', m) }}</option>
                    </select>
                    <InputError :message="form.errors.payment_mode" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('po.observation')" />
                    <textarea v-model="form.observation" rows="2" maxlength="2000" :disabled="pricesOnly"
                        class="mt-1 w-full rounded-lg border-slate-300 shadow-sm disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900"></textarea>
                </div>
            </div>
        </div>

        <div class="space-y-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-6 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('pur.lines') }}</h2>
            <InputError :message="form.errors.lines" />
            <div v-for="(row, index) in form.lines" :key="row.key"
                class="grid grid-cols-1 gap-2 rounded-xl border border-slate-200 p-3 sm:grid-cols-12 sm:items-end dark:border-slate-700">
                <div class="sm:col-span-4">
                    <InputLabel :value="t('pur.catalog_item')" />
                    <SearchableSelect :model-value="row.equipment_catalog_id" :options="catalogOptions" :placeholder="t('pur.catalog_item')"
                        :disabled="pricesOnly" class="mt-1" @update:model-value="pickCatalog(row, $event)" />
                </div>
                <div class="sm:col-span-4">
                    <InputLabel :value="t('designation')" />
                    <TextInput v-model="row.designation" maxlength="255" required :disabled="pricesOnly" class="mt-1 w-full" />
                    <InputError :message="lineError(index, 'designation')" class="mt-1" />
                </div>
                <div class="sm:col-span-1">
                    <InputLabel :value="t('quantity')" />
                    <TextInput v-model="row.quantity" type="number" min="1" step="1" required :disabled="pricesOnly" class="mt-1 w-full" />
                    <InputError :message="lineError(index, 'quantity')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel :value="t('unit_price')" />
                    <TextInput v-model="row.unit_price" type="number" min="0" step="0.01" class="mt-1 w-full" />
                    <InputError :message="lineError(index, 'unit_price')" class="mt-1" />
                </div>
                <div class="flex items-center justify-between gap-2 sm:col-span-1 sm:justify-end">
                    <span class="text-sm tabular-nums text-slate-600 sm:hidden dark:text-slate-300">
                        <bdi v-if="amount(row) !== null" dir="ltr">{{ formatMoney(amount(row)) }}</bdi>
                    </span>
                    <IconButton v-if="!pricesOnly && form.lines.length > 1" icon="trash" :label="t('remove')" variant="danger" size="sm" @click="removeLine(index)" />
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <IconButton v-if="!pricesOnly" icon="plus" :label="t('pur.add_line')" @click="addLine" />
                <p class="ms-auto text-sm font-semibold text-slate-900 dark:text-slate-100">
                    {{ t('total') }}:
                    <bdi v-if="total !== null" dir="ltr" class="tabular-nums">{{ formatMoney(total) }}</bdi>
                    <span v-else class="text-xs font-normal text-slate-500 dark:text-slate-400">{{ t('pur.unpriced') }}</span>
                </p>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="isEdit ? route('purchase-orders.show', order.id) : route('purchase-orders.index')"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('po.save_draft') }}</PrimaryButton>
        </div>
    </form>
</template>
