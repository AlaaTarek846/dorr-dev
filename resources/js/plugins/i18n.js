import { createI18n } from 'vue-i18n';
import ar from '../locales/ar.json';
import en from '../locales/en.json';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: {
        ar,
        en,
    },
});

export default i18n;
