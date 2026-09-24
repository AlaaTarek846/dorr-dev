import { defineStore } from 'pinia';
import adminAxios from '../api/adminAxios';
import { applyDocumentBranding } from '../utils/platformBranding';

const EMPTY_BRANDING = {
    app_name: '',
    logo: null,
    logo_dark: null,
    favicon_ico: null,
    favicon_16: null,
    favicon_32: null,
    apple_touch_icon: null,
    web_manifest: null,
};

export const usePlatformBrandingStore = defineStore('platformBranding', {
    state: () => ({
        loaded: false,
        loading: false,
        ...EMPTY_BRANDING,
    }),

    actions: {
        applyPayload(payload = {}) {
            this.app_name = payload.app_name ?? '';
            this.logo = payload.logo ?? null;
            this.logo_dark = payload.logo_dark ?? null;
            this.favicon_ico = payload.favicon_ico ?? null;
            this.favicon_16 = payload.favicon_16 ?? null;
            this.favicon_32 = payload.favicon_32 ?? null;
            this.apple_touch_icon = payload.apple_touch_icon ?? null;
            this.web_manifest = payload.web_manifest ?? null;
        },

        applyToDocument() {
            applyDocumentBranding(this.$state);
        },

        hydrateFromWindow() {
            const payload = window.__PLATFORM_BRANDING__;

            if (! payload || typeof payload !== 'object') {
                return;
            }

            this.applyPayload(payload);
            this.applyToDocument();
            this.loaded = true;
        },

        async fetch(force = false) {
            if (this.loading) {
                return;
            }

            if (this.loaded && ! force) {
                return;
            }

            this.loading = true;

            try {
                const { data } = await adminAxios.get('/api/general/v1/platform-settings/branding');
                this.applyPayload(data.data ?? {});
                this.applyToDocument();
                this.loaded = true;
            } finally {
                this.loading = false;
            }
        },

        async ensureLoaded() {
            this.hydrateFromWindow();
            await this.fetch(! this.loaded);
        },
    },
});
