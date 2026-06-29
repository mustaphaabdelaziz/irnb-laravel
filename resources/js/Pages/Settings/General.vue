<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref, watch } from 'vue';

const { t } = useI18n();

const props = defineProps({
    config: Object,
    themePresets: { type: Array, default: () => [] },
    defaultTheme: { type: String, default: 'emerald' },
});

const activeTab = ref('basic');

const tabs = [
    { key: 'basic', label: 'basic_info' },
    { key: 'contact', label: 'contact_information' },
    { key: 'social', label: 'social_media' },
    { key: 'banking', label: 'banking_info' },
    { key: 'legal', label: 'legal_info' },
    { key: 'leadership', label: 'leadership' },
    { key: 'branding', label: 'branding' },
    { key: 'settings', label: 'settings' },
];

// Helper to get a date string from a potential Date object or ISO string
function toDateInput(val) {
    if (!val) return '';
    const d = new Date(val);
    return isNaN(d.getTime()) ? '' : d.toISOString().split('T')[0];
}

const basicForm = useForm({
    club_name_ar: props.config?.club_name?.ar || '',
    club_name_fr: props.config?.club_name?.fr || '',
    club_name_en: props.config?.club_name?.en || '',
    club_short_name: props.config?.club_short_name || '',
    tagline: props.config?.tagline?.ar || (typeof props.config?.tagline === 'string' ? props.config.tagline : ''),
    description: props.config?.description?.ar || (typeof props.config?.description === 'string' ? props.config.description : ''),
    founding_date: toDateInput(props.config?.founding_date),
    founder: props.config?.founder || '',
    motto: props.config?.motto?.ar || (typeof props.config?.motto === 'string' ? props.config.motto : ''),
});

const contactForm = useForm({
    contact_email: props.config?.contact_email || '',
    contact_phone: props.config?.contact_phone || '',
    contact_mobile: props.config?.contact_mobile || '',
    contact_fax: props.config?.contact_fax || '',
    contact_website: props.config?.contact_website || '',
    address_street: props.config?.contact_address?.street || '',
    address_city: props.config?.contact_address?.city || '',
    address_state: props.config?.contact_address?.state || '',
    address_postal_code: props.config?.contact_address?.postal_code || '',
});

const socialForm = useForm({
    facebook: props.config?.social_media?.facebook || '',
    twitter: props.config?.social_media?.twitter || '',
    instagram: props.config?.social_media?.instagram || '',
    youtube: props.config?.social_media?.youtube || '',
});

const bankingForm = useForm({
    bank_name: props.config?.banking_info?.bank_name || '',
    account_holder: props.config?.banking_info?.account_holder || '',
    account_number: props.config?.banking_info?.account_number || '',
    iban: props.config?.banking_info?.iban || '',
    rib: props.config?.banking_info?.rib || '',
    ccp_account: props.config?.banking_info?.ccp?.account_number || '',
    ccp_holder: props.config?.banking_info?.ccp?.holder || '',
    ccp_key: props.config?.banking_info?.ccp?.key || '',
});

const legalForm = useForm({
    registration_number: props.config?.legal_info?.registration_number || '',
    nif: props.config?.legal_info?.nif || '',
    nis: props.config?.legal_info?.nis || '',
    legal_form: props.config?.legal_info?.legal_form || '',
});

const leadershipForm = useForm({
    president: props.config?.leadership?.president || '',
    vice_president: props.config?.leadership?.vice_president || '',
    secretary: props.config?.leadership?.secretary || '',
    treasurer: props.config?.leadership?.treasurer || '',
});

const brandingForm = useForm({
    _method: 'put',
    logo: null,
    favicon: null,
    theme: props.config?.branding?.theme || props.defaultTheme,
    primary_color: props.config?.branding?.primary || '#10b981',
});

// --- Club color theme picker -------------------------------------------------
const selectedTheme = ref(brandingForm.theme);
const customColor = ref(props.config?.branding?.primary || '#2563eb');

