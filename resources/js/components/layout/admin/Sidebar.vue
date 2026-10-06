<template>
    <aside class="app-sidebar sticky" id="sidebar">
        <div class="main-sidebar-header">
            <PlatformLogo href="/admin/dashboard" />
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
                        <router-link :to="{ name: 'admin.dashboard' }" class="side-menu__item">
                            <i class="bx bx-home side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('dashboard') }}</span>
                        </router-link>
                    </li>

                    <li v-if="isChatVisible" class="slide has-sub">
                        <a
                            href="javascript:void(0);"
                            class="side-menu__item"
                            @click.prevent="toggleSubMenu"
                        >
                            <i class="ri-message-3-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.chat') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide side-menu__label1">
                                <a href="javascript:void(0)">{{ t('sidebar.chat') }}</a>
                            </li>
                            <li v-for="item in chatItems" :key="item" class="slide">
                                <a href="javascript:void(0)" class="side-menu__item">
                                    {{ t(`sidebar.chat_items.${item}`) }}
                                </a>
                            </li>
                        </ul>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a
                            href="javascript:void(0);"
                            class="side-menu__item"
                            @click.prevent="toggleSubMenu"
                        >
                            <i class="ri-robot-2-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide side-menu__label1">
                                <a href="javascript:void(0)">{{ t('sidebar.ai') }}</a>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-settings' }" class="side-menu__item">
                                    {{ t('sidebar.ai_items.settings') }}
                                </router-link>
                            </li>
                            <li v-for="item in aiItems" :key="item" class="slide">
                                <a href="javascript:void(0)" class="side-menu__item">
                                    {{ t(`sidebar.ai_items.${item}`) }}
                                </a>
                            </li>
                        </ul>
                    </li>

                    <template v-if="smsSectionReady && showSmsSection">
                        <li class="slide__category">
                            <span class="category-name">{{ t('sidebar.sms') }}</span>
                        </li>

                        <li class="slide has-sub">
                            <a
                                href="javascript:void(0);"
                                class="side-menu__item"
                                @click.prevent="toggleSubMenu"
                            >
                                <i class="ri-message-2-line side-menu__icon"></i>
                                <span class="side-menu__label">{{ t('sidebar.sms') }}</span>
                                <i class="fe fe-chevron-right side-menu__angle"></i>
                            </a>
                            <ul class="slide-menu child1">
                                <li class="slide side-menu__label1">
                                    <a href="javascript:void(0)">{{ t('sidebar.sms') }}</a>
                                </li>
                                <li v-if="can('sms-providers.view')" class="slide">
                                    <router-link :to="{ name: 'admin.sms.providers.index' }" class="side-menu__item">
                                        {{ t('sidebar.sms_items.providers') }}
                                    </router-link>
                                </li>
                                <li v-if="can('whatsapp.view')" class="slide">
                                    <router-link :to="{ name: 'admin.sms.whatsapp.index' }" class="side-menu__item">
                                        {{ t('sidebar.sms_items.whatsapp') }}
                                    </router-link>
                                </li>
                                <li v-if="can('otp-settings.view')" class="slide">
                                    <router-link :to="{ name: 'admin.sms.otp.index' }" class="side-menu__item">
                                        {{ t('sidebar.sms_items.otp') }}
                                    </router-link>
                                </li>
                            </ul>
                        </li>
                    </template>

                    <template v-if="showSystemUsersSection">
                        <li class="slide__category">
                            <span class="category-name">{{ t('sidebar.users') }}</span>
                        </li>

                        <li v-if="can('users.view')" class="slide">
                            <router-link :to="{ name: 'admin.users.index' }" class="side-menu__item">
                                <i class="ri-group-line side-menu__icon"></i>
                                <span class="side-menu__label">{{ t('users.title') }}</span>
                            </router-link>
                        </li>

                        <li v-if="can('ratings.view')" class="slide">
                            <router-link :to="{ name: 'admin.ratings.index' }" class="side-menu__item">
                                <i class="ri-star-line side-menu__icon"></i>
                                <span class="side-menu__label">{{ t('ratings.title') }}</span>
                            </router-link>
                        </li>
                    </template>

                    <template v-if="isGeneralVisible">
                        <template v-if="showServicesSection">
                            <li class="slide__category">
                                <span class="category-name">{{ t('sidebar.services') }}</span>
                            </li>

                            <li v-if="can('service_categories.view')" class="slide">
                                <router-link :to="{ name: 'admin.service-categories.index' }" class="side-menu__item">
                                    <i class="ri-list-settings-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('service_categories.title') }}</span>
                                </router-link>
                            </li>

                            <li v-if="can('faqs.view')" class="slide">
                                <router-link :to="{ name: 'admin.faqs.index' }" class="side-menu__item">
                                    <i class="ri-question-answer-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('faqs.title') }}</span>
                                </router-link>
                            </li>

                            <li v-if="can('legal-page.view')" class="slide">
                                <router-link :to="{ name: 'admin.legal-pages.index' }" class="side-menu__item">
                                    <i class="ri-file-shield-2-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('legal_pages.title') }}</span>
                                </router-link>
                            </li>

                            <li class="slide">
                                <router-link :to="{ name: 'admin.providers.index' }" class="side-menu__item">
                                    <i class="ri-user-settings-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('providers.title') }}</span>
                                </router-link>
                            </li>
                        </template>

                        <template v-if="showCatalogSection">
                            <li class="slide__category">
                                <span class="category-name">{{ t('sidebar.catalog') }}</span>
                            </li>

                            <li v-if="can('flags.view')" class="slide">
                                <router-link :to="{ name: 'admin.flags.index' }" class="side-menu__item">
                                    <i class="ri-flag-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('flags.title') }}</span>
                                </router-link>
                            </li>

                            <li v-if="can('countries.view')" class="slide">
                                <router-link :to="{ name: 'admin.countries.index' }" class="side-menu__item">
                                    <i class="ri-earth-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('countries.title') }}</span>
                                </router-link>
                            </li>

                            <li v-if="can('currencies.view')" class="slide">
                                <router-link :to="{ name: 'admin.currencies.index' }" class="side-menu__item">
                                    <i class="ri-money-dollar-circle-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('currencies.title') }}</span>
                                </router-link>
                            </li>

                            <li v-if="can('languages.view')" class="slide">
                                <router-link :to="{ name: 'admin.languages.index' }" class="side-menu__item">
                                    <i class="ri-translate-2 side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('languages.title') }}</span>
                                </router-link>
                            </li>
                        </template>

                        <template v-if="showWalletSection">
                            <li class="slide__category">
                                <span class="category-name">{{ t('sidebar.wallet') }}</span>
                            </li>

                            <li v-for="item in walletItems" v-show="can(item.permission)" :key="item.route" class="slide">
                                <router-link :to="{ name: item.route }" class="side-menu__item">
                                    <i :class="`${item.icon} side-menu__icon`"></i>
                                    <span class="side-menu__label">{{ t(item.label) }}</span>
                                </router-link>
                            </li>
                        </template>

                        <template v-if="showChatAdminSection">
                            <li class="slide__category">
                                <span class="category-name">{{ t('sidebar.chat') }}</span>
                            </li>

                            <li v-for="item in chatAdminItems" v-show="can(item.permission)" :key="item.route" class="slide">
                                <router-link :to="{ name: item.route }" class="side-menu__item">
                                    <i :class="`${item.icon} side-menu__icon`"></i>
                                    <span class="side-menu__label">{{ t(item.label) }}</span>
                                </router-link>
                            </li>
                        </template>

                        <template v-if="showStaffSection">
                            <li class="slide__category">
                                <span class="category-name">{{ t('sidebar.staff') }}</span>
                            </li>

                            <li v-if="can('admins.view')" class="slide">
                                <router-link :to="{ name: 'admin.employees.index' }" class="side-menu__item">
                                    <i class="ri-user-settings-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('employees.title') }}</span>
                                </router-link>
                            </li>

                            <li v-if="can('roles.view')" class="slide">
                                <router-link :to="{ name: 'admin.roles.index' }" class="side-menu__item">
                                    <i class="ri-shield-user-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('roles.title') }}</span>
                                </router-link>
                            </li>
                        </template>

                        <template v-if="showSettingsSection">
                            <li class="slide__category">
                                <span class="category-name">{{ t('sidebar.settings') }}</span>
                            </li>

                            <li v-if="can('dashboard_themes.view')" class="slide">
                                <router-link :to="{ name: 'admin.dashboard-themes.index' }" class="side-menu__item">
                                    <i class="ri-palette-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('dashboard_themes.title') }}</span>
                                </router-link>
                            </li>

                            <li v-if="can('platform_settings.view')" class="slide">
                                <router-link :to="{ name: 'admin.platform-settings' }" class="side-menu__item">
                                    <i class="ri-settings-3-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('platform_settings.title') }}</span>
                                </router-link>
                            </li>

                            <li v-if="can('mobile_app_fonts.view')" class="slide">
                                <router-link :to="{ name: 'admin.mobile-app-fonts.index' }" class="side-menu__item">
                                    <i class="ri-font-size-2 side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('mobile_app_fonts.title') }}</span>
                                </router-link>
                            </li>

                            <li v-if="can('mobile_app_color_defaults.view')" class="slide">
                                <router-link :to="{ name: 'admin.mobile-app-colors' }" class="side-menu__item">
                                    <i class="ri-smartphone-line side-menu__icon"></i>
                                    <span class="side-menu__label">{{ t('mobile_app_color_defaults.title') }}</span>
                                </router-link>
                            </li>
                        </template>
                    </template>
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
import { computed, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import PlatformLogo from '../PlatformLogo.vue';
import { useAuthStore } from '../../../stores/auth';
import { useAdminServiceSelectionStore } from '../../../stores/adminServiceSelection';
import { usePermission } from '../../../composables/usePermission';

const { t } = useI18n();
const { can } = usePermission();
const authStore = useAuthStore();
const selectionStore = useAdminServiceSelectionStore();
const { selectedService } = storeToRefs(selectionStore);

const chatItems = ['inbox', 'groups', 'channels', 'archive'];
const aiItems = ['assistant', 'prompts', 'models', 'history'];

const GENERAL_MODULES = new Set([
    'general_services',
    'admin',
    'admin_permission',
]);

const selectedModuleName = computed(() => selectedService.value?.category?.module_name ?? null);
const isChatVisible = computed(() => selectedModuleName.value === 'chat');
const isAiVisible = computed(() => selectedModuleName.value === 'ai_assistant');
const isSystemUsersVisible = computed(() => selectedModuleName.value === 'system_users');

const showSystemUsersSection = computed(
    () => isSystemUsersVisible.value && (can('users.view') || can('ratings.view')),
);

/**
 * The theme's sidebar script (defaultmenu.min.js) binds its own click toggle to every
 * top-level `.slide.has-sub` that exists when it runs — which is after Vue mounts
 * (useDashboard loads it dynamically). Chat/AI dropdowns escape that double-binding
 * because they only render later. The SMS section must wait for the theme scripts to
 * finish loading, otherwise both toggles fire on every click and cancel each other out.
 */
const smsSectionReady = ref(false);

onMounted(() => {
    const reveal = () => {
        smsSectionReady.value = true;
    };

    if (document.documentElement.dataset.dashboardThemeReady !== undefined) {
        reveal();

        return;
    }

    window.addEventListener('dashboard-theme-scripts-ready', reveal, { once: true });
});

const showSmsSection = computed(
    () => can('sms-providers.view') || can('whatsapp.view') || can('otp-settings.view'),
);

/** Providers nav has no permission gate yet; section shows if it or service categories are visible. */
const showServicesSection = computed(() => can('service_categories.view') || true);

const showCatalogSection = computed(
    () => can('flags.view')
        || can('countries.view')
        || can('currencies.view')
        || can('languages.view'),
);

/** Wallet screens (Modules/Wallet); each entry is shown only to admins holding its `.view` permission. */
const walletItems = [
    { route: 'admin.wallet.wallets', permission: 'wallets.view', icon: 'ri-wallet-3-line', label: 'wallet.wallets.title' },
    { route: 'admin.wallet.online-transactions', permission: 'online-transactions.view', icon: 'ri-bank-card-line', label: 'wallet.online.title' },
    { route: 'admin.wallet.withdrawals', permission: 'withdrawal-requests.view', icon: 'ri-hand-coin-line', label: 'wallet.withdrawals.title' },
    { route: 'admin.wallet.pin-recovery', permission: 'pin-recovery-requests.view', icon: 'ri-lock-unlock-line', label: 'wallet.pinrec.title' },
    { route: 'admin.wallet.financial-entries', permission: 'financial-entries.view', icon: 'ri-file-list-3-line', label: 'wallet.ledger.title' },
    { route: 'admin.wallet.payment-methods', permission: 'payment-methods.view', icon: 'ri-secure-payment-line', label: 'wallet.methods.title' },
    { route: 'admin.wallet.fee-rules', permission: 'wallet-fee-rules.view', icon: 'ri-percent-line', label: 'wallet.rules.title' },
    { route: 'admin.wallet.settings', permission: 'wallet-settings.view', icon: 'ri-settings-4-line', label: 'wallet.settings.title' },
];

const showWalletSection = computed(() => walletItems.some((item) => can(item.permission)));

/** Chat screens (Modules/Chat), same rule as the wallet: each entry needs its `.view` permission. */
const chatAdminItems = [
    { route: 'admin.chat.reports', permission: 'chat-reports.view', icon: 'ri-flag-2-line', label: 'chat.reports.title' },
    { route: 'admin.chat.report-types', permission: 'chat-report-types.view', icon: 'ri-list-check-2', label: 'chat.report_types.title' },
    { route: 'admin.chat.moments', permission: 'chat-moments.view', icon: 'ri-cake-2-line', label: 'chat.moments.title' },
    { route: 'admin.chat.dorr-stories', permission: 'chat-dorr-stories.view', icon: 'ri-donut-chart-line', label: 'chat.dorr_stories.title' },
    { route: 'admin.chat.channels', permission: 'chat-channels.view', icon: 'ri-verified-badge-line', label: 'chat.channels.title' },
    { route: 'admin.chat.portals', permission: 'chat-portals.view', icon: 'ri-store-2-line', label: 'chat.portals.title' },
    { route: 'admin.chat.packages', permission: 'chat-packages.view', icon: 'ri-vip-crown-2-line', label: 'chat.packages.title' },
    { route: 'admin.chat.categories', permission: 'chat-categories.view', icon: 'ri-price-tag-3-line', label: 'chat.categories.title' },
    { route: 'admin.chat.themes', permission: 'chat-themes.view', icon: 'ri-palette-line', label: 'chat.themes.title' },
    { route: 'admin.chat.stickers', permission: 'chat-stickers.view', icon: 'ri-emotion-sticker-line', label: 'chat.stickers.title' },
    { route: 'admin.chat.settings', permission: 'chat-settings.view', icon: 'ri-settings-4-line', label: 'chat.settings.title' },
];

const showChatAdminSection = computed(() => chatAdminItems.some((item) => can(item.permission)));

const showStaffSection = computed(
    () => can('admins.view') || can('roles.view'),
);

const showSettingsSection = computed(
    () => can('dashboard_themes.view')
        || can('platform_settings.view')
        || can('mobile_app_fonts.view')
        || can('mobile_app_color_defaults.view'),
);

const isGeneralVisible = computed(() => {
    if (! selectedService.value) {
        return true;
    }

    return GENERAL_MODULES.has(selectedModuleName.value);
});

watch(
    () => authStore.admin?.services,
    (adminServices) => selectionStore.replaceSelection(adminServices ?? []),
    { immediate: true },
);

function toggleSubMenu(event) {
    event.stopImmediatePropagation();
    const toggle = event.currentTarget;
    const submenu = toggle.nextElementSibling;

    if (! submenu) {
        return;
    }

    const isOpen = submenu.style.display === 'block'
        || window.getComputedStyle(submenu).display !== 'none';

    const nav = toggle.closest('.nav');

    nav?.querySelectorAll(':scope > ul > .slide.has-sub > ul').forEach((menu) => {
        if (menu === submenu) {
            return;
        }

        if (menu.style.display === 'block' || window.getComputedStyle(menu).display !== 'none') {
            menu.style.display = 'none';
            menu.closest('.slide.has-sub')?.classList.remove('open');
        }
    });

    submenu.style.display = isOpen ? 'none' : 'block';
    toggle.closest('.slide.has-sub')?.classList.toggle('open', ! isOpen);
}

</script>
