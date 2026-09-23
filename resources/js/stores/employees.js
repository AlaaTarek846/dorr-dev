import { defineStore } from 'pinia';

export const useEmployeesStore = defineStore('employees', {
    state: () => ({
        total: null,
        activeCount: null,
        inactiveCount: null,
    }),

    actions: {
        setCounts({ total = null, active = null, inactive = null }) {
            if (total != null) {
                this.total = total;
            }

            if (active != null) {
                this.activeCount = active;
            }

            if (inactive != null) {
                this.inactiveCount = inactive;
            }
        },
    },
});
