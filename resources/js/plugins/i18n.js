import { createI18n } from 'vue-i18n';
import ar from '../locales/ar.json';
import en from '../locales/en.json';
import { getStoredLocale } from '../utils/direction';

const i18n = createI18n({
    legacy: false,
    locale: getStoredLocale(),
    fallbackLocale: 'en',
    messages: {
        ar,
        en,
    },
});

export function setI18nLocale(locale) {
    i18n.global.locale.value = locale;
}

export default i18n;
