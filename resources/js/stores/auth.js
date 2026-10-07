import { defineStore } from 'pinia';

const TOKEN_KEY = 'admin_token';

function normalizePermissionNames(value) {
    if (! Array.isArray(value)) {
        return [];
    }

    return value.filter((name) => typeof name === 'string' && name !== '');
}

/** The /me request in flight, shared by everyone who asks for the profile at the same time (route guard, header, profile page). */
let meRequest = null;

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

        /**
         * The signed-in admin and their permissions, from `GET /api/admin/v1/me` — asked for once. Anyone who needs the
         * profile calls this: while the request is on its way they all get that same request, and once the profile is in
         * the store it is not fetched again (pass `force` to ask the server anyway).
         */
        async loadMe(adminAxios, { force = false } = {}) {
            if (! this.token) {
                return null;
            }

            if (! force && this.admin) {
                return this.admin;
            }

            if (! meRequest) {
                meRequest = adminAxios.get('/api/admin/v1/me')
                    .then(({ data }) => {
                        this.setSession({ token: this.token, admin: data.data });

                        return this.admin;
                    })
                    .finally(() => {
                        meRequest = null;
                    });
            }

            return meRequest;
        },

        async refreshSession(adminAxios) {
            // The route guard asks when the permissions are not known yet (a login response may carry the admin without
            // them), so it forces the fetch — but still joins a /me that is already on its way instead of sending another.
            await this.loadMe(adminAxios, { force: true });
        },
    },
});
