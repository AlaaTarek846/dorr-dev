<template>
    <div class="page">
        <div id="loader">
            <img src="/dashboard/assets/images/media/loader.svg" alt="">
        </div>

        <ThemeSwitcher />
        <HeaderModals />

        <Header />
        <ProviderSidebar />

        <div class="main-content app-content">
            <div class="container-fluid">
                <router-view />
            </div>
        </div>

        <Footer />

        <div class="scrollToTop">
            <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
        </div>
        <div id="responsive-overlay"></div>

        <AppToast />
    </div>
</template>

<script setup>
import AppToast from '../components/ui/AppToast.vue';
import Footer from '../components/layout/Footer.vue';
import Header from '../components/layout/Header.vue';
import HeaderModals from '../components/layout/HeaderModals.vue';
import ProviderSidebar from '../components/layout/ProviderSidebar.vue';
import ThemeSwitcher from '../components/layout/ThemeSwitcher.vue';
import { useDashboard } from '../composables/useDashboard';

useDashboard();
</script>
