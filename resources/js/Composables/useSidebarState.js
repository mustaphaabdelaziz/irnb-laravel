import { computed, ref } from 'vue';
import { readJson, writeJson } from '@/lib/safeStorage';

const SECTIONS_KEY = 'sidebar.sections';
const NARROW_KEY = 'sidebar.narrow';

// Module scope: the layout remounts on every Inertia visit, and the sidebar
// must not forget what the user opened.
const stored = readJson(SECTIONS_KEY, {});
const userState = ref(stored && typeof stored === 'object' ? stored : {});
const narrow = ref(readJson(NARROW_KEY, false) === true);

// The narrow rail is a desktop-width layout; the mobile drawer is always wide.
const lgQuery = typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia('(min-width: 1024px)')
    : null;
const isLg = ref(lgQuery?.matches ?? true);
lgQuery?.addEventListener('change', (e) => { isLg.value = e.matches; });

export function useSidebarState() {
    const compact = computed(() => narrow.value && isLg.value);

    function isOpen(key) {
        return userState.value[key] === true;
    }

    function toggleSection(key) {
        userState.value = { ...userState.value, [key]: !isOpen(key) };
        writeJson(SECTIONS_KEY, userState.value);
    }

    function toggleNarrow() {
        narrow.value = !narrow.value;
        writeJson(NARROW_KEY, narrow.value);
    }

    return { isOpen, toggleSection, compact, toggleNarrow };
}
