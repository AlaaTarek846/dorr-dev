import { defineStore } from 'pinia';

const TOKEN_KEY = 'provider_token';

export const useProviderAuthStore = defineStore('providerAuth', {
    state: () => ({
        token: localStorage.getItem(TOKEN_KEY),
        provider: null,
    }),

    getters: {
        isAuthenticated: (state) => Boolean(state.token),
    },

    actions: {
        setSession({ token, provider = null }) {
            if (token !== undefined) {
                this.token = token;
            }

            if (provider !== null) {
                this.provider = provider;
            }

            if (this.token) {
                localStorage.setItem(TOKEN_KEY, this.token);
            } else {
                localStorage.removeItem(TOKEN_KEY);
            }
        },

        logout() {
            this.setSession({ token: null, provider: null });
        },
    },
});
