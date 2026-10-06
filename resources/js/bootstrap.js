import axios from 'axios';
import { router } from '@inertiajs/vue3';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Direct axios writes (job quick-create, injuries) bypass Inertia's own
// visits, so drop the prefetch cache here too. The 30-minute backup heartbeat
// POST /backups/tick flushes as well; that is rare enough to accept.
const flushAfterWrite = (config) => {
    if (config?.method && config.method.toLowerCase() !== 'get') router.flushAll();
};
window.axios.interceptors.response.use(
    (response) => {
        flushAfterWrite(response.config);
        return response;
    },
    (error) => {
        flushAfterWrite(error?.config);
        return Promise.reject(error);
    },
);
