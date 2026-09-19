<template>
    <div class="row authentication mx-0">
        <div class="col-xxl-7 col-xl-7 col-lg-12">
            <div class="row justify-content-center align-items-center h-100">
                <div class="col-xxl-6 col-xl-7 col-lg-7 col-md-7 col-sm-8 col-12">
                    <div class="p-5">
                        <LoginLanguageSelect />

                        <div class="mb-3">
                            <PlatformLogo href="/provider" variant="auth" />
                        </div>

                        <p class="h5 fw-semibold mb-2">{{ t('sign_in') }}</p>
                        <p class="mb-3 text-muted op-7 fw-normal">{{ t('welcome_back') }}</p>

                        <SocialAuthButtons panel="provider" variant="login" />

                        <div class="text-center my-3 authentication-barrier">
                            <span>{{ t('user_auth.or_continue_with') }}</span>
                        </div>

                        <form @submit.prevent="submit">
                            <div class="row gy-3">
                                <div class="col-xl-12 mt-0">
                                    <AuthFormInput
                                        input-id="provider-signin-email"
                                        v-model="form.email"
                                        :label="t('email')"
                                        icon="ri-mail-line"
                                        type="email"
                                        placeholder="provider@example.com"
                                        autocomplete="username"
                                        :error="errors.email?.[0] ?? ''"
                                    />
                                </div>

                                <div class="col-xl-12 mb-3">
                                    <AuthFormInput
                                        input-id="provider-signin-password"
                                        v-model="form.password"
                                        icon="ri-lock-password-line"
                                        placeholder="password"
                                        autocomplete="current-password"
                                        password-toggle
                                        :error="errors.password?.[0] ?? ''"
                                    >
                                        <template #label>
                                            <div
                                                class="d-flex align-items-center justify-content-between mb-2"
                                                :class="{ 'flex-row-reverse': isRtl }"
                                            >
                                                <router-link
                                                    :to="{ name: 'provider.forgot-password' }"
                                                    class="link-danger text-decoration-none fs-12"
                                                >
                                                    {{ t('forget_password') }}
                                                </router-link>
                                                <label for="provider-signin-password" class="form-label text-default mb-0">
                                                    {{ t('password') }}
                                                </label>
                                            </div>
                                        </template>
                                    </AuthFormInput>

                                    <div class="mt-2">
                                        <div class="form-check text-start">
                                            <input
                                                id="provider-remember-password"
                                                v-model="form.remember"
                                                class="form-check-input"
                                                type="checkbox"
                                            >
                                            <label class="form-check-label text-muted fw-normal" for="provider-remember-password">
                                                {{ t('remember_password') }}
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="errors.general" class="col-xl-12">
                                    <div class="alert alert-danger mb-0">
                                        {{ errors.general }}
                                    </div>
                                </div>

                                <div class="col-xl-12 d-grid mt-2">
                                    <button
                                        type="submit"
                                        class="btn btn-lg btn-primary"
                                        :disabled="loading"
                                    >
                                        {{ loading ? t('signing_in') : t('sign_in') }}
                                    </button>
                                </div>

                                <div class="col-xl-12 text-center">
                                    <span class="text-muted">{{ t('user_auth.no_account') }}</span>
                                    <router-link :to="{ name: 'provider.sign-up' }" class="text-primary ms-1">
                                        {{ t('user_auth.sign_up') }}
                                    </router-link>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <AuthCoverAside />
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useLocaleStore } from '../../../../../stores/locale';
import providerAxios from '../../../../../api/providerAxios';
import AuthCoverAside from '../../../../../components/auth/AuthCoverAside.vue';
import AuthFormInput from '../../../../../components/auth/AuthFormInput.vue';
import LoginLanguageSelect from '../../../../../components/auth/LoginLanguageSelect.vue';
import SocialAuthButtons from '../../../../../components/auth/SocialAuthButtons.vue';
import PlatformLogo from '../../../../../components/layout/PlatformLogo.vue';
import { useProviderAuthStore } from '../../../../../stores/providerAuth';

const { t } = useI18n();
const router = useRouter();
const authStore = useProviderAuthStore();
const { isRtl } = storeToRefs(useLocaleStore());

const loading = ref(false);

const form = reactive({
    email: '',
    password: '',
    remember: false,
});

const errors = reactive({
    email: null,
    password: null,
    general: null,
});

function resetErrors() {
    errors.email = null;
    errors.password = null;
    errors.general = null;
}

async function submit() {
    resetErrors();
    loading.value = true;

    try {
        const { data } = await providerAxios.post('/api/provider/v1/login', {
            email: form.email,
            password: form.password,
        });

        authStore.setSession({
            token: data.data.token,
            provider: data.data.provider,
        });

        await router.push({ name: 'provider.dashboard' });
    } catch (error) {
        if (error.response?.status === 422) {
            errors.email = error.response.data.errors?.email ?? null;
            errors.password = error.response.data.errors?.password ?? null;
            errors.general = error.response.data.message ?? null;
        } else {
            errors.general = error.response?.data?.message ?? 'Login failed. Please try again.';
        }
    } finally {
        loading.value = false;
    }
}
</script>
