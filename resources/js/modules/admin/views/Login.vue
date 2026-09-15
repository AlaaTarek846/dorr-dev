<template>
    <div class="row authentication mx-0">
        <div class="col-xxl-7 col-xl-7 col-lg-12">
            <div class="row justify-content-center align-items-center h-100">
                <div class="col-xxl-6 col-xl-7 col-lg-7 col-md-7 col-sm-8 col-12">
                    <div class="p-5">
                        <LoginLanguageSelect />

                        <div class="mb-3">
                            <PlatformLogo href="/admin" variant="auth" />
                        </div>

                        <p class="h5 fw-semibold mb-2">{{ t('sign_in') }}</p>
                        <p class="mb-3 text-muted op-7 fw-normal">{{ t('welcome_back') }}</p>

                        <form @submit.prevent="submit">
                            <div class="row gy-3">
                                <div class="col-xl-12 mt-0">
                                    <AuthFormInput
                                        input-id="admin-signin-email"
                                        v-model="form.email"
                                        :label="t('email')"
                                        icon="ri-mail-line"
                                        type="email"
                                        placeholder="admin@admin.com"
                                        autocomplete="username"
                                        :error="errors.email?.[0] ?? ''"
                                    />
                                </div>

                                <div class="col-xl-12 mb-3">
                                    <AuthFormInput
                                        input-id="admin-signin-password"
                                        v-model="form.password"
                                        :label="t('password')"
                                        icon="ri-lock-password-line"
                                        placeholder="password"
                                        autocomplete="current-password"
                                        password-toggle
                                        :error="errors.password?.[0] ?? ''"
                                    />

                                    <div class="mt-2">
                                        <div class="form-check text-start">
                                            <input
                                                id="admin-remember-password"
                                                v-model="form.remember"
                                                class="form-check-input"
                                                type="checkbox"
                                            >
                                            <label class="form-check-label text-muted fw-normal" for="admin-remember-password">
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
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <AuthCoverAside context="admin" />
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import adminAxios from '../../../api/adminAxios';
import AuthCoverAside from '../../../components/auth/AuthCoverAside.vue';
import AuthFormInput from '../../../components/auth/AuthFormInput.vue';
import LoginLanguageSelect from '../../../components/auth/LoginLanguageSelect.vue';
import PlatformLogo from '../../../components/layout/PlatformLogo.vue';
import { useAuthStore } from '../../../stores/auth';

const { t } = useI18n();
const router = useRouter();
const authStore = useAuthStore();

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
        const { data } = await adminAxios.post('/api/admin/v1/login', {
            email: form.email,
            password: form.password,
        });

        authStore.setSession({
            token: data.data.token,
            admin: data.data.admin,
        });

        await router.push({ name: 'admin.dashboard' });
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
