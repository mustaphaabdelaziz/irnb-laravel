import { compile, registerMessageCompiler } from '@intlify/core-base';
import { createI18n } from 'vue-i18n';
import ar from './ar.json';
import en from './en.json';
import fr from './fr.json';

// vue-i18n 12 (alpha) ships "sideEffects": false, and its entry file only
// re-exports createI18n/useI18n — so the production build tree-shakes away the
// entry's own `registerMessageCompiler(compile)` call. Without a compiler every
// message with a placeholder renders raw ("{age} years", "Used by {count}") in
// the built app (desktop), while the dev server looks fine. Register it here.
registerMessageCompiler(compile);

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
