import { defineStore } from 'pinia';

const TOKEN_KEY = 'admin_token';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem(TOKEN_KEY),
        admin: null,
    }),

    getters: {
        isAuthenticated: (state) => Boolean(state.token),
    },

    actions: {
        setSession({ token, admin = null }) {
            if (token !== undefined) {
                this.token = token;
            }

            if (admin !== null) {
                this.admin = admin;
            }

            if (this.token) {
                localStorage.setItem(TOKEN_KEY, this.token);
            } else {
                localStorage.removeItem(TOKEN_KEY);
            }
        },

        logout() {
            this.setSession({ token: null, admin: null });
        },
    },
});
