import { defineStore } from 'pinia';
import adminAxios from '../api/adminAxios';

let pendingFetch = null;

export const useAvailableLanguagesStore = defineStore('availableLanguages', {
    state: () => ({
        items: [],
        loaded: false,
        loading: false,
    }),

    getters: {
        storableLocales: (state) => state.items.map((language) => language.code),
        defaultDashboard: (state) => state.items.find((language) => language.is_default_dashboard)
            ?? state.items[0]
            ?? null,
        findByCode: (state) => (code) => state.items.find(
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
    },
});
