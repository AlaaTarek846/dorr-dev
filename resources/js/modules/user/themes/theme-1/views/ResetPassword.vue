<template>
    <div>
        <LoginLanguageSelect />

        <div class="mb-3">
            <PlatformLogo href="/user" variant="auth" />
        </div>

        <p class="h5 fw-semibold mb-2">{{ t('user_auth.reset_password_title') }}</p>
        <p class="mb-4 text-muted op-7 fw-normal">{{ t('user_auth.reset_password_subtitle') }}</p>

        <form @submit.prevent="submit">
            <div class="row gy-3">
                <div class="col-xl-12 mt-0">
                    <AuthFormInput
                        input-id="user-reset-password"
                        v-model="form.password"
                        :label="t('password')"
                        icon="ri-lock-password-line"
                        placeholder="password"
                        autocomplete="new-password"
                        password-toggle
                        :error="errors.password?.[0] ?? ''"
                    />
                </div>

                <div class="col-xl-12">
                    <AuthFormInput
                        input-id="user-reset-password-confirmation"
                        v-model="form.password_confirmation"
                        :label="t('profile.confirm_password')"
                        icon="ri-lock-line"
                        placeholder="password"
                        autocomplete="new-password"
                        password-toggle
                        :error="errors.password_confirmation?.[0] ?? ''"
                    />
                </div>

                <div v-if="errors.flow_token?.[0]" class="col-xl-12">
                    <div class="alert alert-danger mb-0">{{ errors.flow_token[0] }}</div>
                </div>

                <div v-if="errors.general" class="col-xl-12">
                    <div class="alert alert-danger mb-0">{{ errors.general }}</div>
                </div>

                <div class="col-xl-12 d-grid mt-2">
                    <button type="submit" class="btn btn-lg btn-primary" :disabled="loading">
                        {{ loading ? t('user_auth.resetting_password') : t('user_auth.reset_password') }}
                    </button>
                </div>
            </div>
        </form>

        <div class="text-center mt-4">
            <router-link :to="{ name: 'user.login' }" class="text-primary">
                {{ t('user_auth.back_to_login') }}
            </router-link>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import userAxios from '../../../../../api/userAxios';
import AuthFormInput from '../../../../../components/auth/AuthFormInput.vue';
import LoginLanguageSelect from '../../../../../components/auth/LoginLanguageSelect.vue';
import PlatformLogo from '../../../../../components/layout/PlatformLogo.vue';
import { useUserAuthStore } from '../../../../../stores/userAuth';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const authStore = useUserAuthStore();

const flowToken = ref(String(route.query.flow_token ?? ''));
const loading = ref(false);

const form = reactive({
    password: '',
    password_confirmation: '',
});

const errors = reactive({
    password: null,
    password_confirmation: null,
    flow_token: null,
    general: null,
});

watch(() => route.query.flow_token, (value) => {
    if (value) {
        flowToken.value = String(value);
    }
});

function resetErrors() {
    errors.password = null;
    errors.password_confirmation = null;
    errors.flow_token = null;
    errors.general = null;
}

async function submit() {
    if (! flowToken.value) {
        errors.general = t('user_auth.session_expired');
        return;
    }

    resetErrors();
    loading.value = true;

    try {
        const { data } = await userAxios.post('/api/user/v1/reset-password', {
            flow_token: flowToken.value,
            password: form.password,
            password_confirmation: form.password_confirmation,
        });

        authStore.setSession({
            token: data.data.token,
            user: data.data.user,
        });

        await router.push({ name: 'user.dashboard' });
    } catch (error) {
        if (error.response?.status === 422) {
            errors.password = error.response.data.errors?.password ?? null;
            errors.password_confirmation = error.response.data.errors?.password_confirmation ?? null;
            errors.flow_token = error.response.data.errors?.flow_token ?? null;
            errors.general = error.response.data.message ?? null;
        } else {
            errors.general = error.response?.data?.message ?? t('user_auth.reset_password_failed');
        }
    } finally {
        loading.value = false;
    }
}
</script>
