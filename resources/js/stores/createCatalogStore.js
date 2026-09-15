import { defineStore } from 'pinia';

/**
 * Pinia store factory for catalog entity counts (sidebar / filters).
 *
 * @example
 * export const useCountriesStore = createCatalogStore('countries');
 */
export function createCatalogStore(id) {
    return defineStore(id, {
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
}
