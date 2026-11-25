/**
 * The product's own name and images — mirrors App\Support\ProductBrand.
 *
 * Every club keeps its own name and logo; ASIREM shows around them (login
 * page, tab title, sidebar footer) and in place of a club logo never set.
 * The artwork has white letters, so each image comes in two: the light one
 * (navy letters) for light mode, the dark one (the original) for dark mode.
 */
export const PRODUCT_NAME = 'ASIREM';

export const PRODUCT_LOGO = {
    light: '/brand/asirem-logo-light.png',
    dark: '/brand/asirem-logo-dark.png',
};

export const PRODUCT_MARK = {
    light: '/brand/asirem-mark-light.png',
    dark: '/brand/asirem-mark-dark.png',
};

/**
 * The release line under the product name — "v 3.11.0" — from the shared
 * `appVersion` prop (NATIVEPHP_APP_VERSION); null when none is set.
 */
export function versionLabel(version) {
    return version ? `v ${version}` : null;
}

/**
 * Tab (and desktop window) title: page, club, product — "Players - FCB · ASIREM".
 * A club that never set a name already reads ASIREM, so it is not said twice.
 */
export function pageTitle(title, clubShortName) {
    const club = clubShortName && clubShortName !== PRODUCT_NAME ? `${clubShortName} · ${PRODUCT_NAME}` : PRODUCT_NAME;

    return title ? `${title} - ${club}` : club;
}
