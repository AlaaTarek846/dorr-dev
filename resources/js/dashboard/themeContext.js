const FALLBACK_PATH = 'theme-1';

/**
 * @returns {{ path: string, base: string, slug: string|null }}
 */
export function getDashboardTheme() {
    const fromWindow = window.__DASHBOARD_THEME__;

    if (fromWindow?.path && fromWindow?.base) {
        return {
            path: fromWindow.path,
            base: fromWindow.base.replace(/\/$/, ''),
            slug: fromWindow.slug ?? null,
        };
    }

    const path = FALLBACK_PATH;

    return {
        path,
        base: `/dashboard/themes/${path}`,
        slug: null,
    };
}

export function getThemePath() {
    return getDashboardTheme().path;
}

export function getThemeBase() {
    return getDashboardTheme().base;
}

export function getFallbackThemePath() {
    return FALLBACK_PATH;
}

/**
 * @param {string} relativePath path under theme root, e.g. assets/css/styles.min.css
 */
export function themeAsset(relativePath) {
    const normalized = relativePath.replace(/^\//, '');

    return `${getThemeBase()}/${normalized}`;
}
