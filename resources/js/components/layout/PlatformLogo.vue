<template>
    <a :href="href" class="header-logo" :class="linkClass">
        <template v-if="variant === 'auth'">
            <img
                :src="lightSrc"
                alt=""
                class="authentication-brand desktop-logo"
            >
            <img
                :src="darkSrc"
                alt=""
                class="authentication-brand desktop-dark"
            >
        </template>
        <template v-else>
            <img :src="lightSrc" alt="" class="desktop-logo">
            <img :src="lightSrc" alt="" class="toggle-logo">
            <img :src="darkSrc" alt="" class="desktop-dark">
            <img :src="darkSrc" alt="" class="toggle-dark">
            <img :src="lightSrc" alt="" class="desktop-white">
            <img :src="lightSrc" alt="" class="toggle-white">
        </template>
    </a>
</template>

<script setup>
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { usePlatformBrandingStore } from '../../stores/platformBranding';
import { resolveDarkLogo, resolveLightLogo } from '../../utils/platformBranding';

defineProps({
    href: {
        type: String,
        default: '/admin/dashboard',
    },
    linkClass: {
        type: String,
        default: '',
    },
    variant: {
        type: String,
        default: 'dashboard',
    },
});

const { logo, logo_dark: logoDark } = storeToRefs(usePlatformBrandingStore());

const lightSrc = computed(() => resolveLightLogo(logo.value));
const darkSrc = computed(() => resolveDarkLogo(logoDark.value, logo.value));
</script>
