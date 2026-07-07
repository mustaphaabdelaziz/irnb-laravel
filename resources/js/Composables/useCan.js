import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function useCan() {
    const page = usePage();

    const isSuperadmin = computed(() => page.props.auth?.isSuperadmin ?? false);
    const permissions = computed(() => page.props.auth?.permissions ?? {});

    function can(module, action) {
        if (isSuperadmin.value) return true;
        return (permissions.value[module] ?? []).includes(action);
    }

    return { can, isSuperadmin, permissions };
}
