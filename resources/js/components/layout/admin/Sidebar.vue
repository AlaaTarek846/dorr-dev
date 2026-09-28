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

                    <li v-if="isAiVisible" class="slide__category">
                        <span class="category-name">{{ t('sidebar.ai') }}</span>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item" @click.prevent="toggleSubMenu">
                            <i class="ri-settings-3-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai_groups.core') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-settings' }" class="side-menu__item">
                                    {{ t('sidebar.ai_items.settings') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-plans.index' }" class="side-menu__item">
                                    {{ t('ai_plans.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-subscriptions.index' }" class="side-menu__item">
                                    {{ t('ai_subscriptions.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-usage-sessions.index' }" class="side-menu__item">
                                    {{ t('ai_usage_sessions.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-trial-control.index' }" class="side-menu__item">
                                    {{ t('ai_trial_control.title') }}
                                </router-link>
                            </li>
                        </ul>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item" @click.prevent="toggleSubMenu">
                            <i class="ri-route-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai_groups.routing') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-intents.index' }" class="side-menu__item">
                                    {{ t('ai_intents.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-routing-policies.index' }" class="side-menu__item">
                                    {{ t('ai_routing_policies.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-gateways.index' }" class="side-menu__item">
                                    {{ t('ai_gateways.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-routing-rules.index' }" class="side-menu__item">
                                    {{ t('ai_routing_rules.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-feature-flags.index' }" class="side-menu__item">
                                    {{ t('ai_feature_flags.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-domain-policies.index' }" class="side-menu__item">
                                    {{ t('ai_domain_policies.title') }}
                                </router-link>
                            </li>
                        </ul>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item" @click.prevent="toggleSubMenu">
                            <i class="ri-shield-check-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai_groups.safety') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-safety-policies.index' }" class="side-menu__item">
                                    {{ t('ai_safety_policies.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-safety-rules.index' }" class="side-menu__item">
                                    {{ t('ai_safety_rules.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-safety-events.index' }" class="side-menu__item">
                                    {{ t('ai_safety_events.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-safety-scans.index' }" class="side-menu__item">
                                    {{ t('ai_safety_scans.title') }}
                                </router-link>
                            </li>
                        </ul>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item" @click.prevent="toggleSubMenu">
                            <i class="ri-lock-2-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai_groups.security') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-security-policies.index' }" class="side-menu__item">
                                    {{ t('ai_security_policies.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-data-policies.index' }" class="side-menu__item">
                                    {{ t('ai_data_policies.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-provider-data-rules.index' }" class="side-menu__item">
                                    {{ t('ai_provider_data_rules.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-security-events.index' }" class="side-menu__item">
                                    {{ t('ai_security_events.title') }}
                                </router-link>
                            </li>
                        </ul>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item" @click.prevent="toggleSubMenu">
                            <i class="ri-exchange-2-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai_groups.requests') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-requests.index' }" class="side-menu__item">
                                    {{ t('ai_requests.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-responses.index' }" class="side-menu__item">
                                    {{ t('ai_responses.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-usage.index' }" class="side-menu__item">
                                    {{ t('ai_usage.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-verifications.index' }" class="side-menu__item">
                                    {{ t('ai_verifications.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-code-executions.index' }" class="side-menu__item">
                                    {{ t('ai_code_executions.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-audit-events.index' }" class="side-menu__item">
                                    {{ t('ai_audit_events.title') }}
                                </router-link>
                            </li>
                        </ul>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item" @click.prevent="toggleSubMenu">
                            <i class="ri-chat-3-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai_groups.conversations') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-conversation-contexts.index' }" class="side-menu__item">
                                    {{ t('ai_conversation_contexts.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-conversation-attachments.index' }" class="side-menu__item">
                                    {{ t('ai_conversation_attachments.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-conversation-instructions.index' }" class="side-menu__item">
                                    {{ t('ai_conversation_instructions.title') }}
                                </router-link>
                            </li>
                        </ul>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item" @click.prevent="toggleSubMenu">
                            <i class="ri-pulse-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai_groups.monitoring') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-provider-logs.index' }" class="side-menu__item">
                                    {{ t('ai_provider_logs.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-provider-health.index' }" class="side-menu__item">
                                    {{ t('ai_provider_health.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-failovers.index' }" class="side-menu__item">
                                    {{ t('ai_failovers.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-reliability-metrics.index' }" class="side-menu__item">
                                    {{ t('ai_reliability_metrics.title') }}
                                </router-link>
                            </li>
                        </ul>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item" @click.prevent="toggleSubMenu">
                            <i class="ri-translate-2 side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai_groups.language') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-languages.index' }" class="side-menu__item">
                                    {{ t('ai_languages.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-locales.index' }" class="side-menu__item">
                                    {{ t('ai_locales.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-language-variants.index' }" class="side-menu__item">
                                    {{ t('ai_language_variants.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-user-language-preferences.index' }" class="side-menu__item">
                                    {{ t('ai_user_language_preferences.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-language-evaluations.index' }" class="side-menu__item">
                                    {{ t('ai_language_evaluations.title') }}
                                </router-link>
                            </li>
                        </ul>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item" @click.prevent="toggleSubMenu">
                            <i class="ri-folder-open-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai_groups.knowledge') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-files.index' }" class="side-menu__item">
                                    {{ t('ai_files.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-file-processing.index' }" class="side-menu__item">
                                    {{ t('ai_file_processing.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-knowledge-sources.index' }" class="side-menu__item">
                                    {{ t('ai_knowledge_sources.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-knowledge-chunks.index' }" class="side-menu__item">
                                    {{ t('ai_knowledge_chunks.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-document-generations.index' }" class="side-menu__item">
                                    {{ t('ai_document_generations.title') }}
                                </router-link>
                            </li>
                        </ul>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item" @click.prevent="toggleSubMenu">
                            <i class="ri-folder-3-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai_groups.projects') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-project-instructions.index' }" class="side-menu__item">
                                    {{ t('ai_project_instructions.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-project-context.index' }" class="side-menu__item">
                                    {{ t('ai_project_context.title') }}
                                </router-link>
                            </li>
                        </ul>
                    </li>

                    <li v-if="isAiVisible" class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item" @click.prevent="toggleSubMenu">
                            <i class="ri-flask-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('sidebar.ai_groups.benchmark') }}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-benchmark-cases.index' }" class="side-menu__item">
                                    {{ t('ai_benchmark_cases.title') }}
                                </router-link>
                            </li>
                            <li class="slide">
                                <router-link :to="{ name: 'admin.ai-benchmark-runs.index' }" class="side-menu__item">
                                    {{ t('ai_benchmark_runs.title') }}
                                </router-link>
                            </li>
                        </ul>
                    </li>

                    <template v-if="showSystemUsersSection">
                        <li class="slide__category">
                            <span class="category-name">{{ t('sidebar.users') }}</span>
                        </li>

                        <li class="slide">
                            <router-link :to="{ name: 'admin.users.index' }" class="side-menu__item">
                                <i class="ri-group-line side-menu__icon"></i>
                                <span class="side-menu__label">{{ t('users.title') }}</span>
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
import { computed, watch } from 'vue';
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
    () => isSystemUsersVisible.value && can('users.view'),
);

/** Providers nav has no permission gate yet; section shows if it or service categories are visible. */
const showServicesSection = computed(() => can('service_categories.view') || true);

const showCatalogSection = computed(
    () => can('flags.view')
        || can('countries.view')
        || can('currencies.view')
        || can('languages.view'),
);

const showStaffSection = computed(
    () => can('admins.view') || can('roles.view'),
);

const showSettingsSection = computed(
    () => can('dashboard_themes.view') || can('platform_settings.view'),
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
