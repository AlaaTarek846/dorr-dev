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

                    <li class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item">
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

                    <li class="slide has-sub">
                        <a href="javascript:void(0);" class="side-menu__item">
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

                    <li class="slide__category">
                        <span class="category-name">{{ t('sidebar.services') }}</span>
                    </li>

                    <li class="slide">
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

                    <li class="slide__category">
                        <span class="category-name">{{ t('sidebar.catalog') }}</span>
                    </li>

                    <li class="slide">
                        <router-link :to="{ name: 'admin.flags.index' }" class="side-menu__item">
                            <i class="ri-flag-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('flags.title') }}</span>
                        </router-link>
                    </li>

                    <li class="slide">
                        <router-link :to="{ name: 'admin.countries.index' }" class="side-menu__item">
                            <i class="ri-earth-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('countries.title') }}</span>
                        </router-link>
                    </li>

                    <li class="slide">
                        <router-link :to="{ name: 'admin.currencies.index' }" class="side-menu__item">
                            <i class="ri-money-dollar-circle-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('currencies.title') }}</span>
                        </router-link>
                    </li>

                    <li class="slide">
                        <router-link :to="{ name: 'admin.languages.index' }" class="side-menu__item">
                            <i class="ri-translate-2 side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('languages.title') }}</span>
                        </router-link>
                    </li>

                    <li class="slide__category">
                        <span class="category-name">{{ t('sidebar.users') }}</span>
                    </li>

                    <li class="slide">
                        <router-link :to="{ name: 'admin.users.index' }" class="side-menu__item">
                            <i class="ri-group-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('users.title') }}</span>
                        </router-link>
                    </li>

                    <li class="slide__category">
                        <span class="category-name">{{ t('sidebar.settings') }}</span>
                    </li>

                    <li class="slide">
                        <router-link :to="{ name: 'admin.dashboard-themes.index' }" class="side-menu__item">
                            <i class="ri-palette-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('dashboard_themes.title') }}</span>
                        </router-link>
                    </li>

                    <li class="slide">
                        <router-link :to="{ name: 'admin.platform-settings' }" class="side-menu__item">
                            <i class="ri-settings-3-line side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('platform_settings.title') }}</span>
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
import { useI18n } from 'vue-i18n';
import PlatformLogo from './PlatformLogo.vue';

const { t } = useI18n();

const chatItems = ['inbox', 'groups', 'channels', 'archive'];
const aiItems = ['assistant', 'prompts', 'models', 'history'];
</script>
