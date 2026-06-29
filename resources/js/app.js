import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { setupI18n, setLocale } from './i18n';

const appName = import.meta.env.VITE_APP_NAME || 'IRNB';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const locale = props.initialPage.props.locale || 'ar';
        const i18n = setupI18n(locale);
        setLocale(i18n, locale);

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n);

        // Sync i18n locale on every Inertia navigation
        router.on('navigate', (event) => {
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
