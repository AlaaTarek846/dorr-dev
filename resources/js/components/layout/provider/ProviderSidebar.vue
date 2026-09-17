<template>
    <aside class="app-sidebar sticky" id="sidebar">
        <div class="main-sidebar-header">
            <PlatformLogo href="/provider/dashboard" />
        </div>

        <div class="main-sidebar" id="sidebar-scroll">
            <nav class="main-menu-container nav nav-pills flex-column sub-open">
                <div class="slide-left" id="slide-left">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="#7b8191" width="24" height="24" viewBox="0 0 24 24">
                        <path d="M13.293 6.293 7.586 12l5.707 5.707 1.414-1.414L10.414 12l4.293-4.293z"></path>
                    </svg>
                </div>

                <ul class="main-menu">
                    <li class="slide__category">
                        <span class="category-name">{{ t('sidebar.main') }}</span>
                    </li>

                    <li class="slide">
                        <router-link :to="{ name: 'provider.dashboard' }" class="side-menu__item">
                            <i class="bx bx-home side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('dashboard') }}</span>
                        </router-link>
                    </li>

                    <template v-if="selectedService">
                        <li class="slide__category">
                            <span class="category-name">{{ t('sidebar.services') }}</span>
                        </li>

                        <li class="slide side-menu__label1">
                            <a href="javascript:void(0);" class="side-menu__item">
                                <i class="bx bx-grid-alt side-menu__icon"></i>
                                <span class="side-menu__label">{{ serviceName(selectedService) }}</span>
                            </a>
                        </li>

                        <li
                            v-for="item in serviceLinks"
                            :key="`${serviceModuleName}-${item.key}`"
                            class="slide"
                        >
                            <a
                                href="javascript:void(0);"
                                class="side-menu__item"
                                @click.prevent
                            >
                                <i :class="item.icon" class="side-menu__icon"></i>
                                <span class="side-menu__label">{{ t(`provider_services.links.${item.key}`) }}</span>
                            </a>
                        </li>
                    </template>

                    <li class="slide__category">
                        <span class="category-name">{{ t('profile.title') }}</span>
                    </li>

                    <li class="slide">
                        <router-link :to="{ name: 'provider.profile' }" class="side-menu__item">
                            <i class="ri-user-settings-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('profile.title') }}</span>
                        </router-link>
                    </li>
                </ul>

                <div class="slide-right" id="slide-right">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="#7b8191" width="24" height="24" viewBox="0 0 24 24">
                        <path d="M10.707 17.707 16.414 12l-5.707-5.707-1.414 1.414L13.586 12l-4.293 4.293z"></path>
                    </svg>
                </div>
            </nav>
        </div>
    </aside>
</template>

<script setup>
import { computed, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import PlatformLogo from '../PlatformLogo.vue';
import { useProviderAuthStore } from '../../../stores/providerAuth';
import { useProviderServiceSelectionStore } from '../../../stores/providerServiceSelection';

const LINK_POOL = [
    { key: 'overview', icon: 'bx bx-collection' },
    { key: 'bookings', icon: 'bx bx-calendar-check' },
    { key: 'reports', icon: 'bx bx-bar-chart-alt-2' },
    { key: 'orders', icon: 'bx bx-receipt' },
    { key: 'trips', icon: 'bx bx-car' },
    { key: 'scheduled', icon: 'bx bx-time' },
    { key: 'drivers', icon: 'bx bx-user-pin' },
    { key: 'vehicles', icon: 'bx bx-car-mechanic' },
    { key: 'payments', icon: 'bx bx-wallet' },
    { key: 'invoices', icon: 'bx bx-file' },
    { key: 'settings', icon: 'bx bx-cog' },
    { key: 'support', icon: 'bx bx-headphone' },
];

const LINKS_PER_SERVICE = 4;

const { t } = useI18n();
const providerAuthStore = useProviderAuthStore();
const selectionStore = useProviderServiceSelectionStore();
const { selectedService } = storeToRefs(selectionStore);

const serviceModuleName = computed(() => selectedService.value?.category?.module_name ?? '');

const serviceLinks = computed(() => {
    const seed = hashCode(serviceModuleName.value || 'default');
    const offset = seed % LINK_POOL.length;

    return Array.from({ length: LINKS_PER_SERVICE }, (_, index) => (
        LINK_POOL[(offset + index) % LINK_POOL.length]
    ));
});

function hashCode(value) {
    let hash = 0;

    for (let i = 0; i < value.length; i += 1) {
        hash = (hash * 31 + value.charCodeAt(i)) >>> 0;
    }

    return hash;
}

watch(
    () => providerAuthStore.provider?.services,
    (providerServices) => selectionStore.replaceSelection(providerServices ?? []),
    { immediate: true },
);

function serviceName(service) {
    return service.category?.name ?? service.category?.translations?.[0]?.name ?? '';
}
</script>