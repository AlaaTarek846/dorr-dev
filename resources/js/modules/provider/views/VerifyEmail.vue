<template>
    <div class="row authentication authentication-basic mx-0 justify-content-center align-items-center min-vh-100">
        <div class="col-xxl-4 col-xl-5 col-lg-6 col-md-7 col-sm-10 col-12">
            <div class="p-5 text-center">
                <LoginLanguageSelect centered />

                <div class="mb-3">
                    <PlatformLogo href="/provider" variant="auth" centered />
                </div>

                <p class="h5 fw-semibold mb-2">{{ t('user_auth.verify_title') }}</p>
                <p class="mb-4 text-muted op-7 fw-normal">
                    {{ t('user_auth.verify_subtitle', { email: maskedEmail }) }}
                </p>

                <form @submit.prevent="submit">
                    <div class="mb-4">
                        <OtpInput
                            v-model="code"
                            :invalid="Boolean(errors.code?.[0])"
                            input-id="provider-verify"
                        />
                        <div v-if="errors.code?.[0]" class="text-danger fs-12 mt-2">
                            {{ errors.code[0] }}
                        </div>
                        <div v-if="errors.flow_token?.[0]" class="text-danger fs-12 mt-2">
                            {{ errors.flow_token[0] }}
                        </div>
                    </div>

                    <div v-if="errors.general" class="alert alert-danger text-start">
                        {{ errors.general }}
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-lg btn-primary" :disabled="loading || ! codeComplete">
                            {{ loading ? t('user_auth.verifying') : t('user_auth.verify') }}
                        </button>
                    </div>

                    <div>
                        <span class="text-muted">{{ t('user_auth.did_not_receive_code') }}</span>
                        <button
                            v-if="canResend"
                            type="button"
                            class="btn btn-link p-0 ms-1 align-baseline"
                            :disabled="resending"
                            @click="resend"
                        >
                            {{ resending ? t('user_auth.resending') : t('user_auth.resend_code') }}
                        </button>
                        <span v-else class="text-muted ms-1">
                            {{ t('user_auth.resend_in', { seconds: remainingSeconds }) }}
                        </span>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import providerAxios from '../../../api/providerAxios';
import LoginLanguageSelect from '../../../components/auth/LoginLanguageSelect.vue';
import OtpInput from '../../../components/auth/OtpInput.vue';
import PlatformLogo from '../../../components/layout/PlatformLogo.vue';
import { useResendCooldown } from '../../../composables/useResendCooldown';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../composables/useToast';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const { showSuccess, showError } = useToast();

const flowToken = ref(String(route.query.flow_token ?? ''));
const maskedEmail = ref(String(route.query.email ?? ''));
const code = ref('');
const loading = ref(false);
const resending = ref(false);
const { remainingSeconds, canResend, startCooldown } = useResendCooldown(flowToken);

const errors = reactive({
    code: null,
    flow_token: null,
    general: null,
});

const codeComplete = computed(() => code.value.replace(/\D/g, '').length === 6);

watch(() => route.query.flow_token, (value) => {
    if (value) {
        flowToken.value = String(value);
    }
});

watch(() => route.query.email, (value) => {
    if (value) {
        maskedEmail.value = String(value);
    }
});

function resetErrors() {
    errors.code = null;
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
        const { data } = await providerAxios.post('/api/provider/v1/verify-email', {
            flow_token: flowToken.value,
            code: code.value,
        });

        await router.push({
            name: 'provider.create-password',
            query: {
                flow_token: data.data.flow_token,
            },
        });
    } catch (error) {
        if (error.response?.status === 422) {
            errors.code = error.response.data.errors?.code ?? null;
            errors.flow_token = error.response.data.errors?.flow_token ?? null;
            errors.general = error.response.data.message ?? null;
        } else {
            errors.general = extractApiErrorMessage(error);
        }
    } finally {
        loading.value = false;
    }
}

async function resend() {
    if (! flowToken.value) {
        showError(t('user_auth.session_expired'));
        return;
    }

    if (! canResend.value) {
        return;
    }

    resending.value = true;

    try {
        const { data } = await providerAxios.post('/api/provider/v1/resend-verification', {
            flow_token: flowToken.value,
        });

        if (data.data?.email) {
            maskedEmail.value = data.data.email;
        }

        startCooldown(data.data?.resend_cooldown_seconds);
        showSuccess(extractApiMessage(data, t('user_auth.code_sent')));
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        resending.value = false;
    }
}
</script>
