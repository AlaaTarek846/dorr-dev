import { createI18n } from 'vue-i18n';
import adminAxios from '../api/adminAxios';
import ar from '../locales/ar.json';
import en from '../locales/en.json';
import { resolveInitialLocale } from '../utils/direction';

const BUNDLED_LOCALES = ['ar', 'en'];
const loadedVersions = {};

const i18n = createI18n({
    legacy: false,
    locale: resolveInitialLocale(),
    fallbackLocale: 'en',
    messages: {
        ar,
        en,
    },
});

export function setI18nLocale(locale) {
    i18n.global.locale.value = locale;
}

export function isBundledLocale(locale) {
    return BUNDLED_LOCALES.includes(String(locale ?? '').toLowerCase());
}

/**
 * ar/en ship in the bundle; any other language comes from its published dashboard translation.
 * Missing keys fall back to en. Resolves false when the language has nothing published.
 */
export async function loadLocaleMessages(locale, version = null) {
    const code = String(locale ?? '').toLowerCase();

    if (! code || isBundledLocale(code)) {
        return true;
    }

    if (code in loadedVersions && (version === null || loadedVersions[code] === version)) {
        return true;
    }

    try {
        const { data } = await adminAxios.get(`/api/general/v1/translations/${encodeURIComponent(code)}/vue`);

        i18n.global.setLocaleMessage(code, data?.data?.messages ?? {});
        loadedVersions[code] = data?.data?.version ?? version;

        return true;
    } catch {
        return false;
    }
}

export default i18n;
