<template>
    <router-view />
</template>

<script setup>
import { onMounted } from 'vue';
import { useLocaleStore } from './stores/locale';
import { usePlatformBrandingStore } from './stores/platformBranding';

onMounted(async () => {
    const brandingStore = usePlatformBrandingStore();

    await Promise.all([
        brandingStore.ensureLoaded(),
        useLocaleStore().ensureValidLocale(),
    ]);

    if (brandingStore.app_name) {
        document.title = `Provider | ${brandingStore.app_name}`;
    }
});
</script>
