<template>
    <div>
        <LoginLanguageSelect />

        <div class="mb-3">
            <PlatformLogo href="/user" variant="auth" />
        </div>

        <p class="h5 fw-semibold mb-2">{{ t('user_auth.forgot_password_title') }}</p>
        <p class="mb-4 text-muted op-7 fw-normal">{{ t('user_auth.forgot_password_subtitle') }}</p>

        <div v-if="sent" class="alert alert-success text-start">
            {{ t('user_auth.reset_link_sent') }}
        </div>

        <form v-else @submit.prevent="submit">
            <div class="row gy-3">
                <div class="col-xl-12 mt-0">
                    <AuthFormInput
                        input-id="user-forgot-email"
                        v-model="form.email"
                        :label="t('email')"
                        icon="ri-mail-line"
                        type="email"
                        placeholder="user@example.com"
                        autocomplete="username"
                        :error="errors.email?.[0] ?? ''"
                    />
                </div>

                <div v-if="errors.general" class="col-xl-12">
                    <div class="alert alert-danger mb-0">{{ errors.general }}</div>
                </div>

                <div class="col-xl-12 d-grid mt-2">
                    <button type="submit" class="btn btn-lg btn-primary" :disabled="loading">
                        {{ loading ? t('user_auth.sending_reset_link') : t('user_auth.send_reset_link') }}
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
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import userAxios from '../../../api/userAxios';
import AuthFormInput from '../../../components/auth/AuthFormInput.vue';
import LoginLanguageSelect from '../../../components/auth/LoginLanguageSelect.vue';
import PlatformLogo from '../../../components/layout/PlatformLogo.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../composables/useToast';

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const loading = ref(false);
const sent = ref(false);

const form = reactive({
    email: '',
});

const errors = reactive({
    email: null,
    general: null,
});

function resetErrors() {
    errors.email = null;
    errors.general = null;
}

async function submit() {
    resetErrors();
    loading.value = true;

    try {
        const { data } = await userAxios.post('/api/user/v1/forgot-password', {
            email: form.email.trim(),
        });

        sent.value = true;
        showSuccess(extractApiMessage(data, t('user_auth.reset_link_sent')));
    } catch (error) {
        if (error.response?.status === 422) {
            errors.email = error.response.data.errors?.email ?? null;
            errors.general = error.response.data.message ?? null;
        } else {
            showError(extractApiErrorMessage(error));
            errors.general = extractApiErrorMessage(error);
        }
    } finally {
        loading.value = false;
    }
}
</script>
