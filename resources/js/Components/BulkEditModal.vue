<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    show: Boolean,
    /** Ids of the selected rows. */
    ids: { type: Array, default: () => [] },
    /** Route name to POST to. */
    action: { type: String, required: true },
    /**
     * Editable fields: { key, label, options: [{value,label}], multiple? }
     * A `multiple` field offers replace/add/remove, since setting a
     * many-to-many is ambiguous.
     */
    fields: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const form = useForm({ ids: [], field: '', value: null, mode: 'replace' });

const activeField = computed(() => props.fields.find((f) => f.key === form.field) ?? null);
const isMultiple = computed(() => !!activeField.value?.multiple);

// Switching field invalidates whatever value was picked for the previous one.
watch(() => form.field, () => {
    form.value = isMultiple.value ? [] : null;
    form.mode = 'replace';
    form.clearErrors();
});

watch(() => props.show, (open) => {
    if (open) {
        form.reset();
        form.field = props.fields[0]?.key ?? '';
    }
});

function submit() {
    form
        .transform((data) => ({ ...data, ids: props.ids }))
        .post(route(props.action), {
            preserveScroll: true,
            onSuccess: () => { emit('saved'); emit('close'); },
        });
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="emit('close')">
            <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('bulk_edit') }}</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ t('bulk_edit_hint', { count: ids.length }) }}
                </p>

                <form @submit.prevent="submit" class="mt-4 space-y-3">
                    <div>
                        <InputLabel :value="t('field')" />
                        <select v-model="form.field" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option v-for="f in fields" :key="f.key" :value="f.key">{{ f.label }}</option>
                        </select>
                    </div>

                    <!-- Many-to-many: "set" is ambiguous, so say which. -->
                    <div v-if="isMultiple" class="grid grid-cols-3 gap-2">
                        <button v-for="m in ['replace', 'attach', 'detach']" :key="m" type="button"
                            @click="form.mode = m"
                            :class="form.mode === m ? 'bg-primary-100 border-primary-500 text-primary-800' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300'"
                            class="rounded-lg border px-2 py-2 text-xs font-medium transition-colors">
                            {{ t('bulk_mode_' + m) }}
                        </button>
                    </div>

                    <div v-if="activeField">
                        <InputLabel :value="activeField.label" />

                        <div v-if="isMultiple" class="mt-2 flex flex-wrap gap-2">
                            <label v-for="o in activeField.options" :key="o.value"
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm"
                                :class="form.value?.includes(o.value) ? 'border-primary-500 bg-primary-50 text-primary-800' : 'border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300'">
                                <input type="checkbox" :value="o.value" v-model="form.value" class="hidden" />
                                {{ o.label }}
                            </label>
                        </div>

                        <select v-else v-model="form.value"
                            class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <!-- Clearing a field is a legitimate bulk edit. -->
                            <option :value="null">— {{ t('none') }} —</option>
                            <option v-for="o in activeField.options" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>

                        <p v-if="form.errors.value" class="mt-1 text-sm text-rose-600">{{ form.errors.value }}</p>
                    </div>

                    <!-- A bulk edit is not individually undoable, so name the damage. -->
                    <p class="rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                        {{ t('bulk_edit_warning', { count: ids.length }) }}
                    </p>

                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="emit('close')" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">
                            {{ t('cancel') }}
                        </button>
                        <PrimaryButton :disabled="form.processing || !ids.length">{{ t('save') }}</PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>
