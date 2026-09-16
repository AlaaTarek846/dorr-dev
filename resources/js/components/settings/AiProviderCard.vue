<template>
    <div class="card custom-card ai-provider-card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="avatar avatar-md avatar-rounded" :class="`bg-${accent}-transparent`">
                    <i :class="[icon, `text-${accent}`]" class="fs-18"></i>
                </span>
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="card-title mb-0">{{ provider.name }}</span>
                        <span
                            class="badge fs-11"
                            :class="provider.has_free_tier ? 'bg-success-transparent' : 'bg-warning-transparent'"
                            :title="provider.pricing_note"
                        >
                            {{ provider.has_free_tier ? t('ai_settings.pricing.free') : t('ai_settings.pricing.paid') }}
                        </span>
                    </div>
                    <a
                        v-if="provider.docs_url"
                        :href="provider.docs_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="fs-12 text-muted"
                    >
                        <i class="ri-external-link-line align-middle"></i>
                        {{ t('ai_settings.docs_link') }}
                    </a>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <span class="badge" :class="provider.is_enabled ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                    {{ provider.is_enabled ? t('ai_settings.enabled') : t('ai_settings.disabled') }}
                </span>
                <div
                    class="toggle toggle-success mb-0"
                    :class="{ on: provider.is_enabled, 'ai-provider-toggle--loading': togglingEnabled }"
                    role="button"
                    tabindex="0"
                    :aria-busy="togglingEnabled"
                    @click="onToggleEnabled"
                    @keydown.enter.space.prevent="onToggleEnabled"
                >
                    <span></span>
                </div>
            </div>
        </div>

        <div class="card-body">
            <form @submit.prevent="saveSettings">
                <div class="mb-3">
                    <label class="form-label" :for="`${provider.key}-api-key`">
                        {{ t('ai_settings.api_key') }}
                    </label>
                    <div class="input-group">
                        <input
                            :id="`${provider.key}-api-key`"
                            v-model="form.api_key"
                            :type="showApiKey ? 'text' : 'password'"
                            class="form-control"
                            :class="{ 'is-invalid': errors.api_key?.[0] }"
                            autocomplete="new-password"
                            :placeholder="apiKeyPlaceholder"
                            @input="onApiKeyInput"
                        >
                        <button
                            type="button"
                            class="btn btn-light border"
                            :title="showApiKey ? t('ai_settings.hide_key') : t('ai_settings.show_key')"
                            @click="showApiKey = !showApiKey"
                        >
                            <i :class="showApiKey ? 'ri-eye-off-line' : 'ri-eye-line'"></i>
                        </button>
                        <button
                            v-if="provider.has_api_key || form.api_key"
                            type="button"
                            class="btn btn-light border text-danger"
                            :title="t('ai_settings.clear_api_key')"
                            @click="onClearApiKey"
                        >
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                    <div v-if="errors.api_key?.[0]" class="invalid-feedback d-block">
                        {{ errors.api_key[0] }}
                    </div>
                    <div v-else class="form-text mb-0">
                        {{ provider.has_api_key ? t('ai_settings.api_key_set_hint', { preview: provider.api_key_preview }) : t('ai_settings.api_key_not_set') }}
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <label class="form-label mb-1" :for="`${provider.key}-model`">{{ t('ai_settings.model') }}</label>
                        <span class="fs-11 text-muted">
                            <i :class="provider.available_models_is_live ? 'ri-checkbox-circle-line text-success' : 'ri-information-line'"></i>
                            {{ provider.available_models_is_live ? t('ai_settings.models_live') : t('ai_settings.models_estimated') }}
                        </span>
                    </div>
                    <select
                        :id="`${provider.key}-model`"
                        v-model="modelSelection"
                        class="form-select"
                        :class="{ 'is-invalid': errors.model?.[0] }"
                    >
                        <option value="" disabled>{{ t('ai_settings.model_placeholder') }}</option>
                        <optgroup
                            v-for="(options, group) in provider.available_models"
                            :key="group"
                            :label="t(`ai_settings.model_groups.${group}`)"
                        >
                            <option v-for="option in options" :key="option" :value="option">
                                {{ option }}
                            </option>
                        </optgroup>
                        <option value="__custom__">{{ t('ai_settings.model_custom_option') }}</option>
                    </select>
                    <input
                        v-if="modelSelection === '__custom__'"
                        v-model="form.model"
                        type="text"
                        class="form-control mt-2"
                        :placeholder="t('ai_settings.model_custom_placeholder')"
                    >
                    <div v-if="errors.model?.[0]" class="invalid-feedback d-block">
                        {{ errors.model[0] }}
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label" :for="`${provider.key}-temperature`">
                            {{ t('ai_settings.temperature') }}
                        </label>
                        <input
                            :id="`${provider.key}-temperature`"
                            v-model="form.temperature"
                            type="number"
                            min="0"
                            max="2"
                            step="0.1"
                            class="form-control"
                            :class="{ 'is-invalid': temperatureMessage }"
                            @input="clearError('temperature')"
                        >
                        <div v-if="temperatureMessage" class="invalid-feedback d-block">
                            {{ temperatureMessage }}
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" :for="`${provider.key}-max-tokens`">
                            {{ t('ai_settings.max_tokens') }}
                        </label>
                        <input
                            :id="`${provider.key}-max-tokens`"
                            v-model="form.max_tokens"
                            type="number"
                            min="1"
                            step="1"
                            class="form-control"
                            :class="{ 'is-invalid': maxTokensMessage }"
                            :placeholder="t('ai_settings.max_tokens_placeholder')"
                            @input="clearError('max_tokens')"
                        >
                        <div v-if="maxTokensMessage" class="invalid-feedback d-block">
                            {{ maxTokensMessage }}
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <button type="button" class="btn btn-link p-0 fs-13" @click="advancedOpen = !advancedOpen">
                        <i :class="advancedOpen ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line'" class="align-middle"></i>
                        {{ t('ai_settings.advanced_settings') }}
                    </button>

                    <div v-show="advancedOpen" class="mt-2">
                        <div class="mb-3">
                            <label class="form-label" :for="`${provider.key}-base-url`">
                                {{ t('ai_settings.base_url') }}
                            </label>
                            <input
                                :id="`${provider.key}-base-url`"
                                v-model="form.base_url"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': errors.base_url?.[0] }"
                                :placeholder="t('ai_settings.base_url_placeholder', { default: provider.default_base_url })"
                                @input="clearError('base_url')"
                            >
                            <div v-if="errors.base_url?.[0]" class="invalid-feedback d-block">
                                {{ errors.base_url[0] }}
                            </div>
                        </div>

                        <div v-if="provider.key === 'openai'" class="mb-0">
                            <label class="form-label" :for="`${provider.key}-organization`">
                                {{ t('ai_settings.organization') }}
                            </label>
                            <input
                                :id="`${provider.key}-organization`"
                                v-model="form.organization"
                                type="text"
                                class="form-control"
                                :placeholder="t('ai_settings.organization_placeholder')"
                            >
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                    <div class="fs-12 text-muted">
                        <template v-if="provider.last_tested_at">
                            <i
                                :class="provider.last_test_status === 'success' ? 'ri-checkbox-circle-fill text-success' : 'ri-close-circle-fill text-danger'"
                            ></i>
                            {{ t('ai_settings.last_tested') }}: {{ formatDate(provider.last_tested_at) }}
                            <span class="d-block">{{ provider.last_test_message }}</span>
                        </template>
                        <template v-else>
                            {{ t('ai_settings.never_tested') }}
                        </template>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <button
                            type="button"
                            class="btn btn-outline-primary btn-sm btn-wave"
                            :disabled="testingConnection || !provider.has_api_key"
                            @click="testConnection"
                        >
                            <span v-if="testingConnection" class="spinner-border spinner-border-sm me-1"></span>
                            {{ testingConnection ? t('ai_settings.testing') : t('ai_settings.test_connection') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm btn-wave" :disabled="savingSettings">
                            <span v-if="savingSettings" class="spinner-border spinner-border-sm me-1"></span>
                            {{ savingSettings ? t('ai_settings.saving') : t('ai_settings.save') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../composables/useToast';
import useValidation from '../../composables/useValidation';

const props = defineProps({
    provider: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['updated']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { applyApiErrors } = useValidation();

const PROVIDER_META = {
    openai: { icon: 'ri-openai-fill', accent: 'success' },
    anthropic: { icon: 'ri-brain-fill', accent: 'warning' },
    google: { icon: 'ri-google-fill', accent: 'primary' },
    groq: { icon: 'ri-speed-up-fill', accent: 'danger' },
};

const icon = computed(() => PROVIDER_META[props.provider.key]?.icon ?? 'ri-robot-2-line');
const accent = computed(() => PROVIDER_META[props.provider.key]?.accent ?? 'primary');

const form = reactive({
    api_key: '',
    model: '',
    base_url: '',
    organization: '',
    temperature: '',
    max_tokens: '',
});

const modelSelection = ref('');
const clearApiKeyFlag = ref(false);
const showApiKey = ref(false);
const advancedOpen = ref(false);
const savingSettings = ref(false);
const togglingEnabled = ref(false);
const testingConnection = ref(false);
const errors = reactive({});

function flattenModels(groupedModels) {
    return Object.values(groupedModels ?? {}).flat();
}

function resetForm() {
    const provider = props.provider;

    form.api_key = '';
    form.model = provider.model ?? '';
    form.base_url = provider.base_url ?? '';
    form.organization = provider.organization ?? '';
    form.temperature = provider.temperature ?? '';
    form.max_tokens = provider.max_tokens ?? '';

    const knownModels = flattenModels(provider.available_models);

    modelSelection.value = provider.model && knownModels.includes(provider.model)
        ? provider.model
        : (provider.model ? '__custom__' : '');

    clearApiKeyFlag.value = false;
    advancedOpen.value = Boolean(provider.base_url || provider.organization);
}

watch(() => props.provider, resetForm, { immediate: true, deep: false });

watch(modelSelection, (value) => {
    if (value !== '__custom__') {
        form.model = value;
    }
});

const apiKeyPlaceholder = computed(() => (
    props.provider.has_api_key
        ? props.provider.api_key_preview
        : t('ai_settings.api_key_placeholder')
));

const temperatureMessage = computed(() => {
    if (errors.temperature?.[0]) {
        return errors.temperature[0];
    }

    if (form.temperature === '' || form.temperature === null) {
        return null;
    }

    const value = Number(form.temperature);

    if (Number.isNaN(value)) {
        return t('ai_settings.validation.temperature_numeric');
    }

    if (value < 0 || value > 2) {
        return t('ai_settings.validation.temperature_range');
    }

    return null;
});

const maxTokensMessage = computed(() => {
    if (errors.max_tokens?.[0]) {
        return errors.max_tokens[0];
    }

    if (form.max_tokens === '' || form.max_tokens === null) {
        return null;
    }

    if (! Number.isInteger(Number(form.max_tokens))) {
        return t('ai_settings.validation.max_tokens_integer');
    }

    return null;
});

function clearError(field) {
    delete errors[field];
}

function onApiKeyInput() {
    clearApiKeyFlag.value = false;
    clearError('api_key');
}

function onClearApiKey() {
    form.api_key = '';
    clearApiKeyFlag.value = true;
    clearError('api_key');
}

function formatDate(value) {
    if (! value) {
        return '-';
    }

    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function buildPayload(overrides = {}) {
    const payload = {
        model: modelSelection.value === '__custom__' ? form.model.trim() : modelSelection.value,
        base_url: form.base_url ? form.base_url.trim() : null,
        organization: form.organization ? form.organization.trim() : null,
        temperature: form.temperature === '' ? null : Number(form.temperature),
        max_tokens: form.max_tokens === '' ? null : Number(form.max_tokens),
    };

    if (clearApiKeyFlag.value) {
        payload.clear_api_key = true;
    } else if (form.api_key) {
        payload.api_key = form.api_key;
    }

    return { ...payload, ...overrides };
}

async function persist(payload, { loadingRef, successMessage } = {}) {
    if (loadingRef) {
        loadingRef.value = true;
    }

    applyApiErrors(errors, {});

    try {
        const response = await adminAxios.post(`/api/admin/v1/ai-providers/${props.provider.key}`, payload);
        emit('updated', response.data.data);
        showSuccess(successMessage ?? extractApiMessage(response, t('ai_settings.saved')));

        return true;
    } catch (error) {
        if (error.response?.status === 422) {
            applyApiErrors(errors, error.response.data.errors ?? {});
            showWarning(t('toast.validation_error'));
        } else {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }

        return false;
    } finally {
        if (loadingRef) {
            loadingRef.value = false;
        }
    }
}

async function onToggleEnabled() {
    const nextValue = ! props.provider.is_enabled;

    if (nextValue && ! props.provider.has_api_key && ! form.api_key) {
        showWarning(t('ai_settings.enable_requires_key'));
        return;
    }

    await persist(buildPayload({ is_enabled: nextValue }), { loadingRef: togglingEnabled });
}

async function saveSettings() {
    if (temperatureMessage.value || maxTokensMessage.value) {
        showWarning(t('toast.validation_error'));
        return;
    }

    await persist(buildPayload(), { loadingRef: savingSettings });
}

async function testConnection() {
    testingConnection.value = true;

    try {
        const response = await adminAxios.post(`/api/admin/v1/ai-providers/${props.provider.key}/test`);
        emit('updated', response.data.data);

        if (response.data.data?.last_test_status === 'success') {
            showSuccess(extractApiMessage(response, t('ai_settings.test_connection')));
        } else {
            showWarning(extractApiMessage(response, t('ai_settings.test_connection')));
        }
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        testingConnection.value = false;
    }
}
</script>

<style scoped>
.ai-provider-toggle--loading {
    opacity: 0.6;
    pointer-events: none;
}
</style>
