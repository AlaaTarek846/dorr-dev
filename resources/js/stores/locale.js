import { defineStore } from 'pinia';
import { setI18nLocale } from '../plugins/i18n';
import {
    applyDocumentDirection,
    hasStoredLocalePreference,
    persistLocale,
    resolveInitialLocale,
} from '../utils/direction';
import { useAvailableLanguagesStore } from './availableLanguages';

const KNOWN_I18N_LOCALES = ['ar', 'en'];

export const useLocaleStore = defineStore('locale', {
    state: () => ({
        locale: resolveInitialLocale(),
        initialized: false,
    }),

    getters: {
        direction(state) {
            const languagesStore = useAvailableLanguagesStore();
            const language = languagesStore.findByCode(state.locale);

            if (language?.direction) {
                return language.direction;
            }

            return state.locale === 'ar' ? 'rtl' : 'ltr';
        },
        isRtl() {
            return this.direction === 'rtl';
        },
        label: (state) => {
            const languagesStore = useAvailableLanguagesStore();
            const language = languagesStore.findByCode(state.locale);

            return language?.name ?? state.locale.toUpperCase();
        },
        flagCode: (state) => {
            const languagesStore = useAvailableLanguagesStore();
            const language = languagesStore.findByCode(state.locale);

            return language?.flag?.code ?? null;
        },
    },

    actions: {
        async ensureValidLocale() {
            if (this.initialized) {
                return;
            }

            const languagesStore = useAvailableLanguagesStore();
            await languagesStore.fetch();

            if (! languagesStore.items.length) {
                return;
            }

            if (hasStoredLocalePreference()) {
                const stored = languagesStore.findByCode(this.locale);

                if (stored) {
                    this.applyLocale(stored.code, stored.direction, true);

                    return;
                }

                localStorage.removeItem('admin_locale');
                localStorage.removeItem('admin_direction');
            }

            const fallback = languagesStore.defaultDashboard ?? languagesStore.items[0];

            if (fallback) {
                this.applyLocale(fallback.code, fallback.direction, false);
            }

            this.initialized = true;
        },

        setLocale(localeCode) {
            const languagesStore = useAvailableLanguagesStore();
            const language = languagesStore.findByCode(localeCode);

            if (! language) {
                return;
            }

            this.applyLocale(language.code, language.direction, true);
        },

        applyLocale(localeCode, direction, persist = true) {
            this.locale = localeCode;
            applyDocumentDirection(direction, localeCode, persist);

            const i18nLocale = KNOWN_I18N_LOCALES.includes(localeCode) ? localeCode : 'en';

            setI18nLocale(i18nLocale);
        },

        toggleLocale() {
            const languagesStore = useAvailableLanguagesStore();
            const codes = languagesStore.storableLocales;

            if (codes.length < 2) {
                return;
            }

            const currentIndex = codes.indexOf(this.locale);
            const nextIndex = currentIndex === -1 ? 0 : (currentIndex + 1) % codes.length;

            this.setLocale(codes[nextIndex]);
        },
    },
});
