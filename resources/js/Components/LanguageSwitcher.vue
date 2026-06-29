<script setup>
import { usePage, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage();

const locales = [
    { code: 'ar', label: 'العربية', flag: '🇩🇿' },
    { code: 'fr', label: 'Français', flag: '🇫🇷' },
    { code: 'en', label: 'English', flag: '🇬🇧' },
];

const currentLocale = page.props.locale;

function switchLocale(code) {
    if (code === currentLocale) return;
    router.get(route('lang.switch', { locale: code }), {}, {
        preserveState: false,
    });
}
</script>

<template>
    <div class="flex items-center gap-1">
        <button
            v-for="loc in locales"
            :key="loc.code"
            @click="switchLocale(loc.code)"
            class="rounded-md px-2 py-1 text-xs font-medium transition-colors"
            :class="currentLocale === loc.code
                ? 'bg-primary-100 text-primary-700'
                : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'"
            :title="loc.label"
        >
            {{ loc.flag }} {{ loc.code.toUpperCase() }}
        </button>
    </div>
</template>
