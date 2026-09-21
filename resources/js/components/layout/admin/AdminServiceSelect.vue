<template>
    <div ref="rootElement" class="dropdown header-service-select">
        <a
            href="javascript:void(0);"
            class="header-link dropdown-toggle header-service-select__toggle"
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
            <span class="fw-semibold mb-0 lh-1 d-none d-sm-inline mx-1">
                {{ selectedServiceName }}
            </span>
        </a>

        <ul class="dropdown-menu header-service-select__menu dropdown-menu-end py-1">
            <li>
                <button
                    type="button"
                    class="dropdown-item d-flex align-items-center gap-2 py-2"
                    :class="{ active: isSelected(GENERAL_ITEM) }"
                    @click="selectService(GENERAL_ITEM)"
                >
                    <img
                        :src="serviceImage(GENERAL_ITEM)"
                        alt="img"
                        class="header-service-select__option-img"
                    >
                    <span class="flex-grow-1 text-start">{{ serviceName(GENERAL_ITEM) }}</span>
                    <i
                        v-if="isSelected(GENERAL_ITEM)"
                        class="ri-check-line text-success fs-16"
                    ></i>
                </button>
            </li>

            <template v-if="! services.length">
                <li>
                    <span class="dropdown-item-text text-muted py-2">
                        {{ t('admin_services.empty') }}
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
                        :class="{ active: isSelected(service) }"
                        @click="selectService(service)"
                    >
                        <img
                            :src="serviceImage(service)"
                            alt="img"
                            class="header-service-select__option-img"
                        >
                        <span class="flex-grow-1 text-start">{{ serviceName(service) }}</span>
                        <i
                            v-if="isSelected(service)"
                            class="ri-check-line text-success fs-16"
                        ></i>
                    </button>
                </li>
            </template>
        </ul>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import { useAdminServiceSelectionStore } from '../../../stores/adminServiceSelection';

const { t } = useI18n();
const selectionStore = useAdminServiceSelectionStore();
const { selectedService, services } = storeToRefs(selectionStore);

const rootElement = ref(null);

const DEFAULT_SERVICE_IMAGE = '/dashboard/themes/theme-1/assets/images/media/media-1.jpg';
const GENERAL_ITEM = { id: null };

const selectedServiceName = computed(() => {
    if (! selectedService.value) {
        return t('admin_services.general');
    }

    return serviceName(selectedService.value);
});

onMounted(() => {
    selectionStore.fetchServices();
});

function serviceName(service) {
    if (service?.id === null) {
        return t('admin_services.general');
    }

    return service?.name ?? service?.translations?.[0]?.name ?? '';
}

function serviceImage(service) {
    return service?.image ?? DEFAULT_SERVICE_IMAGE;
}

function isSelected(service) {
    if (service?.id === null) {
        return ! selectedService.value;
    }

    return String(service?.id) === String(selectedService.value?.id);
}

function selectService(service) {
    selectionStore.selectService(service?.id ?? null);
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
    width: 2rem;
    height: 2rem;
}

.header-service-select__menu {
    min-width: 13rem;
    max-height: 18rem;
    overflow-y: auto;
    overflow-x: hidden;
    padding-block: 0.35rem;
    scrollbar-width: thin;
    scrollbar-color: rgba(var(--primary-rgb), 0.45) transparent;
}

.header-service-select__menu::-webkit-scrollbar {
    width: 6px;
}

.header-service-select__menu::-webkit-scrollbar-track {
    background: transparent;
}

.header-service-select__menu::-webkit-scrollbar-thumb {
    background-color: rgba(var(--primary-rgb), 0.45);
    border-radius: 999px;
}

.header-service-select__menu::-webkit-scrollbar-thumb:hover {
    background-color: rgba(var(--primary-rgb), 0.7);
}

.header-service-select__menu .dropdown-item.active,
.header-service-select__menu .dropdown-item:active {
    background-color: rgba(var(--primary-rgb, 132, 90, 223), 0.12);
    color: inherit;
}
</style>
