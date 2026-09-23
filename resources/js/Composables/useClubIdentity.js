import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Mirrors App\Support\ClubIdentity::DEFAULT_SHORT_NAME.
 *
 * The server always shares a resolved name, so this only shows on a page that
 * renders without shared props. Keep the two in step.
 */
export const DEFAULT_CLUB_SHORT_NAME = 'Sports Club';

/**
 * The club's name as the club set it in Settings -> General.
 *
 * One copy of this logic for every layout and page, so the app never falls
 * back to a particular club's name — it is sold to more than one.
 */
export function useClubIdentity() {
    const page = usePage();

    const appShortName = computed(() => page.props.appShortName || DEFAULT_CLUB_SHORT_NAME);

    const appName = computed(() => {
        const name = page.props.appName;
        const locale = page.props.locale || 'ar';

        if (typeof name !== 'object' || name === null) {
            return name || appShortName.value;
        }

        // The club's own name in another language beats a generic default:
        // a club that filled in only Arabic still shows its name to French
        // readers rather than "Sports Club".
        return name[locale] || name.en || name.ar || name.fr || appShortName.value;
    });

    return { appName, appShortName };
}
