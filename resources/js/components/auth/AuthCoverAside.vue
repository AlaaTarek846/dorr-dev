<template>
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
                                    <h6 class="fw-semibold text-fixed-white">{{ slide.title }}</h6>
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
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    context: {
        type: String,
        default: 'user',
        validator: (value) => ['user', 'admin'].includes(value),
    },
});

const { t } = useI18n();
const swiperEl = ref(null);
let swiperInstance = null;

const prefix = props.context === 'admin' ? 'admin_auth' : 'user_auth';

const slides = [
    {
        image: '/dashboard/themes/theme-1/assets/images/authentication/2.png',
        title: t(`${prefix}.cover_title`),
        text: t(`${prefix}.cover_text_1`),
    },
    {
        image: '/dashboard/themes/theme-1/assets/images/authentication/3.png',
        title: t(`${prefix}.cover_title`),
        text: t(`${prefix}.cover_text_2`),
    },
    {
        image: '/dashboard/themes/theme-1/assets/images/authentication/2.png',
        title: t(`${prefix}.cover_title`),
        text: t(`${prefix}.cover_text_3`),
    },
];

onMounted(() => {
    if (typeof window.Swiper === 'undefined' || ! swiperEl.value) {
        return;
    }

    swiperInstance = new window.Swiper(swiperEl.value, {
        slidesPerView: 1,
        spaceBetween: 30,
        keyboard: { enabled: true },
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
</script>
