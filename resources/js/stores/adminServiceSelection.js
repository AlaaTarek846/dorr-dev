import { defineStore } from 'pinia';
import adminAxios from '../api/adminAxios';

const STORAGE_KEY = 'admin_selected_service_category_id';

function readStoredSelection() {
    const raw = localStorage.getItem(STORAGE_KEY);

    return raw ? String(raw) : null;
}

let pendingFetch = null;

export const useAdminServiceSelectionStore = defineStore('adminServiceSelection', {
    state: () => ({
        services: [],
        selectedServiceId: readStoredSelection(),
        loading: false,
        loaded: false,
    }),

    getters: {
        selectedService(state) {
            if (! state.selectedServiceId) {
                return null;
            }

            return this.services.find(
                (service) => String(service.id) === String(state.selectedServiceId),
            ) ?? null;
        },
    },

    actions: {
        async fetchServices(force = false) {
            if (this.loaded && ! force) {
                return this.services;
            }

            if (pendingFetch && ! force) {
                return pendingFetch;
            }

            this.loading = true;

            pendingFetch = (async () => {
                try {
                    const { data } = await adminAxios.get('/api/admin/v1/service-categories/dropdown');

                    this.services = data.data ?? [];
                    this.loaded = true;
                    this.ensureSelection();

                    return this.services;
                } catch {
                    this.services = [];
                    this.loaded = false;

                    return this.services;
                } finally {
                    this.loading = false;
                    pendingFetch = null;
                }
            })();

            return pendingFetch;
        },

        ensureSelection() {
            const matchesCurrent = this.services.some(
                (service) => String(service.id) === String(this.selectedServiceId),
            );

            if (! matchesCurrent) {
                this.selectService(null);
            }
        },

        selectService(serviceId) {
            this.selectedServiceId = serviceId === null ? null : String(serviceId);

            if (this.selectedServiceId === null) {
                localStorage.removeItem(STORAGE_KEY);
            } else {
                localStorage.setItem(STORAGE_KEY, this.selectedServiceId);
            }
        },
    },
});