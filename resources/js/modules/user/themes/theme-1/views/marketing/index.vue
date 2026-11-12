<template>
    <MarketingSection :config="config" />
</template>

<script setup>
import userAxios from '../../../../../../api/userAxios';
import MarketingSection from '../../../../../../components/marketing/MarketingSection.vue';
import { useUserAuthStore } from '../../../../../../stores/userAuth';

/** AI Marketing Director in the user SPA: /api/user/v1/marketing, the user's wallet (mobile/v1) and private channel. */
const auth = useUserAuthStore();

const config = {
    portal: 'user',
    http: userAxios,
    apiBase: '/api/user/v1/marketing',
    walletBase: '/api/mobile/v1/wallet',
    realtimeConfigPath: '/api/user/v1/chat/realtime-config',
    tokenKey: 'user_token',
    mePath: '/api/user/v1/me',
    channelFor: (id) => `Modules.User.Models.User.${id}`,
    accountId: () => auth.user?.id ?? auth.user?.user?.id ?? null,
};
</script>
