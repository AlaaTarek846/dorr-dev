<template>
    <div ref="rootElement" class="dropdown header-service-select">
        <a
            href="javascript:void(0);"
            class="header-link dropdown-toggle header-service-select__toggle"
            :class="{ disabled: ! services.length }"
            data-bs-toggle="dropdown"
            data-bs-auto-close="true"
            aria-expanded="false"
        >
            <template v-if="selectedService">
                <img
                    :src="serviceImage(selectedService)"
                    alt="img"
                    class="header-service-select__toggle-img"
                >
            </template>
            <img
                v-else
                :src="DEFAULT_SERVICE_IMAGE"
                alt="img"
                class="header-service-select__toggle-img"
            >
            <span class="fw-semibold mb-0 lh-1 d-none d-sm-inline">
                {{ selectedServiceName }}
            </span>
        </a>

        <ul class="dropdown-menu header-service-select__menu dropdown-menu-end">
            <template v-if="! services.length">
                <li>
                    <span class="dropdown-item-text text-muted py-2">
                        {{ t('provider_services.empty') }}
                    </span>
                </li>
            </template>

            <template v-else>
                <li
                    v-for="service in services"
                    :key="service.id"
                >
                    <button
                        type="button"
                        class="dropdown-item d-flex align-items-center gap-2 py-2"
                        :class="{ active: isSelected(service.id) }"
                        @click="selectService(service.id)"
                    >
                        <img
                            :src="serviceImage(service)"
                            alt="img"
                            class="header-service-select__option-img"
                        >
                        <span class="flex-grow-1 text-start">{{ serviceName(service) }}</span>
                        <i
                            v-if="isSelected(service.id)"
                            class="ri-check-line text-success fs-16"
                        ></i>
                    </button>
                </li>
            </template>
        </ul>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import { useProviderAuthStore } from '../../../stores/providerAuth';
import { useProviderServiceSelectionStore } from '../../../stores/providerServiceSelection';

const { t } = useI18n();
const providerAuthStore = useProviderAuthStore();
const selectionStore = useProviderServiceSelectionStore();
const { selectedService, services } = storeToRefs(selectionStore);

const rootElement = ref(null);

const DEFAULT_SERVICE_IMAGE = '/dashboard/themes/theme-1/assets/images/media/media-1.jpg';

const selectedServiceName = computed(() => {
    if (! selectedService.value) {
        return t('provider_services.select_service');
    }

    return serviceName(selectedService.value);
});

watch(
    () => providerAuthStore.provider?.services,
    (providerServices) => selectionStore.replaceSelection(providerServices ?? []),
    { immediate: true },
);

function serviceName(service) {
    return service.category?.name ?? service.category?.translations?.[0]?.name ?? '';
}

function serviceImage(service) {
    return service.category?.image ?? DEFAULT_SERVICE_IMAGE;
}

function isSelected(serviceId) {
    return String(serviceId) === String(selectedService.value?.id);
}

function selectService(serviceId) {
    selectionStore.selectService(serviceId);
    closeDropdown();
}

function closeDropdown() {
    const toggle = rootElement.value?.querySelector('[data-bs-toggle="dropdown"]');

    if (! toggle || ! window.bootstrap?.Dropdown) {
        return;
    }

    window.bootstrap.Dropdown.getInstance(toggle)?.hide();
}
</script>

<style scoped>
.header-service-select__toggle.disabled {
    pointer-events: none;
    opacity: 0.65;
}

.header-service-select__toggle-img,
.header-service-select__option-img {
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}

.header-service-select__toggle-img {
    width: 2.25rem;
    height: 2.25rem;
}

.header-service-select__menu {
    min-width: 13rem;
    max-height: 18rem;
    overflow-y: auto;
    padding-block: 0.35rem;
}

.header-service-select__menu .dropdown-item.active,
.header-service-select__menu .dropdown-item:active {
    background-color: rgba(var(--primary-rgb, 132, 90, 223), 0.12);
    color: inherit;
}
</style>