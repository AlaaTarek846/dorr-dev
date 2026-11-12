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

                <!-- Name / description in every stored language; the switches below are technical (not translated). -->
                <!-- Header and footer stay, only the body scrolls (catalog-list.css .catalog-scroll-form). -->
                <form v-if="activeLocale" class="catalog-scroll-form" @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <!-- The text the admin reads: one tab per stored language (like countries) -->
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
                                <label for="data-policy-name" class="form-label">{{ t('ai_data_policies.name') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                                    <input
                                        id="data-policy-name"
                                        v-model="form.translations[activeLocale].name"
                                        type="text"
                                        maxlength="150"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'name')"
                                        :placeholder="t('ai_data_policies.name_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'name')"
                                    >
                                    <FormFieldFeedback v-bind="fieldFeedbackFor(activeLocale, 'name')" />
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'name')" class="invalid-feedback d-block">{{ fieldMessage(activeLocale, 'name') }}</div>
                            </div>

                            <div class="col-12">
                                <label for="data-policy-description" class="form-label">{{ t('ai_data_policies.description') }}</label>
                                <textarea
                                    id="data-policy-description"
                                    v-model="form.translations[activeLocale].description"
                                    class="form-control"
                                    :class="fieldInputClass(activeLocale, 'description')"
                                    rows="2"
                                    maxlength="1000"
                                    :placeholder="t('ai_data_policies.description_placeholder')"
                                    @input="onTranslationInput(activeLocale, 'description')"
                                ></textarea>
                                <div v-if="fieldMessage(activeLocale, 'description')" class="invalid-feedback d-block">{{ fieldMessage(activeLocale, 'description') }}</div>
                                <div v-else class="form-text">{{ t('ai_data_policies.text_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-settings-3-line me-1"></i>{{ t('ai_data_policies.sections.main') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <label for="data-policy-classification" class="form-label">{{ t('ai_data_policies.data_classification') }}</label>
                                <Select
                                    v-model="form.data_classification"
                                    input-id="data-policy-classification"
                                    :options="classificationOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.data_classification)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('data_classification')"
                                />
                                <div v-if="serverErrors.data_classification" class="invalid-feedback d-block">{{ serverErrors.data_classification[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_data_policies.classification_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="data-policy-retention" class="form-label">{{ t('ai_data_policies.retention_days') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-time-line"></i></span>
                                    <input
                                        id="data-policy-retention"
                                        v-model.number="form.retention_days"
                                        type="number"
                                        min="0"
                                        class="form-control"
                                        :class="{ 'is-invalid': feedbackFor('retention_days').show && feedbackFor('retention_days').invalid }"
                                        :placeholder="t('ai_data_policies.retention_days_placeholder')"
                                        @input="onFieldInput('retention_days')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackFor('retention_days')" />
                                </div>
                                <div v-if="feedbackFor('retention_days').invalid" class="invalid-feedback d-block">{{ v$.retention_days?.$errors?.[0]?.$message || serverErrors.retention_days?.[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_data_policies.retention_days_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_data_policies.consent_required') }}</label>
                                <div class="toggle toggle-primary mb-0 catalog-modal-toggle" :class="{ on: form.consent_required }" role="switch" tabindex="0" :aria-checked="form.consent_required" @click="form.consent_required = !form.consent_required" @keydown.enter.space.prevent="form.consent_required = !form.consent_required">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_data_policies.consent_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_data_policies.minimization_enabled') }}</label>
                                <div class="toggle toggle-primary mb-0 catalog-modal-toggle" :class="{ on: form.minimization_enabled }" role="switch" tabindex="0" :aria-checked="form.minimization_enabled" @click="form.minimization_enabled = !form.minimization_enabled" @keydown.enter.space.prevent="form.minimization_enabled = !form.minimization_enabled">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_data_policies.minimization_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_data_policies.external_provider_allowed') }}</label>
                                <div class="toggle toggle-primary mb-0 catalog-modal-toggle" :class="{ on: form.external_provider_allowed }" role="switch" tabindex="0" :aria-checked="form.external_provider_allowed" @click="form.external_provider_allowed = !form.external_provider_allowed" @keydown.enter.space.prevent="form.external_provider_allowed = !form.external_provider_allowed">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_data_policies.external_provider_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_data_policies.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_data_policies.status_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_data_policies.saving') : t('save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, integer, minValue } from '@vuelidate/validators';
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

const RESOURCE_URI = '/api/admin/v1/ai-data-policies';

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const DEFAULTS = {
    data_classification: 'internal',
    retention_days: null,
    consent_required: false,
    minimization_enabled: true,
    external_provider_allowed: true,
    status: true,
};

const form = reactive({ ...DEFAULTS, translations: {} });

const CLASSIFICATIONS = ['public', 'internal', 'confidential', 'personal', 'secret'];

const classificationOptions = computed(() => CLASSIFICATIONS.map((value) => ({ value, label: t(`ai_data_policies.classification_${value}`) })));

const isBlank = (value) => value === null || value === undefined || value === '';

const {
    activeLocale,
    storableLanguages,
    translationRules,
    ensureLanguagesLoaded,
    fieldFeedbackFor,
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
        { name: 'name', labelKey: 'ai_data_policies.name', max: 150, min: 2 },
        { name: 'description', labelKey: 'ai_data_policies.description', max: 1000, required: false },
    ],
    getV$: () => v$.value,
});

const rules = computed(() => ({
    translations: translationRules.value,
    retention_days: {
        integer: helpers.withMessage(() => t('validation.integer', { field: t('ai_data_policies.retention_days') }), (value) => isBlank(value) || integer.$validator(value)),
        minValue: helpers.withMessage(() => t('validation.min.numeric', { field: t('ai_data_policies.retention_days'), min: 0 }), (value) => isBlank(value) || minValue(0).$validator(value)),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('ai_data_policies.create_title');
    }

    const record = props.record;
    const name = record?.translations?.find((item) => item.locale === locale.value)?.name || record?.name || '';

    return record?.id ? `${t('ai_data_policies.edit_title')} #${record.id} ${name}`.trim() : t('ai_data_policies.edit_title');
});

function feedbackFor(field) {
    return fieldFeedback(v$.value[field], serverErrors[field]?.[0], form[field]);
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
    form.data_classification = record?.data_classification ?? 'internal';
    form.retention_days = record?.retention_days ?? null;
    form.consent_required = Boolean(record?.consent_required ?? false);
    form.minimization_enabled = Boolean(record?.minimization_enabled ?? true);
    form.external_provider_allowed = Boolean(record?.external_provider_allowed ?? true);
    form.status = Boolean(record?.status ?? record?.is_active ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    return {
        data_classification: form.data_classification,
        retention_days: isBlank(form.retention_days) ? null : form.retention_days,
        consent_required: form.consent_required,
        minimization_enabled: form.minimization_enabled,
        external_provider_allowed: form.external_provider_allowed,
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
    onOpen: ensureLanguagesLoaded,
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

.catalog-form-section--first {
    margin-top: 0.25rem;
    padding-top: 0;
    border-top: 0;
}
</style>
