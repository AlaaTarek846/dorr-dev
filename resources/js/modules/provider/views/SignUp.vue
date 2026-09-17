<template>
    <div>
        <LoginLanguageSelect />

        <div class="mb-3">
            <PlatformLogo href="/provider" variant="auth" />
        </div>

        <p class="h5 fw-semibold mb-2">{{ t('user_auth.sign_up_title') }}</p>
        <p class="mb-3 text-muted op-7 fw-normal">{{ t('user_auth.sign_up_subtitle') }}</p>

        <SocialAuthButtons panel="provider" variant="login" />

        <div class="text-center my-3 authentication-barrier">
            <span>{{ t('user_auth.or_continue_with') }}</span>
        </div>

        <form @submit.prevent="submit">
            <div class="row gy-3">
                <div class="col-xl-12 mt-0">
                    <AuthFormInput
                        input-id="provider-signup-name"
                        v-model="form.name"
                        :label="t('profile.name')"
                        icon="ri-user-line"
                        :placeholder="t('profile.name')"
                        autocomplete="name"
                        :error="errors.name?.[0] ?? ''"
                    />
                </div>

                <div class="col-xl-12">
                    <AuthFormInput
                        input-id="provider-signup-email"
                        v-model="form.email"
                        :label="t('email')"
                        icon="ri-mail-line"
                        type="email"
                        placeholder="provider@example.com"
                        autocomplete="username"
                        :hint="t('user_auth.email_verification_hint')"
                        :error="errors.email?.[0] ?? ''"
                    />
                </div>

                <div v-if="errors.general" class="col-xl-12">
                    <div class="alert alert-danger mb-0">{{ errors.general }}</div>
                </div>

                <div class="col-xl-12 d-grid mt-2">
                    <button type="submit" class="btn btn-lg btn-primary" :disabled="loading">
                        {{ loading ? t('user_auth.creating_account') : t('user_auth.create_account') }}
                    </button>
                </div>

                <div class="col-xl-12 text-center">
                    <span class="text-muted">{{ t('user_auth.already_have_account') }}</span>
                    <router-link :to="{ name: 'provider.login' }" class="text-primary ms-1">
                        {{ t('sign_in') }}
                    </router-link>
                </div>
            </div>
        </form>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import providerAxios from '../../../api/providerAxios';
import AuthFormInput from '../../../components/auth/AuthFormInput.vue';
import LoginLanguageSelect from '../../../components/auth/LoginLanguageSelect.vue';
import SocialAuthButtons from '../../../components/auth/SocialAuthButtons.vue';
import PlatformLogo from '../../../components/layout/PlatformLogo.vue';

const { t } = useI18n();
const router = useRouter();

const loading = ref(false);

const form = reactive({
    name: '',
    email: '',
});

const errors = reactive({
    name: null,
    email: null,
    general: null,
});

function resetErrors() {
    errors.name = null;
    errors.email = null;
    errors.general = null;
}

async function submit() {
    resetErrors();
    loading.value = true;

    try {
        const { data } = await providerAxios.post('/api/provider/v1/register', {
            name: form.name.trim(),
            email: form.email.trim(),
        });

        const cooldownSeconds = data.data?.resend_cooldown_seconds ?? 60;
        sessionStorage.setItem(
            `provider_otp_resend_until:${data.data.flow_token}`,
            String(Date.now() + (cooldownSeconds * 1000)),
        );

        await router.push({
            name: 'provider.verify-email',
            query: {
                flow_token: data.data.flow_token,
                email: data.data.email,
            },
        });
    } catch (error) {
        if (error.response?.status === 422) {
            errors.name = error.response.data.errors?.name ?? null;
            errors.email = error.response.data.errors?.email ?? null;
            errors.general = error.response.data.message ?? null;
        } else {
            errors.general = error.response?.data?.message ?? t('user_auth.sign_up_failed');
        }
    } finally {
        loading.value = false;
    }
}
</script>
