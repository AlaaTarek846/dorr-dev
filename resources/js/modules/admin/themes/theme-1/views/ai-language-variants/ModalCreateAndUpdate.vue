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
                        <!-- The name people read: one tab per stored language (like countries) -->
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
                                <label for="variant-name" class="form-label">{{ t('ai_language_variants.name') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                                    <input
                                        id="variant-name"
                                        v-model="form.translations[activeLocale].name"
                                        type="text"
                                        maxlength="100"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'name')"
                                        :placeholder="t('ai_language_variants.name_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'name')"
                                    >
                                    <FormFieldFeedback v-bind="fieldFeedbackFor(activeLocale, 'name')" />
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'name')" class="invalid-feedback d-block">{{ fieldMessage(activeLocale, 'name') }}</div>
                                <div v-else class="form-text">{{ t('ai_language_variants.text_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-settings-3-line me-1"></i>{{ t('ai_language_variants.sections.main') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <label for="variant-language" class="form-label">{{ t('ai_language_variants.language') }} <span class="text-danger">*</span></label>
                                <Select
                                    v-model="form.language_id"
                                    input-id="variant-language"
                                    :options="languages"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('ai_language_variants.select_language')"
                                    :filter="true"
                                    :invalid="feedbackFor('language_id').show && feedbackFor('language_id').invalid"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('language_id')"
                                />
                                <div v-if="messageFor('language_id')" class="invalid-feedback d-block">{{ messageFor('language_id') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="variant-code" class="form-label">{{ t('ai_language_variants.code') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-code-line"></i></span>
                                    <input
                                        id="variant-code"
                                        v-model="form.code"
                                        type="text"
                                        maxlength="50"
                                        dir="ltr"
                                        class="form-control"
                                        :class="inputClass('code')"
                                        :placeholder="t('ai_language_variants.code_placeholder')"
                                        @input="onFieldInput('code')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackFor('code')" />
                                </div>
                                <div v-if="messageFor('code')" class="invalid-feedback d-block">{{ messageFor('code') }}</div>
                                <div v-else class="form-text">{{ t('ai_language_variants.code_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="variant-style" class="form-label">{{ t('ai_language_variants.style') }}</label>
                                <Select
                                    v-model="form.style"
                                    input-id="variant-style"
                                    :options="styleOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.style)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('style')"
                                />
                                <div v-if="serverErrors.style" class="invalid-feedback d-block">{{ serverErrors.style[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_language_variants.style_hint') }}</div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label d-block mb-2">{{ t('ai_language_variants.is_default') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.is_default }" role="switch" tabindex="0" :aria-checked="form.is_default" @click="form.is_default = !form.is_default" @keydown.enter.space.prevent="form.is_default = !form.is_default">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_language_variants.is_default_hint') }}</div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label d-block mb-2">{{ t('ai_language_variants.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_language_variants.status_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_language_variants.saving') : t('save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, maxLength, required } from '@vuelidate/validators';
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

const RESOURCE_URI = '/api/admin/v1/ai-language-variants';

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
const languages = ref([]);
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const DEFAULTS = { language_id: null, code: '', style: 'formal', is_default: false, status: true };

const form = reactive({ ...DEFAULTS, translations: {} });

const styleOptions = computed(() => ['formal', 'conversational'].map((value) => ({ value, label: t(`ai_language_variants.style_${value}`) })));

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
        { name: 'name', labelKey: 'ai_language_variants.name', max: 100, min: 2 },
    ],
    getV$: () => v$.value,
});

const rules = computed(() => ({
    translations: translationRules.value,
    language_id: {
        required: helpers.withMessage(() => t('validation.required', { field: t('ai_language_variants.language') }), required),
    },
    code: {
        required: helpers.withMessage(() => t('validation.required', { field: t('ai_language_variants.code') }), required),
        maxLength: helpers.withMessage(() => t('validation.max.string', { field: t('ai_language_variants.code'), max: 50 }), maxLength(50)),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('ai_language_variants.create_title');
    }

    const record = props.record;
    const name = record?.translations?.find((item) => item.locale === locale.value)?.name || record?.name || '';

    return record?.id ? `${t('ai_language_variants.edit_title')} #${record.id} ${name}`.trim() : t('ai_language_variants.edit_title');
});

async function loadLanguages() {
    try {
        // ai-language-variants.view may read the AI languages (AiAdminScreens::READERS).
        const { data } = await adminAxios.get('/api/admin/v1/ai-languages');
        languages.value = data.data ?? [];
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
    form.language_id = record?.language_id ?? record?.language?.id ?? null;
    form.code = record?.code ?? '';
    form.style = record?.style ?? 'formal';
    form.is_default = Boolean(record?.is_default ?? false);
    form.status = Boolean(record?.status ?? record?.is_active ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    return {
        language_id: form.language_id,
        code: String(form.code ?? '').trim(),
        style: form.style,
        is_default: form.is_default,
        status: form.status,
        translations: buildTranslationsPayload(),
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
    onOpen: async () => {
        await Promise.all([ensureLanguagesLoaded(), languages.value.length ? null : loadLanguages()]);
    },
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
