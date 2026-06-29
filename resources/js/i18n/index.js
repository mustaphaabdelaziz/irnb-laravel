import { createI18n } from 'vue-i18n';
import ar from './ar.json';
import en from './en.json';
import fr from './fr.json';

export function setupI18n(locale = 'ar') {
    const i18n = createI18n({
        legacy: false,
        locale,
        fallbackLocale: 'en',
        messages: { ar, en, fr },
    });

    return i18n;
}

export function setLocale(i18n, locale) {
    i18n.global.locale.value = locale;
    document.documentElement.lang = locale;
    document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
}
