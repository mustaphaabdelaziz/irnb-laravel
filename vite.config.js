import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    build: {
        rollupOptions: {
            output: {
                // The small shared building blocks (top-level components, the
                // sidebar, composables, helpers) ship as one file instead of one
                // file each: the desktop app's server answers one request at a
                // time, so the first visit of a page queued 20–25 tiny files
                // behind each other. Feature components (Competitions/,
                // Finance/…), the big single-page ones and anything chart-related
                // keep their own files, so they still load only where used.
                manualChunks(id) {
                    const shared = /[\\/]resources[\\/]js[\\/](Components[\\/](Sidebar[\\/])?[^\\/]+|Composables[\\/][^\\/]+|lib[\\/][^\\/]+)$/;
                    const apart = /(StatDoughnut|PlayerDocumentsCard|CategoryManager|BulkEditModal)\.vue$|(chartTheme|registerCharts|attendanceStats)\.js$/;
                    if (shared.test(id) && !apart.test(id)) {
                        return 'ui';
                    }
                },
            },
        },
    },
});
