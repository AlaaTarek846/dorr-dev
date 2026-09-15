<template>
    <div class="row authentication mx-0">
        <div class="col-xxl-7 col-xl-7 col-lg-12">
            <div class="row justify-content-center align-items-center h-100">
                <div class="col-xxl-6 col-xl-7 col-lg-7 col-md-7 col-sm-8 col-12">
                    <div class="p-5">
                        <div class="mb-3">
                            <a href="/admin">
                                <img
                                    src="/dashboard/assets/images/brand-logos/desktop-logo.png"
                                    alt=""
                                    class="authentication-brand desktop-logo"
                                >
                                <img
                                    src="/dashboard/assets/images/brand-logos/desktop-dark.png"
                                    alt=""
                                    class="authentication-brand desktop-dark"
                                >
                            </a>
                        </div>

                        <p class="h5 fw-semibold mb-2">{{ t('sign_in') }}</p>
                        <p class="mb-3 text-muted op-7 fw-normal">{{ t('welcome_back') }}</p>

                        <div class="btn-list">
                            <button type="button" class="btn btn-light">
                                <svg class="google-svg" xmlns="http://www.w3.org/2000/svg" width="2443" height="2500" preserveAspectRatio="xMidYMid" viewBox="0 0 256 262"><path fill="#4285F4" d="M255.878 133.451c0-10.734-.871-18.567-2.756-26.69H130.55v48.448h71.947c-1.45 12.04-9.283 30.172-26.69 42.356l-.244 1.622 38.755 30.023 2.685.268c24.659-22.774 38.875-56.282 38.875-96.027" /><path fill="#34A853" d="M130.55 261.1c35.248 0 64.839-11.605 86.453-31.622l-41.196-31.913c-11.024 7.688-25.82 13.055-45.257 13.055-34.523 0-63.824-22.773-74.269-54.25l-1.531.13-40.298 31.187-.527 1.465C35.393 231.798 79.49 261.1 130.55 261.1" /><path fill="#FBBC05" d="M56.281 156.37c-2.756-8.123-4.351-16.827-4.351-25.82 0-8.994 1.595-17.697 4.206-25.82l-.073-1.73L15.26 71.312l-1.335.635C5.077 89.644 0 109.517 0 130.55s5.077 40.905 13.925 58.602l42.356-32.782" /><path fill="#EB4335" d="M130.55 50.479c24.514 0 41.05 10.589 50.479 19.438l36.844-35.974C195.245 12.91 165.798 0 130.55 0 79.49 0 35.393 29.301 13.925 71.947l42.211 32.783c10.59-31.477 39.891-54.251 74.414-54.251" /></svg>
                                Sign In with google
                            </button>
                            <button type="button" class="btn btn-icon btn-light">
                                <i class="ri-facebook-fill"></i>
                            </button>
                            <button type="button" class="btn btn-icon btn-light">
                                <i class="ri-twitter-fill"></i>
                            </button>
                        </div>

                        <div class="text-center my-5 authentication-barrier">
                            <span>OR</span>
                        </div>

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
                                    <label for="signin-password" class="form-label text-default d-block">
                                        {{ t('password') }}
                                        <a href="#" class="float-end text-danger" @click.prevent>{{ t('forget_password') }}</a>
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

                        <div class="text-center">
                            <p class="fs-12 text-muted mt-4">
                                Dont have an account?
                                <a href="#" class="text-primary" @click.prevent>Sign Up</a>
                            </p>
                        </div>
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
