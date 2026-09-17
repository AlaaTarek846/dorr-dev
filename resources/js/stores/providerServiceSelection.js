import { defineStore } from 'pinia';
import { useProviderAuthStore } from './providerAuth';

const STORAGE_KEY = 'provider_selected_service_id';

function readStoredSelection() {
    const raw = localStorage.getItem(STORAGE_KEY);

    return raw ? String(raw) : null;
}

export const useProviderServiceSelectionStore = defineStore('providerServiceSelection', {
    state: () => ({
        selectedServiceId: readStoredSelection(),
    }),

    getters: {
        services() {
            const provider = useProviderAuthStore().provider;

            return provider?.services ?? [];
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
            const currentId = services.some(
                (service) => String(service.id) === String(this.selectedServiceId),
            );

            if (! currentId && services.length) {
                this.selectService(services[0].id);
            }
        },

        selectService(serviceId) {
            this.selectedServiceId = String(serviceId);
            localStorage.setItem(STORAGE_KEY, this.selectedServiceId);
        },
    },
});