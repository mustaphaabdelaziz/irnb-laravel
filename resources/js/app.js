import '../css/app.css';
import './bootstrap';

// Fonts are bundled by Vite (no CDN), so the desktop app renders them offline.
import '@fontsource-variable/inter';
import '@fontsource-variable/cairo';
import '@fontsource-variable/noto-sans-arabic';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { setupI18n, setLocale } from './i18n';
import { localizeNativeValidation } from './lib/nativeValidation';
import { DEFAULT_CLUB_SHORT_NAME } from './Composables/useClubIdentity';

// The tab title's suffix is the club's saved short name, kept current from
// each page's shared props. It used to be VITE_APP_NAME, which is baked in at
// build time and ignores Settings entirely — so every club sold a copy would
// see the build's name in its browser tabs, not its own.
let clubShortName = DEFAULT_CLUB_SHORT_NAME;

const rememberClubName = (pageProps) => {
    clubShortName = pageProps?.appShortName || DEFAULT_CLUB_SHORT_NAME;
};

// Sidebar and quick-search links prefetch pages on hover and keep them for
// 30s. Any write can change what those pages show, so drop the cache before
// a POST/PUT/PATCH/DELETE rather than ever serving a stale page after it.
// flushAll() only clears finished entries, so a prefetch still in flight when
// the write starts would land in the cache afterwards; flushing again when the
// write finishes drops it too.
router.on('before', (event) => {
    if (event.detail.visit.method !== 'get') router.flushAll();
});
router.on('finish', (event) => {
    if (event.detail.visit.method !== 'get') router.flushAll();
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${clubShortName}` : clubShortName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        rememberClubName(props.initialPage.props);

        const locale = props.initialPage.props.locale || 'ar';
        const i18n = setupI18n(locale);
        setLocale(i18n, locale);
        // Required-field bubbles in the app's language, not the browser's.
        localizeNativeValidation(i18n.global.t);

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n);

        // Sync i18n locale on every Inertia navigation
        router.on('navigate', (event) => {
            // Picks up a rename in Settings on the next visit, no reload needed.
            rememberClubName(event.detail.page.props);

            const newLocale = event.detail.page.props.locale;
            if (newLocale && newLocale !== i18n.global.locale.value) {
                setLocale(i18n, newLocale);
            }
        });

        return app.mount(el);
    },
    progress: {
        color: '#10b981',
    },
});
