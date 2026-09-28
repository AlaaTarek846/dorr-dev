<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('mobile_app_color_defaults.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('mobile_app_color_defaults.subtitle') }}</span>
            </div>
        </div>

        <form v-if="canUpdate" @submit.prevent="submitSettings">
            <div class="row g-4">
                <div class="col-xl-6">
                    <div class="card custom-card">
                        <div class="card-header"><div class="card-title">{{ t('mobile_app_color_defaults.light') }}</div></div>
                        <div class="card-body">
                            <div v-for="key in colorKeys" :key="'l-' + key" class="mb-3">
                                <label class="form-label">{{ key }}</label>
                                <input v-model="form.light_tokens[key]" type="color" class="form-control form-control-color">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="card custom-card">
                        <div class="card-header"><div class="card-title">{{ t('mobile_app_color_defaults.dark') }}</div></div>
                        <div class="card-body">
                            <div v-for="key in colorKeys" :key="'d-' + key" class="mb-3">
                                <label class="form-label">{{ key }}</label>
                                <input v-model="form.dark_tokens[key]" type="color" class="form-control form-control-color">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-primary" :disabled="submitting">
                    {{ submitting ? t('mobile_app_color_defaults.saving') : t('save_changes') }}
                </button>
            </div>
        </form>
        <div v-else class="alert alert-warning">{{ t('mobile_app_color_defaults.no_permission') }}</div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

const COLOR_KEYS = [
    'primary', 'primaryLight', 'primaryDark', 'secondary', 'accent',
    'danger', 'success', 'warning', 'info',
    'background', 'surface', 'textPrimary', 'textSecondary', 'textMuted', 'border', 'headerBackground',
];

const { t } = useI18n();
const { canUpdate } = useCatalogPermissions('mobile_app_color_defaults');
const { showSuccess, showError } = useToast();

const colorKeys = COLOR_KEYS;
const submitting = ref(false);
const form = reactive({
    light_tokens: Object.fromEntries(COLOR_KEYS.map((k) => [k, '#000000'])),
    dark_tokens: Object.fromEntries(COLOR_KEYS.map((k) => [k, '#000000'])),
});

function normalizeHex(value) {
    if (! value) return '#000000';
    const hex = String(value).replace('#', '').slice(0, 6);
    return `#${hex.padEnd(6, '0')}`;
}

function fillForm(data) {
    COLOR_KEYS.forEach((key) => {
        form.light_tokens[key] = normalizeHex(data?.light_tokens?.[key]);
        form.dark_tokens[key] = normalizeHex(data?.dark_tokens?.[key]);
    });
}

async function loadSettings() {
    const { data } = await adminAxios.get('/api/admin/v1/mobile-app-color-defaults');
    fillForm(data.data ?? data);
}

async function submitSettings() {
    submitting.value = true;
    try {
        const payload = {
            light_tokens: { ...form.light_tokens },
            dark_tokens: { ...form.dark_tokens },
        };
        const response = await adminAxios.put('/api/admin/v1/mobile-app-color-defaults', payload);
        showSuccess(extractApiMessage(response, t('toast.updated')));
        fillForm(response.data?.data ?? response.data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        submitting.value = false;
    }
}

onMounted(() => {
    loadSettings().catch((error) => showError(extractApiErrorMessage(error, t('toast.error'))));
});
</script>
