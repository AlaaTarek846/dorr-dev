<template>
    <div id="particles-js" class="page error-bg">
        <div class="error-page">
            <div class="container">
                <div class="text-center p-5 my-auto">
                    <div class="row align-items-center justify-content-center h-100">
                        <div class="col-xl-7">
                            <p class="error-text mb-sm-0 mb-2">404</p>
                            <p class="fs-18 fw-semibold mb-3">
                                {{ t('errors.not_found_title') }}
                            </p>
                            <div class="row justify-content-center mb-5">
                                <div class="col-xl-6">
                                    <p class="mb-0 op-7">
                                        {{ t('errors.not_found_message') }}
                                    </p>
                                </div>
                            </div>
                            <router-link
                                :to="{ name: 'admin.dashboard' }"
                                class="btn btn-primary"
                            >
                                <i class="ri-arrow-left-line align-middle me-1 d-inline-block"></i>
                                {{ t('errors.back_to_dashboard') }}
                            </router-link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { getThemeBase } from '../../../../../../dashboard/themeContext';

const { t } = useI18n();

let loadedScripts = [];

function loadScript(src) {
    return new Promise((resolve, reject) => {
        const existing = document.querySelector(`script[src="${src}"]`);

        if (existing) {
            resolve();

            return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error(`Failed to load ${src}`));
        document.body.appendChild(script);
        loadedScripts.push(script);
    });
}

onMounted(async () => {
    const base = getThemeBase();

    try {
        await loadScript(`${base}/assets/libs/particles.js/particles.js`);
        await loadScript(`${base}/assets/js/error.js`);
    } catch {
        //
    }
});

onUnmounted(() => {
    for (const script of loadedScripts) {
        script.remove();
    }

    loadedScripts = [];
});
</script>
