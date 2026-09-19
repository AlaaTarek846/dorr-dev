import { themeAsset } from '../dashboard/themeContext';

export function bootstrapStylesheetUrls() {
    return {
        ltr: themeAsset('assets/libs/bootstrap/css/bootstrap.min.css'),
        rtl: themeAsset('assets/libs/bootstrap/css/bootstrap.rtl.min.css'),
    };
}
const LOCALE_KEY = 'admin_locale';
const DIRECTION_KEY = 'admin_direction';

export function getDefaultDashboardLocale() {
    return window.__DEFAULT_DASHBOARD_LOCALE__ ?? null;
}

export function hasStoredLocalePreference() {
    return localStorage.getItem(LOCALE_KEY) !== null;
}

export function getStoredLocale() {
    return localStorage.getItem(LOCALE_KEY);
}

export function resolveInitialLocale() {
    return getStoredLocale()
        ?? getDefaultDashboardLocale()?.code
        ?? 'en';
}

export function getStoredDirection() {
    const storedDirection = localStorage.getItem(DIRECTION_KEY);

    if (storedDirection === 'rtl' || storedDirection === 'ltr') {
        return storedDirection;
    }

    const defaultDirection = getDefaultDashboardLocale()?.direction;

    if (defaultDirection === 'rtl' || defaultDirection === 'ltr') {
        return defaultDirection;
    }

    return resolveInitialLocale() === 'ar' ? 'rtl' : 'ltr';
}

export function persistLocale(localeCode, direction) {
    localStorage.setItem(LOCALE_KEY, localeCode);
    localStorage.setItem(DIRECTION_KEY, direction);
}

export function syncBootstrapStylesheet(direction = null) {
    const styleLink = document.getElementById('style');

    if (! styleLink) {
        return;
    }

    const resolvedDirection = direction ?? getStoredDirection();
    const { ltr, rtl } = bootstrapStylesheetUrls();
    const expected = resolvedDirection === 'rtl' ? rtl : ltr;
    const current = styleLink.getAttribute('href') ?? '';

    if (current.endsWith(expected) || current === expected) {
        return;
    }

    styleLink.href = expected;
}

export function applyDocumentDirection(direction, localeCode = null, persist = true) {
    const html = document.documentElement;
    const isRtl = direction === 'rtl';
    const lang = localeCode ?? resolveInitialLocale();

    html.setAttribute('dir', direction);
    html.setAttribute('lang', lang);

    if (persist) {
        persistLocale(lang, direction);
    }

    if (isRtl) {
        localStorage.setItem('ynexrtl', 'true');
        localStorage.removeItem('ynexltr');
    } else {
        localStorage.removeItem('ynexrtl');
        localStorage.setItem('ynexltr', 'true');
    }

    const rtlInput = document.querySelector('#switcher-rtl');
    const ltrInput = document.querySelector('#switcher-ltr');

    if (rtlInput) {
        rtlInput.checked = isRtl;
    }

    if (ltrInput) {
        ltrInput.checked = ! isRtl;
    }

    syncBootstrapStylesheet(direction);

    if (typeof window.checkOptions === 'function') {
        window.checkOptions();
    }

    window.dispatchEvent(new Event('resize'));
}
