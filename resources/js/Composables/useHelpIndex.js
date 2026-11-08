import { ref } from 'vue';
import { normalize } from '@/lib/navigation';

// Help articles for the quick search. Fetched once, the first time the palette
// opens — never on page load. Module-level so every palette shares it.
const entries = ref([]);
let pending = null;

export function useHelpIndex() {
    function load() {
        pending ??= window.axios.get(route('help.search-index'))
            .then(({ data }) => {
                entries.value = data.articles.map((a) => ({
                    label: a.title,
                    href: a.href,
                    icon: 'help',
                    haystack: normalize([a.title, a.summary, ...a.keywords, ...a.headings].join(' ')),
                }));
            })
            .catch(() => { pending = null; });
        return pending;
    }

    return { entries, load };
}
