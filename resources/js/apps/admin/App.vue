<template>
    <router-view />
</template>

<script setup>
import { onMounted } from 'vue';
import { useLocaleStore } from '../../stores/locale';
import { usePlatformBrandingStore } from '../../stores/platformBranding';

onMounted(async () => {
    await Promise.all([
        usePlatformBrandingStore().ensureLoaded(),
        useLocaleStore().ensureValidLocale(),
    ]);
});
</script>
