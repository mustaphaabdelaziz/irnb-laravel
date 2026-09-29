/**
 * The browser's own "fill in this field" bubbles speak the operating system's
 * language, not the app's — an Arabic screen would warn in English or French.
 * This swaps each bubble's text for the app's translation, for every form at
 * once: `invalid` fires on the field just before the bubble is shown.
 *
 * @param {(key: string, params?: object) => string} t  the i18n translate function
 */
export function localizeNativeValidation(t) {
    const message = (el) => {
        const v = el.validity;
        const type = (el.type || '').toLowerCase();

        if (v.valueMissing) {
            if (el.tagName === 'SELECT') return t('validation.select_required');
            if (type === 'checkbox' || type === 'radio') return t('validation.check_required');
            if (type === 'file') return t('validation.file_required');
            return t('validation.required');
        }
        if (v.typeMismatch) {
            if (type === 'email') return t('validation.email');
            if (type === 'url') return t('validation.url');
            return t('validation.invalid');
        }
        if (v.badInput) return type === 'number' ? t('validation.number') : t('validation.invalid');
        if (v.tooShort) return t('validation.min_length', { min: el.minLength });
        if (v.tooLong) return t('validation.max_length', { max: el.maxLength });
        if (v.rangeUnderflow) return t('validation.min_value', { min: el.min });
        if (v.rangeOverflow) return t('validation.max_value', { max: el.max });
        if (v.patternMismatch || v.stepMismatch) return t('validation.invalid');
        return '';
    };

    document.addEventListener('invalid', (event) => {
        const el = event.target;
        if (!el?.setCustomValidity) return;
        // Clear our own earlier message first so validity reflects the real problem.
        el.setCustomValidity('');
        if (el.validity.valid) return;
        el.setCustomValidity(message(el));
    }, true);

    // Any edit starts fresh; the browser re-checks on the next submit.
    const reset = (event) => event.target?.setCustomValidity?.('');
    document.addEventListener('input', reset, true);
    document.addEventListener('change', reset, true);
}
