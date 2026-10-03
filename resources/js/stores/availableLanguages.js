import { defineStore } from 'pinia';
import adminAxios from '../api/adminAxios';

let pendingFetch = null;
let pendingInterfaceFetch = null;

export const useAvailableLanguagesStore = defineStore('availableLanguages', {
    state: () => ({
        items: [],
        loaded: false,
        loading: false,
        interfaceItems: [],
        interfaceLoaded: false,
        interfaceLoading: false,
    }),

    getters: {
        storableLocales: (state) => state.items.map((language) => language.code),
        defaultDashboard: (state) => state.items.find((language) => language.is_default_dashboard)
            ?? state.items[0]
            ?? null,
        findByCode: (state) => (code) => state.items.find(
            (language) => String(language.code).toLowerCase() === String(code).toLowerCase(),
        ) ?? null,
        interfaceLocales: (state) => state.interfaceItems.map((language) => language.code),
        defaultInterface: (state) => state.interfaceItems.find((language) => language.is_default_dashboard)
            ?? state.interfaceItems.find((language) => String(language.code).toLowerCase() === 'en')
            ?? state.interfaceItems[0]
            ?? null,
        findInterfaceByCode: (state) => (code) => state.interfaceItems.find(
            (language) => String(language.code).toLowerCase() === String(code).toLowerCase(),
        ) ?? null,
    },

    actions: {
        async fetch(force = false) {
            if (this.loaded && ! force) {
                return this.items;
            }

            if (pendingFetch && ! force) {
                return pendingFetch;
            }

            this.loading = true;

            pendingFetch = (async () => {
                try {
                    const { data } = await adminAxios.get('/api/general/v1/languages/dropdown');

                    this.items = data.data ?? [];
                    this.loaded = true;

                    return this.items;
                } catch {
                    this.items = [];
                    this.loaded = false;

                    return this.items;
                } finally {
                    this.loading = false;
                    pendingFetch = null;
                }
            })();

            return pendingFetch;
        },

        /**
         * Languages the dashboard UI can switch to: ar/en plus languages with a published Vue translation.
         * Content translation tabs keep using `items` (languages that store catalog translations).
         */
        async fetchInterface(force = false) {
            if (this.interfaceLoaded && ! force) {
                return this.interfaceItems;
            }

            if (pendingInterfaceFetch && ! force) {
                return pendingInterfaceFetch;
            }

            this.interfaceLoading = true;

            pendingInterfaceFetch = (async () => {
                try {
                    const { data } = await adminAxios.get('/api/general/v1/translations/languages');

                    this.interfaceItems = data.data ?? [];
                    this.interfaceLoaded = true;

                    return this.interfaceItems;
                } catch {
                    this.interfaceItems = [];
                    this.interfaceLoaded = false;

                    return this.interfaceItems;
                } finally {
                    this.interfaceLoading = false;
                    pendingInterfaceFetch = null;
                }
            })();

            return pendingInterfaceFetch;
        },
    },
});
