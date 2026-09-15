<template>
    <aside class="app-sidebar sticky" id="sidebar">
        <div class="main-sidebar-header">
            <PlatformLogo href="/user/dashboard" />
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
                        <router-link :to="{ name: 'user.dashboard' }" class="side-menu__item">
                            <i class="bx bx-home side-menu__icon"></i>
                            <span class="side-menu__label">{{ t('dashboard') }}</span>
                        </router-link>
                    </li>

                    <li class="slide__category">
                        <span class="category-name">{{ t('profile.title') }}</span>
                    </li>

                    <li class="slide">
                        <router-link :to="{ name: 'user.profile' }" class="side-menu__item">
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
import { useI18n } from 'vue-i18n';
import PlatformLogo from './PlatformLogo.vue';

const { t } = useI18n();
</script>
