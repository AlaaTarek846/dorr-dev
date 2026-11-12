import { defineStore } from 'pinia';

/** Open/close state of the admin screen-guide panel. `routeName` = whose guide is shown. */
export const useAdminGuideStore = defineStore('adminGuide', {
    state: () => ({
        isOpen: false,
        routeName: null,
    }),

    actions: {
        open(routeName) {
            this.routeName = routeName;
            this.isOpen = true;
        },

        close() {
            this.isOpen = false;
        },
    },
});