function hexToTriplet(hex) {
    const h = hex.replace('#', '');
    return `${parseInt(h.slice(0, 2), 16)} ${parseInt(h.slice(2, 4), 16)} ${parseInt(h.slice(4, 6), 16)}`;
}

// Mirrors App\Support\Theme::generateScale so the live preview matches the save.
function generateScale(hex) {
    const h = hex.replace('#', '');
    const r = parseInt(h.slice(0, 2), 16), g = parseInt(h.slice(2, 4), 16), b = parseInt(h.slice(4, 6), 16);
    const mix = { 50: -0.95, 100: -0.90, 200: -0.78, 300: -0.62, 400: -0.32, 500: 0, 600: 0.12, 700: 0.28, 800: 0.44, 900: 0.58, 950: 0.74 };
    const out = {};
    for (const [stop, f] of Object.entries(mix)) {
        let nr, ng, nb;
        if (f < 0) { const t = -f; nr = r + (255 - r) * t; ng = g + (255 - g) * t; nb = b + (255 - b) * t; }
        else { const t = 1 - f; nr = r * t; ng = g * t; nb = b * t; }
        out[stop] = `#${[nr, ng, nb].map((c) => Math.round(c).toString(16).padStart(2, '0')).join('')}`;
    }
    return out;
}

// Paint the chosen ramp onto :root immediately so the whole UI previews the change.
function applyScale(scaleHexMap) {
    for (const [stop, hex] of Object.entries(scaleHexMap)) {
        document.documentElement.style.setProperty(`--color-primary-${stop}`, hexToTriplet(hex));
    }
}

function scaleFor(key) {
    if (key === 'custom') return generateScale(customColor.value);
    return props.themePresets.find((p) => p.key === key)?.scale || {};
}

// Persist the pick immediately (debounced) so the club color survives a hard
// refresh without a separate "Save" click. Hits a flash-free endpoint.
let themeSaveTimer = null;
function persistTheme() {
    clearTimeout(themeSaveTimer);
    themeSaveTimer = setTimeout(() => {
        router.put(route('settings.theme'), {
            theme: brandingForm.theme,
            primary_color: brandingForm.primary_color,
        }, { preserveScroll: true, preserveState: true });
    }, 350);
}

function selectPreset(key) {
    selectedTheme.value = key;
    brandingForm.theme = key;
    applyScale(scaleFor(key));
    persistTheme();
}

function selectCustom() {
    selectedTheme.value = 'custom';
    brandingForm.theme = 'custom';
    brandingForm.primary_color = customColor.value;
    applyScale(scaleFor('custom'));
    persistTheme();
}

