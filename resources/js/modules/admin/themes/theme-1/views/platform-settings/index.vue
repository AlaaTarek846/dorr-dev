<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('platform_settings.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('platform_settings.subtitle') }}</span>
            </div>
        </div>

        <form @submit.prevent="submitSettings">
            <fieldset :disabled="!canUpdate">
            <div class="row g-4">
                <div class="col-xl-5">
                    <div class="card custom-card">
                        <div class="card-header">
                            <div class="card-title">{{ t('platform_settings.general') }}</div>
                        </div>
                        <div class="card-body">
                            <label for="platform-app-name" class="form-label">
                                {{ t('platform_settings.app_name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-apps-line"></i>
                                </span>
                                <input
                                    id="platform-app-name"
                                    v-model="form.app_name"
                                    type="text"
                                    maxlength="255"
                                    class="form-control"
                                    :class="appNameInputClass"
                                    @input="clearError('app_name')"
                                >
                            </div>
                            <div v-if="appNameMessage" class="invalid-feedback d-block">
                                {{ appNameMessage }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-7">
                    <div class="card custom-card">
                        <div class="card-header">
                            <div class="card-title">{{ t('platform_settings.favicon_section') }}</div>
                        </div>
                        <div class="card-body">
                            <SettingsAssetUpload
                                v-for="asset in assetFields"
                                :key="asset.key"
                                :ref="(el) => setAssetRef(asset.key, el)"
                                :label="t(asset.labelKey)"
                                :accept="asset.accept"
                                :is-image="asset.isImage"
                                :preview-url="assetPreviews[asset.key]"
                                :has-file="hasAssetFile(asset.key)"
                                :error="errors[asset.key]?.[0] ?? ''"
                                @select="(file) => onAssetSelect(asset.key, file)"
                                @remove="() => onAssetRemove(asset.key)"
                            />
                        </div>
                    </div>
                </div>
            </div>
            </fieldset>

            <div v-if="canUpdate" class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-primary" :disabled="submitting">
                    {{ submitting ? t('platform_settings.saving') : t('save_changes') }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import SettingsAssetUpload from '../../../../../../components/settings/SettingsAssetUpload.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { usePlatformBrandingStore } from '../../../../../../stores/platformBranding';

const ASSET_FIELDS = [
    {
        key: 'logo',
        labelKey: 'platform_settings.logo',
        accept: 'image/png,image/jpeg,image/webp,image/svg+xml,.svg',
        isImage: true,
    },
    {
        key: 'logo_dark',
        labelKey: 'platform_settings.logo_dark',
        accept: 'image/png,image/jpeg,image/webp,image/svg+xml,.svg',
        isImage: true,
    },
    {
        key: 'favicon_ico',
        labelKey: 'platform_settings.favicon_ico',
        accept: '.ico,image/x-icon,image/vnd.microsoft.icon',
        isImage: true,
    },
    {
        key: 'favicon_16',
        labelKey: 'platform_settings.favicon_16',
        accept: 'image/png,image/x-icon,.ico',
        isImage: true,
    },
    {
        key: 'favicon_32',
        labelKey: 'platform_settings.favicon_32',
        accept: 'image/png,image/x-icon,.ico',
        isImage: true,
    },
    {
        key: 'apple_touch_icon',
        labelKey: 'platform_settings.apple_touch_icon',
        accept: 'image/png,image/jpeg,image/webp',
        isImage: true,
    },
    {
        key: 'web_manifest',
        labelKey: 'platform_settings.web_manifest',
        accept: '.json,.webmanifest,application/json',
        isImage: false,
    },
];

const { t } = useI18n();
const { canUpdate } = useCatalogPermissions('platform_settings');
const brandingStore = usePlatformBrandingStore();
const { showSuccess, showError, showWarning } = useToast();
const {
    requiredField,
    maxString,
    applyApiErrors,
    fieldFeedback,
} = useValidation();

const assetFields = ASSET_FIELDS;
const submitting = ref(false);
const errors = reactive({});
const assetRefs = reactive({});
const assetPreviews = reactive(createAssetState('', null, false));
const assetFiles = reactive(createAssetState(null, null, false));
const assetRemoveFlags = reactive(createAssetState(false, null, false));

const form = reactive({
    app_name: '',
});

const rules = computed(() => ({
    app_name: {
        required: requiredField('platform_settings.app_name'),
        maxLength: maxString('platform_settings.app_name', 255),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const appNameFeedback = computed(() => fieldFeedback(
    v$.value.app_name,
    errors.app_name?.[0],
    form.app_name,
));

const appNameInputClass = computed(() => ({
    'is-invalid': appNameFeedback.value.show && appNameFeedback.value.invalid,
    'is-valid': appNameFeedback.value.show && appNameFeedback.value.valid,
}));

const appNameMessage = computed(() => {
    if (! appNameFeedback.value.invalid) {
        return null;
    }

    return v$.value.app_name?.$errors[0]?.$message || errors.app_name?.[0] || null;
});

function createAssetState(initialValue, _ignored, asBoolean = false) {
    const state = {};

    ASSET_FIELDS.forEach(({ key }) => {
        state[key] = asBoolean ? false : initialValue;
    });

    return state;
}

function setAssetRef(key, element) {
    if (element) {
        assetRefs[key] = element;
    }
}

function hasAssetFile(key) {
    return Boolean(assetPreviews[key]);
}

function clearError(field) {
    delete errors[field];
}

function fillForm(settings) {
    form.app_name = settings?.app_name ?? '';

    ASSET_FIELDS.forEach(({ key }) => {
        assetPreviews[key] = settings?.[key] ?? '';
        assetFiles[key] = null;
        assetRemoveFlags[key] = false;
    });
}

function onAssetSelect(key, file) {
    assetFiles[key] = file;
    assetRemoveFlags[key] = false;
    assetPreviews[key] = URL.createObjectURL(file);
    clearError(key);
}

function onAssetRemove(key) {
    assetFiles[key] = null;
    assetRemoveFlags[key] = true;
    assetPreviews[key] = '';
    assetRefs[key]?.resetInput?.();
    clearError(key);
}

function buildFormData() {
    const formData = new FormData();

    formData.append('app_name', form.app_name.trim());

    ASSET_FIELDS.forEach(({ key }) => {
        if (assetFiles[key]) {
            formData.append(key, assetFiles[key]);
        }

        if (assetRemoveFlags[key]) {
            formData.append(`remove_${key}`, '1');
        }
    });

    return formData;
}

async function loadSettings() {
    const { data } = await adminAxios.get('/api/admin/v1/platform-settings');
    fillForm(data.data ?? null);
}

async function submitSettings() {
    if (! canUpdate.value) {
        return;
    }

    v$.value.$touch();

    if (v$.value.$invalid) {
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;
    applyApiErrors(errors, {});

    try {
        const response = await adminAxios.post('/api/admin/v1/platform-settings', buildFormData());
        fillForm(response.data.data ?? null);
        v$.value.$reset();
        await brandingStore.fetch(true);
        showSuccess(extractApiMessage(response, t('toast.updated')));
    } catch (error) {
        if (error.response?.status === 422) {
            applyApiErrors(errors, error.response.data.errors ?? {});
            showWarning(t('toast.validation_error'));
        } else {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }
    } finally {
        submitting.value = false;
    }
}

onMounted(async () => {
    try {
        await loadSettings();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
});
</script>
