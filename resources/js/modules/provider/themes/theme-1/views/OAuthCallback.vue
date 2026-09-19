<template>
    <div class="row authentication authentication-basic mx-0 justify-content-center align-items-center min-vh-100">
        <div class="col-xxl-4 col-xl-5 col-lg-6 col-md-7 col-sm-10 col-12">
            <div class="p-5 text-center">
                <div class="mb-3">
                    <PlatformLogo href="/provider" variant="auth" centered />
                </div>

                <div v-if="processing" class="py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">{{ t('user_auth.oauth_processing') }}</span>
                    </div>
                    <p class="mt-3 text-muted">{{ t('user_auth.oauth_processing') }}</p>
                </div>

                <div v-else-if="errorMessage" class="py-4">
                    <div class="alert alert-danger">{{ errorMessage }}</div>
                    <router-link :to="{ name: 'provider.login' }" class="btn btn-primary">
                        {{ t('sign_in') }}
                    </router-link>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import providerAxios from '../../../../../api/providerAxios';
import PlatformLogo from '../../../../../components/layout/PlatformLogo.vue';
import { useProviderAuthStore } from '../../../../../stores/providerAuth';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const authStore = useProviderAuthStore();

const processing = ref(true);
const errorMessage = ref('');

onMounted(async () => {
    const status = String(route.query.status ?? '');

    if (status === 'error') {
        processing.value = false;
        errorMessage.value = String(route.query.message ?? t('user_auth.oauth_failed'));
        return;
    }

    if (status === 'needs_verification') {
        await router.replace({
            name: 'provider.verify-email',
            query: {
                flow_token: route.query.flow_token,
                email: route.query.email,
            },
        });
        return;
    }

    if (status === 'needs_password') {
        await router.replace({
            name: 'provider.create-password',
            query: {
                flow_token: route.query.flow_token,
            },
        });
        return;
    }

    if (status === 'success' && route.query.token) {
        try {
            authStore.setSession({ token: String(route.query.token) });
            const { data } = await providerAxios.get('/api/provider/v1/me');
            authStore.setSession({ provider: data.data });
            await router.replace({ name: 'provider.dashboard' });
        } catch {
            processing.value = false;
            errorMessage.value = t('user_auth.oauth_failed');
        }
        return;
    }

    processing.value = false;
    errorMessage.value = t('user_auth.oauth_failed');
});
</script>
