import { onBeforeUnmount, onMounted, ref } from 'vue';
import { isDark } from '@/lib/chartTheme';

/**
 * chartTheme reads the theme at the moment options are built, so a chart
 * drawn in light mode stayed light after a toggle. Read `themeVersion.value`
 * inside each chart computed: it changes only when dark mode actually flips,
 * and the charts rebuild in the new colours.
 */
export function useChartTheme() {
    const themeVersion = ref(0);
    let observer = null;
    let dark = false;

    onMounted(() => {
        dark = isDark();
        observer = new MutationObserver(() => {
            if (isDark() !== dark) {
                dark = isDark();
                themeVersion.value += 1;
            }
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    });

    onBeforeUnmount(() => observer?.disconnect());

    return { themeVersion };
}
