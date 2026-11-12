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
                                <label for="policy-name" class="form-label">{{ t('ai_domain_policies.name') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                                    <input
                                        id="policy-name"
                                        v-model="form.translations[activeLocale].name"
                                        type="text"
                                        maxlength="150"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'name')"
                                        :placeholder="t('ai_domain_policies.name_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'name')"
                                    >
                                    <FormFieldFeedback v-bind="fieldFeedbackFor(activeLocale, 'name')" />
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'name')" class="invalid-feedback d-block">{{ fieldMessage(activeLocale, 'name') }}</div>
                            </div>

                            <div class="col-12">
                                <label for="policy-description" class="form-label">{{ t('ai_domain_policies.description') }}</label>
                                <textarea
                                    id="policy-description"
                                    v-model="form.translations[activeLocale].description"
                                    class="form-control"
                                    :class="fieldInputClass(activeLocale, 'description')"
                                    rows="2"
                                    maxlength="1000"
                                    :placeholder="t('ai_domain_policies.description_placeholder')"
                                    @input="onTranslationInput(activeLocale, 'description')"
                                ></textarea>
                                <div v-if="fieldMessage(activeLocale, 'description')" class="invalid-feedback d-block">{{ fieldMessage(activeLocale, 'description') }}</div>
                                <div v-else class="form-text">{{ t('ai_domain_policies.text_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-settings-3-line me-1"></i>{{ t('ai_domain_policies.sections.main') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="policy-domain-key" class="form-label">{{ t('ai_domain_policies.domain') }} <span class="text-danger">*</span></label>
                                <Select
                                    v-model="form.domain_key"
                                    input-id="policy-domain-key"
                                    :options="domainOptions"
                                    option-label="label"
                                    option-value="value"
                                    :disabled="isEdit"
                                    :invalid="Boolean(serverErrors.domain_key)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('domain_key')"
                                />
                                <div v-if="serverErrors.domain_key" class="invalid-feedback d-block">{{ serverErrors.domain_key[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_domain_policies.domain_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="policy-risk-level" class="form-label">{{ t('ai_domain_policies.risk_level') }}</label>
                                <Select
                                    v-model="form.risk_level"
                                    input-id="policy-risk-level"
                                    :options="riskOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.risk_level)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('risk_level')"
                                />
                                <div v-if="serverErrors.risk_level" class="invalid-feedback d-block">{{ serverErrors.risk_level[0] }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_domain_policies.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_domain_policies.status_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-shield-keyhole-line me-1"></i>{{ t('ai_domain_policies.controls') }}</h6>
                        <div class="row g-3">
                            <div v-for="control in controls" :key="control" class="col-md-6">
                                <div class="form-check">
                                    <input :id="`policy-${control}`" v-model="form[control]" class="form-check-input" type="checkbox">
                                    <label class="form-check-label" :for="`policy-${control}`">{{ t(`ai_domain_policies.${control}`) }}</label>
                                </div>
                                <div class="form-text">{{ t(`ai_domain_policies.${control}_hint`) }}</div>
                            </div>
                        </div>

                        <!-- Instructions to the model: technical, never translated (the model translates the disclaimer itself). -->
                        <h6 class="catalog-form-section"><i class="ri-robot-2-line me-1"></i>{{ t('ai_domain_policies.sections.model') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-12">
                                <label for="policy-system-prompt" class="form-label">{{ t('ai_domain_policies.system_prompt_addition') }}</label>
                                <textarea
                                    id="policy-system-prompt"
                                    v-model="form.system_prompt_addition"
                                    rows="3"
                                    maxlength="2000"
                                    dir="auto"
                                    class="form-control"
                                    :class="inputClass('system_prompt_addition')"
                                    @input="onFieldInput('system_prompt_addition')"
                                ></textarea>
                                <div v-if="messageFor('system_prompt_addition')" class="invalid-feedback d-block">{{ messageFor('system_prompt_addition') }}</div>
                                <div v-else class="form-text">{{ t('ai_domain_policies.system_prompt_addition_hint') }}</div>
                            </div>

                            <div class="col-12">
                                <label for="policy-disclaimer" class="form-label">{{ t('ai_domain_policies.disclaimer_text') }}</label>
                                <textarea
                                    id="policy-disclaimer"
                                    v-model="form.disclaimer_text"
                                    rows="2"
                                    maxlength="1000"
                                    dir="auto"
                                    class="form-control"
                                    :class="inputClass('disclaimer_text')"
                                    @input="onFieldInput('disclaimer_text')"
                                ></textarea>
                                <div v-if="messageFor('disclaimer_text')" class="invalid-feedback d-block">{{ messageFor('disclaimer_text') }}</div>
                                <div v-else class="form-text">{{ t('ai_domain_policies.disclaimer_text_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_domain_policies.saving') : t('save_changes') }}</button>
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

const RESOURCE_URI = '/api/admin/v1/ai-domain-policies';

const DOMAINS = ['legal', 'health', 'education', 'code', 'marketing', 'general_info'];
const RISKS = ['low', 'medium', 'high', 'critical'];

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

const controls = ['requires_jurisdiction', 'requires_triage', 'sandbox_required', 'allowlist_enforced'];

const DEFAULTS = {
    domain_key: 'legal',
    risk_level: 'medium',
    requires_jurisdiction: false,
    requires_triage: false,
    sandbox_required: false,
    allowlist_enforced: false,
    system_prompt_addition: '',
    disclaimer_text: '',
    status: true,
};

const form = reactive({ ...DEFAULTS, translations: {} });

const domainOptions = computed(() => DOMAINS.map((value) => ({ value, label: t(`ai_domain_policies.domain_key_${value}`) })));
const riskOptions = computed(() => RISKS.map((value) => ({ value, label: t(`ai_domain_policies.risk_${value}`) })));

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
        { name: 'name', labelKey: 'ai_domain_policies.name', max: 150, min: 2 },
        { name: 'description', labelKey: 'ai_domain_policies.description', max: 1000, required: false },
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
    system_prompt_addition: optionalMax('ai_domain_policies.system_prompt_addition', 2000),
    disclaimer_text: optionalMax('ai_domain_policies.disclaimer_text', 1000),
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('ai_domain_policies.create_title');
    }

    const record = props.record;
    const name = record?.translations?.find((item) => item.locale === locale.value)?.name || record?.name || '';

    return record?.id ? `${t('ai_domain_policies.edit_title')} #${record.id} ${name}`.trim() : t('ai_domain_policies.edit_title');
});

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
    form.domain_key = record?.domain_key ?? 'legal';
    form.risk_level = record?.risk_level ?? 'medium';
    controls.forEach((control) => {
        form[control] = Boolean(record?.[control]);
    });
    form.system_prompt_addition = record?.system_prompt_addition ?? '';
    form.disclaimer_text = record?.disclaimer_text ?? '';
    form.status = Boolean(record?.status ?? record?.is_active ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    const text = (value) => String(value ?? '').trim() || null;

    return {
        domain_key: form.domain_key,
        risk_level: form.risk_level,
        ...Object.fromEntries(controls.map((control) => [control, Boolean(form[control])])),
        system_prompt_addition: text(form.system_prompt_addition),
        disclaimer_text: text(form.disclaimer_text),
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
</style>
