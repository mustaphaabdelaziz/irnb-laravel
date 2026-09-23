import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { setupI18n, setLocale } from './i18n';
import { DEFAULT_CLUB_SHORT_NAME } from './Composables/useClubIdentity';

// The tab title's suffix is the club's saved short name, kept current from
// each page's shared props. It used to be VITE_APP_NAME, which is baked in at
// build time and ignores Settings entirely — so every club sold a copy would
// see the build's name in its browser tabs, not its own.
let clubShortName = DEFAULT_CLUB_SHORT_NAME;

const rememberClubName = (pageProps) => {
    clubShortName = pageProps?.appShortName || DEFAULT_CLUB_SHORT_NAME;
};

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
