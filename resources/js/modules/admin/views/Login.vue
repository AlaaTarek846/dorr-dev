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
                                    <label for="signin-email" class="form-label text-default">{{ t('email') }}</label>
                                    <input
                                        id="signin-email"
                                        v-model="form.email"
                                        type="email"
                                        class="form-control form-control-lg"
                                        placeholder="email@example.com"
                                        autocomplete="username"
                                    >
                                    <div v-if="errors.email" class="text-danger fs-12 mt-1">
                                        {{ errors.email[0] }}
                                    </div>
                                </div>

                                <div class="col-xl-12 mb-3">
                                    <label for="signin-password" class="form-label text-default">
                                        {{ t('password') }}
                                    </label>
                                    <div class="input-group">
                                        <input
                                            id="signin-password"
                                            v-model="form.password"
                                            :type="showPassword ? 'text' : 'password'"
                                            class="form-control form-control-lg"
                                            placeholder="password"
                                            autocomplete="current-password"
                                        >
                                        <button
                                            type="button"
                                            class="btn btn-light"
                                            @click="showPassword = !showPassword"
                                        >
                                            <i :class="showPassword ? 'ri-eye-line' : 'ri-eye-off-line'" class="align-middle"></i>
                                        </button>
                                    </div>
                                    <div v-if="errors.password" class="text-danger fs-12 mt-1">
                                        {{ errors.password[0] }}
                                    </div>
                                    <div class="mt-2">
                                        <div class="form-check">
                                            <input
                                                id="remember-password"
                                                v-model="form.remember"
                                                class="form-check-input"
                                                type="checkbox"
                                            >
                                            <label class="form-check-label text-muted fw-normal" for="remember-password">
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

        <div class="col-xxl-5 col-xl-5 col-lg-5 d-xl-block d-none px-0">
            <div class="authentication-cover">
                <div class="aunthentication-cover-content rounded">
                    <div ref="swiperEl" class="swiper keyboard-control">
                        <div class="swiper-wrapper">
                            <div v-for="slide in slides" :key="slide.image" class="swiper-slide">
                                <div class="text-fixed-white text-center p-5 d-flex align-items-center justify-content-center">
                                    <div>
                                        <div class="mb-5">
                                            <img
                                                :src="slide.image"
                                                class="authentication-image"
                                                alt=""
                                            >
                                        </div>
                                        <h6 class="fw-semibold text-fixed-white">Sign In</h6>
                                        <p class="fw-normal fs-14 op-7">{{ slide.text }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="swiper-button-next"></div>
                        <div class="swiper-button-prev"></div>
                        <div class="swiper-pagination"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';

const { t } = useI18n();
import adminAxios from '../../../api/adminAxios';
import LoginLanguageSelect from '../../../components/auth/LoginLanguageSelect.vue';
import PlatformLogo from '../../../components/layout/PlatformLogo.vue';
import { useAuthStore } from '../../../stores/auth';

const router = useRouter();
const authStore = useAuthStore();

const swiperEl = ref(null);
const showPassword = ref(false);
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

const slides = [
    {
        image: '/dashboard/assets/images/authentication/2.png',
        text: 'Manage your dashboard with a clean and modern admin experience.',
    },
    {
        image: '/dashboard/assets/images/authentication/3.png',
        text: 'Secure access for administrators with full control over your platform.',
    },
    {
        image: '/dashboard/assets/images/authentication/2.png',
        text: 'Sign in to continue to the admin panel and manage your data.',
    },
];

let swiperInstance = null;

onMounted(() => {
    if (typeof window.Swiper === 'undefined' || ! swiperEl.value) {
        return;
    }

    swiperInstance = new window.Swiper(swiperEl.value, {
        slidesPerView: 1,
        spaceBetween: 30,
        keyboard: {
            enabled: true,
        },
        pagination: {
            el: '.swiper-pagination',
            clickable: true,
        },
        navigation: {
            nextEl: '.swiper-button-next',
            prevEl: '.swiper-button-prev',
        },
        loop: true,
        autoplay: {
            delay: 1500,
            disableOnInteraction: false,
        },
    });
});

onBeforeUnmount(() => {
    swiperInstance?.destroy(true, true);
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
