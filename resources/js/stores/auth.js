import { defineStore } from 'pinia';

const TOKEN_KEY = 'admin_token';

function normalizePermissionNames(value) {
    if (! Array.isArray(value)) {
        return [];
    }

    return value.filter((name) => typeof name === 'string' && name !== '');
}

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem(TOKEN_KEY),
        admin: null,
        permission_names: [],
    }),

    getters: {
        isAuthenticated: (state) => Boolean(state.token),

        permissionNames: (state) => state.permission_names,

        /** Route guards (same shape as legacy `permission` array). */
        permission: (state) => state.permission_names,

        hasPermission: (state) => (name) => state.permission_names.includes(name),
    },

    actions: {
        setSession({ token, admin = undefined }) {
            if (token !== undefined) {
                this.token = token;
            }

            if (admin !== undefined) {
                if (admin === null) {
                    this.admin = null;
                    this.permission_names = [];
                } else {
                    if (Array.isArray(admin.permission_names)) {
                        this.permission_names = normalizePermissionNames(admin.permission_names);
                    } else if (Object.prototype.hasOwnProperty.call(admin, 'permission_names')) {
                        this.permission_names = [];
                    }

                    this.admin = {
                        ...admin,
                        permission_names: this.permission_names,
                    };
                }
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

        async refreshSession(adminAxios) {
            if (! this.token) {
                return;
            }

            const { data } = await adminAxios.get('/api/admin/v1/me');

            this.setSession({
                token: this.token,
                admin: data.data,
            });
        },
    },
});
