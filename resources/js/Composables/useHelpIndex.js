import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { normalize } from '@/lib/navigation';

// Help articles for the quick search. Fetched once, the first time the palette
// opens — never on page load. Module-level so every palette shares it.
const entries = ref([]);
let pending = null;
let loadedScope = null;

export function useHelpIndex() {
    const page = usePage();

    function load() {
        // Answers depend on locale + user: refetch when either changed.
        const scope = `${page.props.locale}:${page.props.auth?.user?.id ?? 'guest'}`;
        if (loadedScope !== scope) {
            loadedScope = scope;
            entries.value = [];
            pending = null;
        }
        pending ??= window.axios.get(route('help.search-index'))
            .then(({ data }) => {
                entries.value = data.articles.map((a) => ({
                    label: a.title,
                    href: a.href,
                    icon: 'help',
                    haystack: normalize([a.title, a.summary, ...a.keywords, ...a.headings].join(' ')),
                }));
            })
            .catch(() => { pending = null; loadedScope = null; });
        return pending;
    }

    return { entries, load };
}
