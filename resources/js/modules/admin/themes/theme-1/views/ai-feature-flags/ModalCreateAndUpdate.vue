<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header catalog-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">{{ modalTitle }}</h6>
                        <button type="button" class="btn-close catalog-modal-close" aria-label="Close" @click="close"></button>
                    </div>
                </div>

                <!-- Header and footer stay, only the body scrolls (catalog-list.css .catalog-scroll-form). -->
                <form v-if="activeLocale" class="catalog-scroll-form" @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <!-- The text the admin reads (the description): one tab per stored language (like countries) -->
                        <CatalogTranslationTabs
                            :languages="storableLanguages"
                            :active-locale="activeLocale"
                            :translation-tab-class="translationTabClass"
                            :translation-tab-feedback="translationTabFeedback"
                            @update:active-locale="activeLocale = $event"
                        />

                        <div v-if="translationsGroupMessage" class="alert alert-danger py-2 px-3 mb-3">{{ translationsGroupMessage }}</div>

                        <div class="row g-3 mb-2">
                            <div class="col-12">
                                <label for="flag-description" class="form-label">{{ t('ai_feature_flags.description') }}</label>
                                <textarea
                                    id="flag-description"
                                    v-model="form.translations[activeLocale].description"
                                    class="form-control"
                                    :class="fieldInputClass(activeLocale, 'description')"
                                    rows="2"
                                    maxlength="1000"
                                    :placeholder="t('ai_feature_flags.description_placeholder')"
                                    @input="onTranslationInput(activeLocale, 'description')"
                                ></textarea>
                                <div v-if="fieldMessage(activeLocale, 'description')" class="invalid-feedback d-block">{{ fieldMessage(activeLocale, 'description') }}</div>
                                <div v-else class="form-text">{{ t('ai_feature_flags.text_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-crosshair-2-line me-1"></i>{{ t('ai_feature_flags.sections.target') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="flag-key" class="form-label">{{ t('ai_feature_flags.key') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-code-s-slash-line"></i></span>
                                    <input
                                        id="flag-key"
                                        v-model="form.key"
                                        type="text"
                                        maxlength="150"
                                        dir="ltr"
                                        class="form-control"
                                        :class="inputClass('key')"
                                        :placeholder="t('ai_feature_flags.key_placeholder')"
                                        @input="onFieldInput('key')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackFor('key')" />
                                </div>
                                <div v-if="messageFor('key')" class="invalid-feedback d-block">{{ messageFor('key') }}</div>
                                <div v-else class="form-text">{{ t('ai_feature_flags.key_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="flag-target-type" class="form-label">{{ t('ai_feature_flags.target') }} <span class="text-danger">*</span></label>
                                <Select
                                    v-model="form.target_type"
                                    input-id="flag-target-type"
                                    :options="targetOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.target_type)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('target_type')"
                                />
                                <div v-if="serverErrors.target_type" class="invalid-feedback d-block">{{ serverErrors.target_type[0] }}</div>
                            </div>

                            <div v-if="form.target_type === 'provider' || form.target_type === 'model'" class="col-md-6">
                                <label for="flag-provider" class="form-label">{{ t('ai_feature_flags.provider') }}</label>
                                <Select
                                    v-model="form.provider_id"
                                    input-id="flag-provider"
                                    :options="providers"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('ai_feature_flags.all_providers')"
                                    :show-clear="true"
                                    :invalid="Boolean(serverErrors.provider_id)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('provider_id')"
                                />
                                <div v-if="serverErrors.provider_id" class="invalid-feedback d-block">{{ serverErrors.provider_id[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_feature_flags.provider_hint') }}</div>
                            </div>

                            <div v-if="form.target_type === 'model'" class="col-md-6">
                                <label for="flag-model-key" class="form-label">{{ t('ai_feature_flags.model_key') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-cpu-line"></i></span>
                                    <input id="flag-model-key" v-model="form.model_key" type="text" maxlength="150" dir="ltr" class="form-control" :class="inputClass('model_key')" @input="onFieldInput('model_key')">
                                    <FormFieldFeedback v-bind="feedbackFor('model_key')" />
                                </div>
                                <div v-if="messageFor('model_key')" class="invalid-feedback d-block">{{ messageFor('model_key') }}</div>
                                <div v-else class="form-text">{{ t('ai_feature_flags.model_key_hint') }}</div>
                            </div>

                            <div v-if="form.target_type === 'tool'" class="col-md-6">
                                <label for="flag-tool-key" class="form-label">{{ t('ai_feature_flags.tool_key') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-tools-line"></i></span>
                                    <input id="flag-tool-key" v-model="form.tool_key" type="text" maxlength="150" dir="ltr" class="form-control" :class="inputClass('tool_key')" @input="onFieldInput('tool_key')">
                                    <FormFieldFeedback v-bind="feedbackFor('tool_key')" />
                                </div>
                                <div v-if="messageFor('tool_key')" class="invalid-feedback d-block">{{ messageFor('tool_key') }}</div>
                                <div v-else class="form-text">{{ t('ai_feature_flags.tool_key_hint') }}</div>
                            </div>

                            <!-- A whole service (today: the DORR AI chat) - switched off for a country, it shows "not available". -->
                            <div v-if="form.target_type === 'service'" class="col-md-6">
                                <label for="flag-service-key" class="form-label">{{ t('ai_feature_flags.service_key') }}</label>
                                <Select
                                    v-model="form.tool_key"
                                    input-id="flag-service-key"
                                    :options="serviceOptions"
                                    option-label="label"
                                    option-value="value"
                                    append-to="body"
                                    class="w-100"
                                />
                                <div class="form-text">{{ t('ai_feature_flags.service_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-earth-line me-1"></i>{{ t('ai_feature_flags.sections.scope') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-md-4">
                                <label for="flag-country" class="form-label">{{ t('ai_feature_flags.country_code') }}</label>
                                <div class="input-group">
                                    <input id="flag-country" v-model="form.country_code" type="text" maxlength="2" dir="ltr" class="form-control text-uppercase" :class="inputClass('country_code')" :placeholder="t('ai_feature_flags.all_countries')" @input="onFieldInput('country_code')">
                                    <FormFieldFeedback v-bind="feedbackFor('country_code')" />
                                </div>
                                <div v-if="messageFor('country_code')" class="invalid-feedback d-block">{{ messageFor('country_code') }}</div>
                                <div v-else class="form-text">{{ t('ai_feature_flags.country_hint') }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="flag-domain" class="form-label">{{ t('ai_feature_flags.domain') }}</label>
                                <div class="input-group">
                                    <input id="flag-domain" v-model="form.domain" type="text" maxlength="100" dir="ltr" class="form-control" :class="inputClass('domain')" :placeholder="t('ai_feature_flags.all_domains')" @input="onFieldInput('domain')">
                                    <FormFieldFeedback v-bind="feedbackFor('domain')" />
                                </div>
                                <div v-if="messageFor('domain')" class="invalid-feedback d-block">{{ messageFor('domain') }}</div>
                                <div v-else class="form-text">{{ t('ai_feature_flags.domain_hint') }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="flag-environment" class="form-label">{{ t('ai_feature_flags.environment') }}</label>
                                <div class="input-group">
                                    <input id="flag-environment" v-model="form.environment" type="text" maxlength="20" dir="ltr" class="form-control" :class="inputClass('environment')" @input="onFieldInput('environment')">
                                    <FormFieldFeedback v-bind="feedbackFor('environment')" />
                                </div>
                                <div v-if="messageFor('environment')" class="invalid-feedback d-block">{{ messageFor('environment') }}</div>
                                <div v-else class="form-text">{{ t('ai_feature_flags.environment_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_feature_flags.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ form.status ? t('ai_feature_flags.status_on_hint') : t('ai_feature_flags.status_off_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_feature_flags.saving') : t('save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, maxLength } from '@vuelidate/validators';
import Select from 'primevue/select';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useCatalogTranslationFields from '../../../../../../composables/useCatalogTranslationFields';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../../../utils/catalog';

const RESOURCE_URI = '/api/admin/v1/ai-feature-flags';

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { stringFieldRules, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const providers = ref([]);
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const DEFAULTS = {
    key: '',
    target_type: 'provider',
    provider_id: null,
    model_key: '',
    tool_key: '',
    country_code: '',
    domain: '',
    environment: 'production',
    // The flag's on/off (was is_enabled): off = the kill switch is on.
    status: true,
};

const form = reactive({ ...DEFAULTS, translations: {} });

const targetOptions = computed(() => ['provider', 'model', 'tool', 'service'].map((value) => ({
    value,
    label: t(`ai_feature_flags.target_type_${value}`),
})));

const serviceOptions = computed(() => [{ value: 'ai_chat', label: t('ai_feature_flags.service_ai_chat') }]);

const {
    activeLocale,
    storableLanguages,
    translationRules,
    ensureLanguagesLoaded,
    fieldInputClass,
    fieldMessage,
    translationTabFeedback,
    translationTabClass,
    translationsGroupMessage,
    onTranslationInput,
    resetTranslations,
    fillTranslations,
    buildTranslationsPayload,
    focusInvalidTranslationTab,
} = useCatalogTranslationFields({
    form,
    serverErrors,
    fields: [
        { name: 'description', labelKey: 'ai_feature_flags.description', max: 1000, required: false },
    ],
    getV$: () => v$.value,
});

function optionalMax(labelKey, max) {
    return {
        maxLength: helpers.withMessage(() => t('validation.max.string', { field: t(labelKey), max }), maxLength(max)),
    };
}

const rules = computed(() => ({
    translations: translationRules.value,
    key: stringFieldRules('ai_feature_flags.key', 150),
    model_key: optionalMax('ai_feature_flags.model_key', 150),
    tool_key: optionalMax('ai_feature_flags.tool_key', 150),
    domain: optionalMax('ai_feature_flags.domain', 100),
    environment: optionalMax('ai_feature_flags.environment', 20),
    country_code: {
        size: helpers.withMessage(
            () => t('ai_feature_flags.country_code_size'),
            (value) => ! value || String(value).trim().length === 2,
        ),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_feature_flags.edit_title')}${props.record?.id ? ` #${props.record.id} ${props.record.key ?? ''}` : ''}`.trim() : t('ai_feature_flags.create_title')
));

async function loadOptions() {
    await ensureLanguagesLoaded();

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-providers');
        providers.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

const isBlank = (value) => value === null || value === undefined || value === '';

function feedbackFor(field) {
    return fieldFeedback(v$.value[field], serverErrors[field]?.[0], form[field]);
}

function inputClass(field) {
    const feedback = feedbackFor(field);

    return { 'is-invalid': feedback.show && feedback.invalid, 'is-valid': feedback.show && feedback.valid && ! isBlank(form[field]) };
}

function messageFor(field) {
    if (! feedbackFor(field).invalid) {
        return null;
    }

    return v$.value[field]?.$errors?.[0]?.$message || serverErrors[field]?.[0] || null;
}

function onFieldInput(field) {
    delete serverErrors[field];
    v$.value[field]?.$touch();
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    Object.assign(form, DEFAULTS);
    resetTranslations();
    resetValidation();
}

function fillForm(record) {
    form.key = record?.key ?? '';
    form.target_type = record?.target_type ?? 'provider';
    form.provider_id = record?.provider_id ?? record?.provider?.id ?? null;
    form.model_key = record?.model_key ?? '';
    form.tool_key = record?.tool_key ?? '';
    form.country_code = record?.country_code ?? '';
    form.domain = record?.domain ?? '';
    form.environment = record?.environment ?? 'production';
    form.status = Boolean(record?.status ?? record?.is_enabled ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    const text = (value) => String(value ?? '').trim() || null;
    const target = form.target_type;

    return {
        key: String(form.key ?? '').trim(),
        target_type: target,
        // Only the fields of the chosen target are kept; a service flag names its service in tool_key (today only the DORR AI chat).
        provider_id: target === 'provider' || target === 'model' ? (form.provider_id ?? null) : null,
        model_key: target === 'model' ? text(form.model_key) : null,
        tool_key: target === 'service' ? (text(form.tool_key) || 'ai_chat') : (target === 'tool' ? text(form.tool_key) : null),
        country_code: text(form.country_code)?.toUpperCase() ?? null,
        domain: text(form.domain),
        environment: text(form.environment) || 'production',
        status: form.status,
        translations: buildTranslationsPayload().map((translation) => ({
            ...translation,
            description: translation.description || null,
        })),
    };
}

function openModal() {
    if (! modalElement.value) {
        return;
    }

    modalInstance ??= new window.bootstrap.Modal(modalElement.value, { focus: false });
    modalInstance.show();
}

function closeModal() {
    modalInstance?.hide();
}

function close() {
    closeModal();
    emit('close');
}

function onModalHidden() {
    emit('close');
}

async function submit() {
    v$.value.$touch();

    if (v$.value.$invalid) {
        focusInvalidTranslationTab();
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;
    applyApiErrors(serverErrors, {});

    try {
        const response = isEdit.value && props.record?.id
            ? await adminAxios.put(`${RESOURCE_URI}/${props.record.id}`, buildPayload())
            : await adminAxios.post(RESOURCE_URI, buildPayload());

        showSuccess(extractApiMessage(response, t(isEdit.value ? 'toast.updated' : 'toast.created')));
        closeModal();
        emit('saved');
    } catch (error) {
        if (error.response?.status === 422) {
            applyApiErrors(serverErrors, error.response.data.errors ?? {});
            focusInvalidTranslationTab();
            showWarning(t('toast.validation_error'));
        } else {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }
    } finally {
        submitting.value = false;
    }
}

setupCatalogModalWatcher({
    props,
    fillForm,
    resetForm,
    openModal,
    closeModal,
    resourceUri: RESOURCE_URI,
    onOpen: loadOptions,
});

onMounted(async () => {
    await ensureLanguagesLoaded();
    modalElement.value?.addEventListener('hidden.bs.modal', onModalHidden);
});

onUnmounted(() => {
    modalElement.value?.removeEventListener('hidden.bs.modal', onModalHidden);
    modalInstance?.dispose();
});
</script>

<style scoped>
.catalog-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.catalog-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.catalog-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
    opacity: 0.65;
    background-size: 0.65rem;
}

.catalog-modal-close:hover {
    opacity: 1;
}

.catalog-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}

.catalog-form-section {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--text-muted, #6c757d);
    margin: 1.5rem 0 0.75rem;
    padding-top: 1rem;
    border-top: 1px dashed var(--default-border, #dee2e6);
}
</style>
