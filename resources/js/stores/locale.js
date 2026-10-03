import { defineStore } from 'pinia';
import { loadLocaleMessages, setI18nLocale } from '../plugins/i18n';
import {
    applyDocumentDirection,
    getStoredDirection,
    hasStoredLocalePreference,
    persistLocale,
    resolveInitialLocale,
} from '../utils/direction';
import { syncCatalogToggleLabels } from '../utils/catalog';
import { useAvailableLanguagesStore } from './availableLanguages';

function findLanguage(languagesStore, code) {
    return languagesStore.findInterfaceByCode(code) ?? languagesStore.findByCode(code);
}

export const useLocaleStore = defineStore('locale', {
    state: () => ({
        locale: resolveInitialLocale(),
        initialized: false,
    }),

    getters: {
        direction(state) {
            const language = findLanguage(useAvailableLanguagesStore(), state.locale);

            if (language?.direction) {
                return language.direction;
            }

            return getStoredDirection();
        },
        isRtl() {
            return this.direction === 'rtl';
        },
        label: (state) => {
            const language = findLanguage(useAvailableLanguagesStore(), state.locale);

            return language?.name ?? state.locale.toUpperCase();
        },
        flagCode: (state) => {
            const language = findLanguage(useAvailableLanguagesStore(), state.locale);

            return language?.flag?.code ?? null;
        },
    },

    actions: {
        async ensureValidLocale() {
            if (this.initialized) {
                return;
            }

            const languagesStore = useAvailableLanguagesStore();
            await languagesStore.fetchInterface();

            if (! languagesStore.interfaceItems.length) {
                return;
            }

            if (hasStoredLocalePreference()) {
                const stored = languagesStore.findInterfaceByCode(this.locale);

                if (stored && await this.applyLocale(stored.code, stored.direction, true)) {
                    this.initialized = true;

                    return;
                }

                localStorage.removeItem('admin_locale');
                localStorage.removeItem('admin_direction');
            }

            const fallback = languagesStore.defaultInterface;

            if (fallback && ! await this.applyLocale(fallback.code, fallback.direction, false)) {
                await this.applyLocale('en', 'ltr', false);
            }

            this.initialized = true;
        },

        async setLocale(localeCode) {
            const languagesStore = useAvailableLanguagesStore();
            const language = languagesStore.findInterfaceByCode(localeCode);

            if (! language) {
                return false;
            }

            return this.applyLocale(language.code, language.direction, true);
        },

        async applyLocale(localeCode, direction, persist = true) {
            const language = useAvailableLanguagesStore().findInterfaceByCode(localeCode);

            if (! await loadLocaleMessages(localeCode, language?.version ?? null)) {
                return false;
            }

            this.locale = localeCode;
            applyDocumentDirection(direction || language?.direction || 'ltr', localeCode, persist);
            setI18nLocale(localeCode);
            syncCatalogToggleLabels();

            return true;
        },

        toggleLocale() {
            const languagesStore = useAvailableLanguagesStore();
            const codes = languagesStore.interfaceLocales;

            if (codes.length < 2) {
                return;
            }

            const currentIndex = codes.indexOf(this.locale);
            const nextIndex = currentIndex === -1 ? 0 : (currentIndex + 1) % codes.length;

            this.setLocale(codes[nextIndex]);
        },
    },
});