// Keep the form value in sync, and live-preview as the hex is typed while in custom mode.
watch(customColor, (val) => {
    if (!/^#?[0-9a-fA-F]{6}$/.test(val)) return;
    if (val[0] !== '#') customColor.value = '#' + val;
    brandingForm.primary_color = customColor.value;
    if (selectedTheme.value === 'custom') {
        applyScale(scaleFor('custom'));
        persistTheme();
    }
});

const settingsForm = useForm({
    currency: props.config?.settings?.currency || 'DZD',
    timezone: props.config?.settings?.timezone || 'Africa/Algiers',
    default_language: props.config?.settings?.defaultLanguage || props.config?.settings?.default_language || 'ar',
    enable_registration: props.config?.settings?.enableRegistration ?? props.config?.settings?.enable_registration ?? true,
    enable_donations: props.config?.settings?.enableDonations ?? props.config?.settings?.enable_donations ?? true,
    maintenance_mode: props.config?.settings?.maintenanceMode ?? props.config?.settings?.maintenance_mode ?? false,
});

function saveBasic() {
    basicForm.transform(data => ({
        club_name: { ar: data.club_name_ar, fr: data.club_name_fr, en: data.club_name_en },
        club_short_name: data.club_short_name,
        tagline: { ar: data.tagline, fr: data.tagline, en: data.tagline },
        description: { ar: data.description, fr: data.description, en: data.description },
        founding_date: data.founding_date || null,
        founder: data.founder,
        motto: { ar: data.motto, fr: data.motto, en: data.motto },
    })).put(route('settings.update'));
}

function saveContact() {
    contactForm.transform(data => ({
        contact_email: data.contact_email,
        contact_phone: data.contact_phone,
        contact_mobile: data.contact_mobile,
        contact_fax: data.contact_fax,
        contact_website: data.contact_website,
        contact_address: {
            street: data.address_street,
            city: data.address_city,
            state: data.address_state,
            postal_code: data.address_postal_code,
        },
    })).put(route('settings.update'));
}

function saveSocial() {
    socialForm.transform(data => ({
        social_media: {
            facebook: data.facebook,
            twitter: data.twitter,
            instagram: data.instagram,
            youtube: data.youtube,
        },
    })).put(route('settings.update'));
}

function saveBanking() {
    bankingForm.transform(data => ({
        banking_info: {
            bank_name: data.bank_name,
            account_holder: data.account_holder,
            account_number: data.account_number,
            iban: data.iban,
            rib: data.rib,
            ccp: {
                account_number: data.ccp_account,
                holder: data.ccp_holder,
                key: data.ccp_key,
            },
        },
    })).put(route('settings.update'));
}

function saveLegal() {
    legalForm.transform(data => ({
        legal_info: {
            registration_number: data.registration_number,
            nif: data.nif,
            nis: data.nis,
            legal_form: data.legal_form,
        },
    })).put(route('settings.update'));
}

function saveLeadership() {
    leadershipForm.transform(data => ({
        leadership: {
            president: data.president,
            vice_president: data.vice_president,
            secretary: data.secretary,
            treasurer: data.treasurer,
        },
    })).put(route('settings.update'));
}

function saveBranding() {
    brandingForm.post(route('settings.update'), { forceFormData: true });
}

function saveSettings() {
    settingsForm.transform(data => ({
        settings: {
            currency: data.currency,
            currencySymbol: data.currency,
            timezone: data.timezone,
            defaultLanguage: data.default_language,
            enableRegistration: data.enable_registration,
            enableDonations: data.enable_donations,
            maintenanceMode: data.maintenance_mode,
        },
    })).put(route('settings.update'));
}
</script>

<template>
    <Head :title="t('settings')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('settings') }}</h1>
        </template>

        <div class="flex flex-col gap-6 lg:flex-row">
            <!-- Sidebar tabs -->
            <div class="w-full lg:w-56 flex-shrink-0">
                <nav class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
                    <button
                        v-for="tab in tabs" :key="tab.key"
                        @click="activeTab = tab.key"
                        :class="[activeTab === tab.key ? 'bg-primary-50 text-primary-700 font-semibold dark:bg-primary-500/15 dark:text-primary-300' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800']"
                        class="whitespace-nowrap rounded-lg px-4 py-2 text-start text-sm transition-colors">
                        {{ t(tab.label) }}
                    </button>
                </nav>
            </div>

            <!-- Content -->
            <div class="min-w-0 flex-1">
                <!-- Basic Info -->
                <form v-if="activeTab === 'basic'" @submit.prevent="saveBasic" class="space-y-6">
                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('basic_info') }}</h2>
                        <div class="space-y-4">
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <InputLabel value="🇩🇿 اسم النادي (عربي)" />
                                    <TextInput v-model="basicForm.club_name_ar" class="mt-1 w-full" dir="rtl" />
                                </div>
                                <div>
                                    <InputLabel value="🇫🇷 Nom du club (français)" />
                                    <TextInput v-model="basicForm.club_name_fr" class="mt-1 w-full" dir="ltr" />
                                </div>
                                <div>
                                    <InputLabel value="🇬🇧 Club Name (English)" />
                                    <TextInput v-model="basicForm.club_name_en" class="mt-1 w-full" dir="ltr" />
                                </div>
                            </div>
                            <div>
                                <InputLabel :value="t('abbreviation')" />
                                <TextInput v-model="basicForm.club_short_name" class="mt-1 w-48" placeholder="e.g. IRNB" />
                            </div>
                            <div>
                                <InputLabel value="Tagline" />
                                <TextInput v-model="basicForm.tagline" class="mt-1 w-full" />
                            </div>
                            <div>
                                <InputLabel :value="t('description')" />
                                <textarea v-model="basicForm.description" rows="3" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <InputLabel :value="t('founding_date')" />
                                    <TextInput v-model="basicForm.founding_date" type="date" class="mt-1 w-full" />
                                </div>
                                <div>
                                    <InputLabel value="Motto" />
                                    <TextInput v-model="basicForm.motto" class="mt-1 w-full" />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end"><PrimaryButton :disabled="basicForm.processing">{{ t('save') }}</PrimaryButton></div>
                </form>

                <!-- Contact Info -->
                <form v-if="activeTab === 'contact'" @submit.prevent="saveContact" class="space-y-6">
                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('contact_information') }}</h2>
                        <div class="space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div><InputLabel :value="t('email')" /><TextInput v-model="contactForm.contact_email" type="email" class="mt-1 w-full" /></div>
                                <div><InputLabel :value="t('phone')" /><TextInput v-model="contactForm.contact_phone" type="tel" class="mt-1 w-full" /></div>
                                <div><InputLabel value="Mobile" /><TextInput v-model="contactForm.contact_mobile" type="tel" class="mt-1 w-full" /></div>
                                <div><InputLabel value="Website" /><TextInput v-model="contactForm.contact_website" type="url" class="mt-1 w-full" /></div>
                            </div>
                            <hr class="border-slate-200 dark:border-slate-800" />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div><InputLabel :value="t('address')" /><TextInput v-model="contactForm.address_street" class="mt-1 w-full" /></div>
                                <div><InputLabel :value="t('city')" /><TextInput v-model="contactForm.address_city" class="mt-1 w-full" /></div>
                                <div><InputLabel :value="t('state')" /><TextInput v-model="contactForm.address_state" class="mt-1 w-full" /></div>
                                <div><InputLabel value="Code Postal" /><TextInput v-model="contactForm.address_postal_code" class="mt-1 w-full" /></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end"><PrimaryButton :disabled="contactForm.processing">{{ t('save') }}</PrimaryButton></div>
                </form>

                <!-- Social Media -->
                <form v-if="activeTab === 'social'" @submit.prevent="saveSocial" class="space-y-6">
                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('social_media') }}</h2>
                        <div class="space-y-4">
                            <div><InputLabel value="Facebook" /><TextInput v-model="socialForm.facebook" type="url" class="mt-1 w-full" placeholder="https://facebook.com/..." /></div>
                            <div><InputLabel value="Twitter / X" /><TextInput v-model="socialForm.twitter" type="url" class="mt-1 w-full" /></div>
                            <div><InputLabel value="Instagram" /><TextInput v-model="socialForm.instagram" type="url" class="mt-1 w-full" /></div>
                            <div><InputLabel value="YouTube" /><TextInput v-model="socialForm.youtube" type="url" class="mt-1 w-full" /></div>
                        </div>
                    </div>
                    <div class="flex justify-end"><PrimaryButton :disabled="socialForm.processing">{{ t('save') }}</PrimaryButton></div>
                </form>

                <!-- Banking -->
                <form v-if="activeTab === 'banking'" @submit.prevent="saveBanking" class="space-y-6">
                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('banking_info') }}</h2>
                        <div class="space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div><InputLabel value="Bank" /><TextInput v-model="bankingForm.bank_name" class="mt-1 w-full" /></div>
                                <div><InputLabel :value="t('name')" /><TextInput v-model="bankingForm.account_holder" class="mt-1 w-full" /></div>
                                <div><InputLabel value="Numéro de compte" /><TextInput v-model="bankingForm.account_number" class="mt-1 w-full" /></div>
                                <div><InputLabel value="IBAN" /><TextInput v-model="bankingForm.iban" class="mt-1 w-full" /></div>
                                <div><InputLabel value="RIB" /><TextInput v-model="bankingForm.rib" class="mt-1 w-full" /></div>
                            </div>
                            <hr class="border-slate-200 dark:border-slate-800" />
                            <h3 class="text-sm font-medium text-slate-700 dark:text-slate-200">CCP</h3>
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div><InputLabel value="Numéro" /><TextInput v-model="bankingForm.ccp_account" class="mt-1 w-full" /></div>
                                <div><InputLabel value="Titulaire" /><TextInput v-model="bankingForm.ccp_holder" class="mt-1 w-full" /></div>
                                <div><InputLabel value="Clé" /><TextInput v-model="bankingForm.ccp_key" class="mt-1 w-full" /></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end"><PrimaryButton :disabled="bankingForm.processing">{{ t('save') }}</PrimaryButton></div>
                </form>

                <!-- Legal -->
                <form v-if="activeTab === 'legal'" @submit.prevent="saveLegal" class="space-y-6">
                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('legal_info') }}</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><InputLabel value="N° d'enregistrement" /><TextInput v-model="legalForm.registration_number" class="mt-1 w-full" /></div>
                            <div><InputLabel value="NIF" /><TextInput v-model="legalForm.nif" class="mt-1 w-full" /></div>
                            <div><InputLabel value="NIS" /><TextInput v-model="legalForm.nis" class="mt-1 w-full" /></div>
                            <div><InputLabel value="Forme juridique" /><TextInput v-model="legalForm.legal_form" class="mt-1 w-full" /></div>
                        </div>
                    </div>
                    <div class="flex justify-end"><PrimaryButton :disabled="legalForm.processing">{{ t('save') }}</PrimaryButton></div>
                </form>

                <!-- Leadership -->
                <form v-if="activeTab === 'leadership'" @submit.prevent="saveLeadership" class="space-y-6">
                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('leadership') }}</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><InputLabel :value="t('president')" /><TextInput v-model="leadershipForm.president" class="mt-1 w-full" /></div>
                            <div><InputLabel :value="t('vice_president')" /><TextInput v-model="leadershipForm.vice_president" class="mt-1 w-full" /></div>
                            <div><InputLabel :value="t('secretary')" /><TextInput v-model="leadershipForm.secretary" class="mt-1 w-full" /></div>
                            <div><InputLabel :value="t('treasurer')" /><TextInput v-model="leadershipForm.treasurer" class="mt-1 w-full" /></div>
                        </div>
                    </div>
                    <div class="flex justify-end"><PrimaryButton :disabled="leadershipForm.processing">{{ t('save') }}</PrimaryButton></div>
                </form>

                <!-- Branding -->
                <form v-if="activeTab === 'branding'" @submit.prevent="saveBranding" class="space-y-6">
                    <!-- Club color theme -->
                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('club_color') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('club_color_hint') }}</p>

                        <div class="mt-5 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                            <button
                                v-for="preset in themePresets" :key="preset.key" type="button"
                                @click="selectPreset(preset.key)"
                                class="group relative flex flex-col items-center gap-2 rounded-xl border p-3 transition-all"
                                :class="selectedTheme === preset.key ? 'border-slate-900/20 bg-slate-50 ring-2 ring-slate-900/10 dark:border-slate-100/20 dark:bg-slate-950 dark:ring-slate-100/15' : 'border-slate-200 dark:border-slate-800 hover:border-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800'"
                            >
                                <span class="h-9 w-9 rounded-full shadow-inner ring-1 ring-black/10"
                                    :style="{ background: `linear-gradient(135deg, ${preset.scale[400]}, ${preset.scale[600]})` }"></span>
                                <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ preset.name }}</span>
                                <span v-if="selectedTheme === preset.key"
                                    class="absolute end-1.5 top-1.5 flex h-4 w-4 items-center justify-center rounded-full text-white"
                                    :style="{ background: preset.scale[600] }">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5 9-10"/></svg>
                                </span>
                            </button>
                        </div>

                        <!-- Custom club color -->
                        <div class="mt-4 flex flex-wrap items-center gap-3 rounded-xl border p-4"
                            :class="selectedTheme === 'custom' ? 'border-slate-900/20 bg-slate-50 ring-2 ring-slate-900/10 dark:border-slate-100/20 dark:bg-slate-950 dark:ring-slate-100/15' : 'border-slate-200 dark:border-slate-800'">
                            <input type="color" v-model="customColor" @input="selectCustom"
                                class="h-10 w-12 cursor-pointer rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 p-0.5" />
                            <div class="flex-1">
                                <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('custom_color') }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('custom_color_hint') }}</p>
                            </div>
                            <TextInput v-model="customColor" class="w-32 font-mono uppercase" placeholder="#2563EB" maxlength="7" dir="ltr" />
                            <button type="button" @click="selectCustom"
                                class="rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                                :class="selectedTheme === 'custom' ? 'bg-primary-600 text-white' : 'border border-slate-300 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800'">
                                {{ t('use') }}
                            </button>
                        </div>
                        <p class="mt-3 text-xs text-slate-400 dark:text-slate-500">{{ t('preview_live_hint') }}</p>
                    </div>

                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('branding') }}</h2>
                        <div class="space-y-4">
                            <div>
                                <InputLabel value="Logo" />
                                <div v-if="config?.branding?.logo" class="mb-2">
                                    <img :src="config.branding.logo" alt="Logo" class="h-16 w-16 rounded-lg object-contain" />
                                </div>
                                <input type="file" accept="image/*" @change="brandingForm.logo = $event.target.files[0]"
                                    class="text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700" />
                            </div>
                            <div>
                                <InputLabel value="Favicon" />
                                <input type="file" accept="image/*" @change="brandingForm.favicon = $event.target.files[0]"
                                    class="text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700" />
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end"><PrimaryButton :disabled="brandingForm.processing">{{ t('save') }}</PrimaryButton></div>
                </form>

                <!-- Settings -->
                <form v-if="activeTab === 'settings'" @submit.prevent="saveSettings" class="space-y-6">
                    <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                        <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('settings') }}</h2>
                        <div class="space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <InputLabel :value="t('currency')" />
                                    <select v-model="settingsForm.currency" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                        <option value="DZD">DZD - Dinar algérien</option>
                                        <option value="EUR">EUR - Euro</option>
                                        <option value="USD">USD - US Dollar</option>
                                    </select>
                                </div>
                                <div>
                                    <InputLabel :value="t('default_language')" />
                                    <select v-model="settingsForm.default_language" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                        <option value="ar">العربية</option>
                                        <option value="fr">Français</option>
                                        <option value="en">English</option>
                                    </select>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" v-model="settingsForm.enable_registration" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                                    <span class="text-sm text-slate-700 dark:text-slate-200">{{ t('enable_registration') }}</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" v-model="settingsForm.enable_donations" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                                    <span class="text-sm text-slate-700 dark:text-slate-200">{{ t('enable_donations') }}</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" v-model="settingsForm.maintenance_mode" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                                    <span class="text-sm text-slate-700 dark:text-slate-200">{{ t('maintenance_mode') }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end"><PrimaryButton :disabled="settingsForm.processing">{{ t('save') }}</PrimaryButton></div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
