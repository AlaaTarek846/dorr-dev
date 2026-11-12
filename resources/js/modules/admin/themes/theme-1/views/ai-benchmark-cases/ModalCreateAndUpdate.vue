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

                <!-- No language tabs: a case is test data (the question, keywords, notes), not a label people read. -->
                <!-- Header and footer stay, only the body scrolls (catalog-list.css .catalog-scroll-form). -->
                <form class="catalog-scroll-form" @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <h6 class="catalog-form-section catalog-form-section--first"><i class="ri-question-line me-1"></i>{{ t('ai_benchmark_cases.sections.question') }}</h6>
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="case-prompt" class="form-label">{{ t('ai_benchmark_cases.prompt') }} <span class="text-danger">*</span></label>
                                <textarea
                                    id="case-prompt"
                                    v-model="form.prompt"
                                    rows="3"
                                    maxlength="5000"
                                    class="form-control"
                                    :class="inputClass('prompt')"
                                    @input="onFieldInput('prompt')"
                                ></textarea>
                                <div v-if="messageFor('prompt')" class="invalid-feedback d-block">{{ messageFor('prompt') }}</div>
                                <div v-else class="form-text">{{ t('ai_benchmark_cases.prompt_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="case-expected-behavior" class="form-label">{{ t('ai_benchmark_cases.expected_behavior') }} <span class="text-danger">*</span></label>
                                <Select
                                    v-model="form.expected_behavior"
                                    input-id="case-expected-behavior"
                                    :options="behaviorOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.expected_behavior)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('expected_behavior')"
                                />
                                <div v-if="serverErrors.expected_behavior" class="invalid-feedback d-block">{{ serverErrors.expected_behavior[0] }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_benchmark_cases.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_benchmark_cases.status_hint') }}</div>
                            </div>

                            <div class="col-12">
                                <label for="case-keywords" class="form-label">{{ t('ai_benchmark_cases.expected_answer_keywords') }}</label>
                                <input id="case-keywords" v-model="keywordsText" type="text" class="form-control" :class="{ 'is-invalid': keywordsError }" :placeholder="t('ai_benchmark_cases.expected_answer_keywords_hint')" @input="onFieldInput('expected_answer_keywords')">
                                <div v-if="keywordsError" class="invalid-feedback d-block">{{ keywordsError }}</div>
                                <div v-else class="form-text">{{ t('ai_benchmark_cases.expected_answer_keywords_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-price-tag-3-line me-1"></i>{{ t('ai_benchmark_cases.sections.classification') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="case-domain-key" class="form-label">{{ t('ai_benchmark_cases.domain') }}</label>
                                <Select
                                    v-model="form.domain_key"
                                    input-id="case-domain-key"
                                    :options="domainOptions"
                                    option-label="label"
                                    option-value="value"
                                    :placeholder="'-'"
                                    :show-clear="true"
                                    :invalid="Boolean(serverErrors.domain_key)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('domain_key')"
                                />
                                <div v-if="serverErrors.domain_key" class="invalid-feedback d-block">{{ serverErrors.domain_key[0] }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="case-difficulty" class="form-label">{{ t('ai_benchmark_cases.difficulty') }} <span class="text-danger">*</span></label>
                                <Select
                                    v-model="form.difficulty"
                                    input-id="case-difficulty"
                                    :options="difficultyOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.difficulty)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('difficulty')"
                                />
                                <div v-if="serverErrors.difficulty" class="invalid-feedback d-block">{{ serverErrors.difficulty[0] }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="case-risk-level" class="form-label">{{ t('ai_benchmark_cases.risk_level') }}</label>
                                <Select
                                    v-model="form.risk_level"
                                    input-id="case-risk-level"
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

                            <div class="col-md-4">
                                <label for="case-language" class="form-label">{{ t('ai_benchmark_cases.language') }}</label>
                                <Select
                                    v-model="form.language"
                                    input-id="case-language"
                                    :options="languageOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.language)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('language')"
                                />
                                <div v-if="serverErrors.language" class="invalid-feedback d-block">{{ serverErrors.language[0] }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="case-country" class="form-label">{{ t('ai_benchmark_cases.country_code') }}</label>
                                <div class="input-group">
                                    <input id="case-country" v-model="form.country_code" type="text" maxlength="2" dir="ltr" class="form-control text-uppercase" :class="inputClass('country_code')" @input="onFieldInput('country_code')">
                                    <FormFieldFeedback v-bind="feedbackFor('country_code')" />
                                </div>
                                <div v-if="messageFor('country_code')" class="invalid-feedback d-block">{{ messageFor('country_code') }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="case-task-type" class="form-label">{{ t('ai_benchmark_cases.task_type') }}</label>
                                <div class="input-group">
                                    <input id="case-task-type" v-model="form.task_type" type="text" maxlength="60" dir="ltr" class="form-control" :class="inputClass('task_type')" @input="onFieldInput('task_type')">
                                    <FormFieldFeedback v-bind="feedbackFor('task_type')" />
                                </div>
                                <div v-if="messageFor('task_type')" class="invalid-feedback d-block">{{ messageFor('task_type') }}</div>
                            </div>

                            <div class="col-12 mb-2">
                                <label for="case-notes" class="form-label">{{ t('ai_benchmark_cases.notes') }}</label>
                                <textarea id="case-notes" v-model="form.notes" rows="2" maxlength="1000" class="form-control" :class="inputClass('notes')" @input="onFieldInput('notes')"></textarea>
                                <div v-if="messageFor('notes')" class="invalid-feedback d-block">{{ messageFor('notes') }}</div>
                                <div v-else class="form-text">{{ t('ai_benchmark_cases.notes_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_benchmark_cases.saving') : t('save_changes') }}</button>
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
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../../../utils/catalog';

const RESOURCE_URI = '/api/admin/v1/ai-benchmark-cases';

// The domains AiDomainPolicy knows (domain_key), the difficulty tiers and behaviours of AiBenchmarkCase.
const DOMAINS = ['legal', 'health', 'education', 'code', 'marketing', 'general_info'];
const DIFFICULTIES = ['easy', 'hard', 'adversarial', 'insufficient_info'];
const BEHAVIORS = ['answer', 'abstain', 'ask_clarification'];
const RISKS = ['low', 'medium', 'high', 'critical'];
const LANGUAGES = ['ar', 'en'];

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const keywordsText = ref('');
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const DEFAULTS = {
    prompt: '',
    domain_key: null,
    difficulty: 'easy',
    risk_level: 'low',
    language: 'ar',
    country_code: '',
    task_type: 'qa',
    expected_behavior: 'answer',
    notes: '',
    status: true,
};

const form = reactive({ ...DEFAULTS });

const plainOptions = (values) => values.map((value) => ({ value, label: value }));
const domainOptions = plainOptions(DOMAINS);
const riskOptions = plainOptions(RISKS);
const languageOptions = plainOptions(LANGUAGES);
const difficultyOptions = computed(() => DIFFICULTIES.map((value) => ({ value, label: t(`ai_benchmark_cases.difficulty_${value}`) })));
const behaviorOptions = computed(() => BEHAVIORS.map((value) => ({ value, label: t(`ai_benchmark_cases.behavior_${value}`) })));

const keywordsError = computed(() => {
    const errors = Object.entries(serverErrors).find(([key]) => key === 'expected_answer_keywords' || key.startsWith('expected_answer_keywords.'));

    return errors ? errors[1][0] : null;
});

const isBlank = (value) => value === null || value === undefined || value === '';

const rules = computed(() => ({
    prompt: {
        required: helpers.withMessage(() => t('validation.required', { field: t('ai_benchmark_cases.prompt') }), required),
        maxLength: helpers.withMessage(() => t('validation.max.string', { field: t('ai_benchmark_cases.prompt'), max: 5000 }), maxLength(5000)),
    },
    country_code: {
        size: helpers.withMessage(() => t('ai_benchmark_cases.country_code_size'), (value) => isBlank(value) || String(value).length === 2),
    },
    task_type: {
        maxLength: helpers.withMessage(() => t('validation.max.string', { field: t('ai_benchmark_cases.task_type'), max: 60 }), maxLength(60)),
    },
    notes: {
        maxLength: helpers.withMessage(() => t('validation.max.string', { field: t('ai_benchmark_cases.notes'), max: 1000 }), maxLength(1000)),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_benchmark_cases.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_benchmark_cases.create_title')
));

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
    Object.keys(serverErrors).filter((key) => key === field || key.startsWith(`${field}.`)).forEach((key) => delete serverErrors[key]);
    v$.value[field]?.$touch();
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    Object.assign(form, DEFAULTS);
    keywordsText.value = '';
    resetValidation();
}

function fillForm(record) {
    form.prompt = record?.prompt ?? '';
    form.domain_key = record?.domain_key || null;
    form.difficulty = record?.difficulty ?? 'easy';
    form.risk_level = record?.risk_level ?? 'low';
    form.language = record?.language ?? 'ar';
    form.country_code = record?.country_code ?? '';
    form.task_type = record?.task_type ?? 'qa';
    form.expected_behavior = record?.expected_behavior ?? 'answer';
    form.notes = record?.notes ?? '';
    form.status = Boolean(record?.status ?? record?.is_active ?? true);
    keywordsText.value = Array.isArray(record?.expected_answer_keywords) ? record.expected_answer_keywords.join(', ') : '';
    resetValidation();
}

function buildPayload() {
    const keywords = keywordsText.value
        .split(',')
        .map((keyword) => keyword.trim())
        .filter((keyword) => keyword.length > 0);

    return {
        prompt: form.prompt,
        domain_key: form.domain_key || null,
        difficulty: form.difficulty,
        risk_level: form.risk_level,
        language: form.language,
        country_code: String(form.country_code ?? '').trim().toUpperCase() || null,
        task_type: String(form.task_type ?? '').trim() || null,
        expected_behavior: form.expected_behavior,
        expected_answer_keywords: keywords.length ? keywords : null,
        notes: String(form.notes ?? '').trim() || null,
        status: form.status,
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
});

onMounted(() => {
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
