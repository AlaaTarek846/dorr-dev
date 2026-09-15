export const BOOTSTRAP_LTR = '/dashboard/assets/libs/bootstrap/css/bootstrap.min.css';
export const BOOTSTRAP_RTL = '/dashboard/assets/libs/bootstrap/css/bootstrap.rtl.min.css';
const LOCALE_KEY = 'admin_locale';
const DIRECTION_KEY = 'admin_direction';

export function getStoredLocale() {
    return localStorage.getItem(LOCALE_KEY) ?? (localStorage.getItem('ynexrtl') ? 'ar' : 'en');
}

export function getStoredDirection() {
    const storedDirection = localStorage.getItem(DIRECTION_KEY);

    if (storedDirection === 'rtl' || storedDirection === 'ltr') {
        return storedDirection;
    }

    return getStoredLocale() === 'ar' ? 'rtl' : 'ltr';
}

export function persistLocale(localeCode, direction) {
    localStorage.setItem(LOCALE_KEY, localeCode);
    localStorage.setItem(DIRECTION_KEY, direction);
}

export function syncBootstrapStylesheet() {
    const styleLink = document.getElementById('style');

    if (! styleLink) {
        return;
    }

    const expected = getStoredDirection() === 'rtl' ? BOOTSTRAP_RTL : BOOTSTRAP_LTR;
    const current = styleLink.getAttribute('href') ?? '';

    if (current.endsWith(expected) || current === expected) {
        return;
    }

    styleLink.href = expected;
}

export function applyDocumentDirection(direction, localeCode = null) {
    const html = document.documentElement;
    const isRtl = direction === 'rtl';
    const lang = localeCode ?? getStoredLocale();

    html.setAttribute('dir', direction);
    html.setAttribute('lang', lang);

    persistLocale(lang, direction);

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

    syncBootstrapStylesheet();

    if (typeof window.checkOptions === 'function') {
        window.checkOptions();
    }

    window.dispatchEvent(new Event('resize'));
}
