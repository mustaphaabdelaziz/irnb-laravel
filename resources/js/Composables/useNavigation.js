import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan.js';
import { visibleSections } from '@/lib/navigation';

// The one menu definition, shared by the sidebar (wide and narrow) and the
// quick search, so a page can never be listed in one and missing in the other.
// Every item declares the `module` that gates it (see lib/navigation.js).
export function useNavigation() {
    const { t } = useI18n();
    const page = usePage();
    const { can, isSuperadmin } = useCan();

    const url = computed(() => page.url ?? '');

    const sections = computed(() => {
        const pendingApprovals = page.props.pendingApprovals ?? 0;

        const raw = [
            { key: 'dashboard', standalone: true, items: [
                { label: t('dashboard'), href: '/dashboard', icon: 'dashboard', prefix: '/dashboard', always: true },
            ] },
            { key: 'members', label: t('nav_members'), icon: 'players', items: [
                { label: t('players'), href: '/players', icon: 'players', prefix: '/players', module: 'players' },
                { label: t('subscriptions'), href: '/subscriptions', icon: 'subscriptions', prefix: '/subscriptions', module: 'subscriptions' },
                { label: t('attendance'), href: '/attendance', icon: 'calendar', prefix: '/attendance', module: 'attendance' },
            ] },
            { key: 'finance', label: t('nav_finance'), icon: 'money', items: [
                { label: t('transactions'), href: '/transactions', icon: 'transactions', prefix: '/transactions', module: 'transactions' },
                { label: t('finance'), href: '/finance', icon: 'money', prefix: '/finance', module: 'finance' },
            ] },
            { key: 'equipment', label: t('nav_equipment'), icon: 'equipment', items: [
                { label: t('equipments'), href: '/equipment/catalogs', icon: 'equipment', prefix: '/equipment/catalogs', module: 'equipment' },
                { label: t('equipment_out'), href: '/equipment/out', icon: 'box', prefix: '/equipment/out', module: 'equipment' },
                { label: t('inventory'), href: '/equipment/stocktake', icon: 'clipboard', prefix: '/equipment/stocktake', module: 'inventory' },
            ] },
            { key: 'board', label: t('nav.board_of_directors'), icon: 'board', items: [
                { label: t('nav.board_overview'), href: '/board', icon: 'board', prefix: '/board', exact: true, module: 'board' },
                { label: t('calendar'), href: '/board/calendar', icon: 'calendar', prefix: '/board/calendar', module: 'board' },
                { label: t('meetings'), href: '/board/meetings', icon: 'clipboard', prefix: '/board/meetings', module: 'board' },
                { label: t('tasks'), href: '/board/tasks', icon: 'task', prefix: '/board/tasks', module: 'board' },
            ] },
            { key: 'config', label: t('nav.config'), icon: 'wrench', items: [
                { label: t('categories'), href: '/categories', icon: 'categories', prefix: '/categories', module: 'categories' },
                { label: t('branches'), href: '/branches', icon: 'categories', prefix: '/branches', module: 'categories' },
                { label: t('positions'), href: '/positions', icon: 'positions', prefix: '/positions', module: 'categories' },
                { label: t('player_statuses'), href: '/player-statuses', icon: 'positions', prefix: '/player-statuses', module: 'categories' },
                { label: t('document_types'), href: '/document-types', icon: 'document', prefix: '/document-types', module: 'categories' },
                { label: t('jobs'), href: '/jobs', icon: 'jobs', prefix: '/jobs', module: 'categories' },
                { label: t('board_roles'), href: '/board-roles', icon: 'board', prefix: '/board-roles', module: 'board' },
                { label: t('equipment_categories'), href: '/equipment-categories', icon: 'equipment', prefix: '/equipment-categories', module: 'categories' },
                { label: t('storage_locations'), href: '/storage-locations', icon: 'location', prefix: '/storage-locations', module: 'categories' },
            ] },
            { key: 'system', label: t('nav.system'), icon: 'settings', items: [
                { label: t('users'), href: '/users', icon: 'members', prefix: '/users', match: /^\/users(?!\/(\d+\/)?activity)/, badge: pendingApprovals, module: 'users' },
                { label: t('activity.title'), href: '/users/activity', icon: 'task', prefix: '/users/activity', match: /^\/users\/(\d+\/)?activity/, module: 'users' },
                { label: t('roles'), href: '/roles', icon: 'flag', prefix: '/roles', superadminOnly: true },
                { label: t('settings'), href: '/settings', icon: 'settings', prefix: '/settings', module: 'settings' },
                { label: t('backup'), href: '/backups', icon: 'archive', prefix: '/backups', superadminOnly: true, desktopOnly: true },
            ] },
        ];

        return visibleSections(raw, {
            can,
            isSuperadmin: isSuperadmin.value,
            isDesktop: page.props.isDesktop ?? false,
        });
    });

    return { sections, url };
}
