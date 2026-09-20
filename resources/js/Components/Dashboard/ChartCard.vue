<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

/**
 * The frame every chart sits in.
 *
 * Carries the three states a chart actually has — loading, empty, and drawn —
 * plus a table view. The table is not a nicety: three colours in the palette
 * sit below 3:1 contrast on the light surface, and the rule that comes with
 * them is that the values must be reachable as text.
 */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    empty: { type: Boolean, default: false },
    emptyHint: { type: String, default: null },
    loading: { type: Boolean, default: false },
    height: { type: String, default: 'h-64' },
    hasTable: { type: Boolean, default: false },
});

const { t } = useI18n();
const showTable = ref(false);
</script>

<template>
    <Card class="border-border/70 shadow-none">
        <CardHeader class="flex-row items-start justify-between gap-3 space-y-0 px-5 pb-2 pt-4">
            <div class="min-w-0">
                <CardTitle class="text-base">{{ title }}</CardTitle>
                <CardDescription v-if="subtitle" class="mt-0.5">{{ subtitle }}</CardDescription>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <slot name="actions" />
                <Button
                    v-if="hasTable && !empty"
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground"
                    :aria-pressed="showTable"
                    @click="showTable = !showTable"
                >
                    <Icon :name="showTable ? 'dashboard' : 'clipboard'" />
                    <span class="sr-only">{{ t('dashboard.toggle_table') }}</span>
                </Button>
            </div>
        </CardHeader>

        <CardContent class="px-5 pb-5">
            <div v-if="loading" :class="height" class="animate-pulse rounded-lg bg-muted/60" />

            <div
                v-else-if="empty"
                :class="height"
                class="flex flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-border text-center"
            >
                <p class="text-sm font-medium text-muted-foreground">{{ t('dashboard.no_data') }}</p>
                <p v-if="emptyHint" class="max-w-xs text-xs text-muted-foreground/80">{{ emptyHint }}</p>
            </div>

            <div v-else-if="showTable" class="max-h-72 overflow-auto rounded-lg border border-border/70">
                <slot name="table" />
            </div>

            <div v-else :class="height">
                <slot />
            </div>
        </CardContent>
    </Card>
</template>
