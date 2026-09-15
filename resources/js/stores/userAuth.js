import { defineStore } from 'pinia';

const TOKEN_KEY = 'user_token';

export const useUserAuthStore = defineStore('userAuth', {
    state: () => ({
        token: localStorage.getItem(TOKEN_KEY),
        user: null,
    }),

    getters: {
        isAuthenticated: (state) => Boolean(state.token),
    },

    actions: {
        setSession({ token, user = null }) {
            if (token !== undefined) {
                this.token = token;
            }

            if (user !== null) {
                this.user = user;
            }

            if (this.token) {
                localStorage.setItem(TOKEN_KEY, this.token);
            } else {
                localStorage.removeItem(TOKEN_KEY);
            }
        },

        logout() {
            this.setSession({ token: null, user: null });
        },
    },
});
