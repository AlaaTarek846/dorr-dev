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
                        <!-- The name the admin reads: one tab per stored language (like countries). Notes stay untranslated. -->
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
                                <label for="evaluation-name" class="form-label">{{ t('ai_language_evaluations.name') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                                    <input
                                        id="evaluation-name"
                                        v-model="form.translations[activeLocale].name"
                                        type="text"
                                        maxlength="150"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'name')"
                                        :placeholder="t('ai_language_evaluations.name_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'name')"
                                    >
                                    <FormFieldFeedback v-bind="fieldFeedbackFor(activeLocale, 'name')" />
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'name')" class="invalid-feedback d-block">{{ fieldMessage(activeLocale, 'name') }}</div>
                                <div v-else class="form-text">{{ t('ai_language_evaluations.text_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-translate-2 me-1"></i>{{ t('ai_language_evaluations.sections.target') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <label for="evaluation-language" class="form-label">{{ t('ai_language_evaluations.language') }} <span class="text-danger">*</span></label>
                                <Select
                                    v-model="form.language_id"
                                    input-id="evaluation-language"
                                    :options="languages"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('ai_language_evaluations.select_language')"
                                    :filter="true"
                                    :invalid="feedbackFor('language_id').show && feedbackFor('language_id').invalid"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onLanguageChange"
                                />
                                <div v-if="messageFor('language_id')" class="invalid-feedback d-block">{{ messageFor('language_id') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="evaluation-variant" class="form-label">{{ t('ai_language_evaluations.variant') }}</label>
                                <Select
                                    v-model="form.variant_id"
                                    input-id="evaluation-variant"
                                    :options="variantOptions"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('ai_language_evaluations.select_variant')"
                                    :filter="true"
                                    :filter-fields="['name', 'code']"
                                    :show-clear="true"
                                    :invalid="Boolean(serverErrors.variant_id)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('variant_id')"
                                >
                                    <template #option="{ option }">
                                        <span>{{ option.name }}</span>
                                        <span class="text-muted fs-11 ms-2" dir="ltr">{{ option.code }}</span>
                                        <span v-if="option.deleted" class="badge bg-danger-transparent ms-2">{{ t('catalog.deleted_badge') }}</span>
                                        <span v-else-if="!option.status" class="badge bg-secondary-transparent ms-2">{{ t('ai_language_evaluations.filter_inactive') }}</span>
                                    </template>
                                </Select>
                                <div v-if="serverErrors.variant_id" class="invalid-feedback d-block">{{ serverErrors.variant_id[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_language_evaluations.variant_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-bar-chart-box-line me-1"></i>{{ t('ai_language_evaluations.sections.result') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-md-4">
                                <label for="evaluation-test-cases" class="form-label">{{ t('ai_language_evaluations.test_case_count') }}</label>
                                <div class="input-group">
                                    <input id="evaluation-test-cases" v-model.number="form.test_case_count" type="number" min="0" class="form-control" :class="inputClass('test_case_count')" @input="onFieldInput('test_case_count')">
                                    <FormFieldFeedback v-bind="feedbackFor('test_case_count')" />
                                </div>
                                <div v-if="messageFor('test_case_count')" class="invalid-feedback d-block">{{ messageFor('test_case_count') }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="evaluation-pass-rate" class="form-label">{{ t('ai_language_evaluations.pass_rate') }}</label>
                                <div class="input-group">
                                    <input id="evaluation-pass-rate" v-model.number="form.pass_rate" type="number" min="0" max="100" step="0.01" class="form-control" :class="inputClass('pass_rate')" @input="onFieldInput('pass_rate')">
                                    <FormFieldFeedback v-bind="feedbackFor('pass_rate')" />
                                </div>
                                <div v-if="messageFor('pass_rate')" class="invalid-feedback d-block">{{ messageFor('pass_rate') }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="evaluation-result" class="form-label">{{ t('ai_language_evaluations.evaluation_status') }}</label>
                                <Select
                                    v-model="form.evaluation_status"
                                    input-id="evaluation-result"
                                    :options="evaluationStatusOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.evaluation_status)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('evaluation_status')"
                                />
                                <div v-if="serverErrors.evaluation_status" class="invalid-feedback d-block">{{ serverErrors.evaluation_status[0] }}</div>
                            </div>

                            <div class="col-12">
                                <label for="evaluation-notes" class="form-label">{{ t('ai_language_evaluations.notes') }}</label>
                                <textarea
                                    id="evaluation-notes"
                                    v-model="form.notes"
                                    class="form-control"
                                    :class="inputClass('notes')"
                                    rows="3"
                                    maxlength="2000"
                                    :placeholder="t('ai_language_evaluations.notes_placeholder')"
                                    @input="onFieldInput('notes')"
                                ></textarea>
                                <div v-if="messageFor('notes')" class="invalid-feedback d-block">{{ messageFor('notes') }}</div>
                                <div v-else class="form-text">{{ t('ai_language_evaluations.notes_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_language_evaluations.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_language_evaluations.status_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_language_evaluations.saving') : t('save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, integer, maxLength, maxValue, minValue, required } from '@vuelidate/validators';
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

const RESOURCE_URI = '/api/admin/v1/ai-language-evaluations';

const EVALUATION_STATUSES = ['pending', 'running', 'passed', 'failed'];

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
const variants = ref([]);
const currentVariant = ref(null);
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const DEFAULTS = {
    language_id: null,
    variant_id: null,
    test_case_count: 0,
    pass_rate: null,
    evaluation_status: 'pending',
    notes: '',
    status: true,
};

const form = reactive({ ...DEFAULTS, translations: {} });

const evaluationStatusOptions = computed(() => EVALUATION_STATUSES.map((value) => ({ value, label: t(`ai_language_evaluations.status_${value}`) })));

// The variants of the picked language (dropdown = not in the trash); a trashed variant the evaluation already uses stays listed.
const variantOptions = computed(() => {
    const list = form.language_id ? variants.value.filter((variant) => variant.language_id === form.language_id) : variants.value;
    const current = currentVariant.value;

    return current && current.deleted && ! list.some((row) => row.id === current.id) ? [...list, current] : list;
});

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
        { name: 'name', labelKey: 'ai_language_evaluations.name', max: 150, min: 2 },
    ],
    getV$: () => v$.value,
});

const isBlank = (value) => value === null || value === undefined || value === '';

function numberRules(labelKey, { min = 0, max = null, whole = true } = {}) {
    const label = () => t(labelKey);
    const rules = {
        minValue: helpers.withMessage(() => t('validation.min.numeric', { field: label(), min }), (value) => isBlank(value) || minValue(min).$validator(value)),
    };

    if (whole) {
        rules.integer = helpers.withMessage(() => t('validation.integer', { field: label() }), (value) => isBlank(value) || integer.$validator(value));
    }

    if (max !== null) {
        rules.maxValue = helpers.withMessage(() => t('validation.max.numeric', { field: label(), max }), (value) => isBlank(value) || maxValue(max).$validator(value));
    }

    return rules;
}

const rules = computed(() => ({
    translations: translationRules.value,
    language_id: {
        required: helpers.withMessage(() => t('validation.required', { field: t('ai_language_evaluations.language') }), required),
    },
    test_case_count: numberRules('ai_language_evaluations.test_case_count'),
    pass_rate: numberRules('ai_language_evaluations.pass_rate', { max: 100, whole: false }),
    notes: {
        maxLength: helpers.withMessage(() => t('validation.max.string', { field: t('ai_language_evaluations.notes'), max: 2000 }), maxLength(2000)),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('ai_language_evaluations.create_title');
    }

    const record = props.record;
    const name = record?.translations?.find((item) => item.locale === locale.value)?.name || record?.name || '';

    return record?.id ? `${t('ai_language_evaluations.edit_title')} #${record.id} ${name}`.trim() : t('ai_language_evaluations.edit_title');
});

async function loadOptions() {
    try {
        // The variant picker: ai-language-evaluations.view may read it (AiAdminScreens::READERS).
        const [languagesRes, variantsRes] = await Promise.all([
            adminAxios.get('/api/admin/v1/ai-languages'),
            adminAxios.get('/api/admin/v1/ai-language-variants/dropdown'),
        ]);
        languages.value = languagesRes.data.data ?? [];
        variants.value = variantsRes.data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

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

// A variant belongs to one language: changing the language drops a variant of another one.
function onLanguageChange() {
    onFieldInput('language_id');

    if (form.variant_id && ! variantOptions.value.some((variant) => variant.id === form.variant_id)) {
        form.variant_id = null;
    }
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    Object.assign(form, DEFAULTS);
    currentVariant.value = null;
    resetTranslations();
    resetValidation();
}

function fillForm(record) {
    form.language_id = record?.language_id ?? record?.language?.id ?? null;
    form.variant_id = record?.variant_id ?? record?.variant?.id ?? null;
    form.test_case_count = record?.test_case_count ?? 0;
    form.pass_rate = record?.pass_rate ?? null;
    form.evaluation_status = record?.evaluation_status ?? 'pending';
    form.notes = record?.notes ?? '';
    form.status = Boolean(record?.status ?? record?.is_active ?? true);
    currentVariant.value = record?.variant ? { ...record.variant, language_id: form.language_id } : null;
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    return {
        language_id: form.language_id,
        variant_id: form.variant_id ?? null,
        test_case_count: isBlank(form.test_case_count) ? 0 : form.test_case_count,
        pass_rate: isBlank(form.pass_rate) ? null : form.pass_rate,
        evaluation_status: form.evaluation_status,
        notes: String(form.notes ?? '').trim() || null,
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
        await Promise.all([ensureLanguagesLoaded(), loadOptions()]);
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
