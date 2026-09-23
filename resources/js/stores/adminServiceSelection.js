import { defineStore } from 'pinia';
import { useAuthStore } from './auth';

const STORAGE_KEY = 'admin_selected_service_id';

function readStoredSelection() {
    const raw = localStorage.getItem(STORAGE_KEY);

    return raw ? String(raw) : null;
}

export const useAdminServiceSelectionStore = defineStore('adminServiceSelection', {
    state: () => ({
        selectedServiceId: readStoredSelection(),
    }),

    getters: {
        services() {
            const admin = useAuthStore().admin;

            return admin?.services ?? [];
        },

        selectedService(state) {
            if (! this.services.length) {
                return null;
            }

            return this.services.find(
                (service) => String(service.id) === String(state.selectedServiceId),
            ) ?? this.services[0];
        },
    },

    actions: {
        replaceSelection(services) {
            const matchesCurrent = (services ?? []).some(
                (service) => String(service.id) === String(this.selectedServiceId),
            );

            if (! matchesCurrent) {
                if (services?.length) {
                    this.selectService(services[0].id);
                } else {
                    this.selectService(null);
                }
            }
        },

        selectService(serviceId) {
            if (serviceId === null || serviceId === undefined) {
                this.selectedServiceId = null;
                localStorage.removeItem(STORAGE_KEY);

                return;
            }

            this.selectedServiceId = String(serviceId);
            localStorage.setItem(STORAGE_KEY, this.selectedServiceId);
        },
    },
});
