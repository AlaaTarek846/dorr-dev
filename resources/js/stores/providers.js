import { defineStore } from 'pinia';

export const useProvidersStore = defineStore('providers', {
    state: () => ({
        total: null,
        activeCount: null,
        inactiveCount: null,
        blockedCount: null,
    }),

    actions: {
        setCounts({ total = null, active = null, inactive = null, blocked = null }) {
            if (total != null) {
                this.total = total;
            }

            if (active != null) {
                this.activeCount = active;
            }

            if (inactive != null) {
                this.inactiveCount = inactive;
            }

            if (blocked != null) {
                this.blockedCount = blocked;
            }
        },
    },
});
