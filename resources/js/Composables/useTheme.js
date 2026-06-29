import { ref } from 'vue';

// Module-level singleton so every toggle/indicator in the app stays in sync.
// The initial value mirrors the class the no-flash <head> script already set.
const isDark = ref(
    typeof document !== 'undefined' && document.documentElement.classList.contains('dark'),
);

function apply(dark) {
    isDark.value = dark;
    if (typeof document !== 'undefined') {
        document.documentElement.classList.toggle('dark', dark);
    }
    try {
        localStorage.setItem('theme', dark ? 'dark' : 'light');
    } catch (e) {
        // localStorage unavailable (private mode / SSR) — choice just won't persist.
    }
}

export function useTheme() {
    return {
        isDark,
        toggle: () => apply(!isDark.value),
        apply,
    };
}
