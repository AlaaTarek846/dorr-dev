<template>
    <MarketingSection :config="config" />
</template>

<script setup>
import providerAxios from '../../../../../../api/providerAxios';
import MarketingSection from '../../../../../../components/marketing/MarketingSection.vue';
import { useProviderAuthStore } from '../../../../../../stores/providerAuth';

/**
 * AI Marketing Director in the provider SPA: /api/provider/v1/marketing, the provider's wallet and private channel.
 * The provider API has no realtime-config route, so the shared Echo client (VITE_REVERB_*) carries the pushes.
 */
const auth = useProviderAuthStore();

const config = {
    portal: 'provider',
    http: providerAxios,
    apiBase: '/api/provider/v1/marketing',
    walletBase: '/api/provider/v1/wallet',
    realtimeConfigPath: null,
    tokenKey: 'provider_token',
    mePath: '/api/provider/v1/me',
    channelFor: (id) => `Modules.Provider.Models.Provider.${id}`,
    accountId: () => auth.provider?.id ?? auth.provider?.provider?.id ?? null,
};
</script>
