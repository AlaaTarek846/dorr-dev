<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header catalog-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">{{ modalTitle }}</h6>
                        <button type="button" class="btn-close" aria-label="Close" @click="close"></button>
                    </div>
                </div>

                <form @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="case-prompt" class="form-label">
                                    {{ t('ai_benchmark_cases.prompt') }} <span class="text-danger">*</span>
                                </label>
                                <textarea
                                    id="case-prompt"
                                    v-model="form.prompt"
                                    rows="3"
                                    class="form-control"
                                    :class="fieldClass('prompt')"
                                    @input="onFieldInput('prompt')"
                                ></textarea>
                                <div v-if="fieldMessage('prompt')" class="invalid-feedback d-block">{{ fieldMessage('prompt') }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="case-domain-key" class="form-label">{{ t('ai_benchmark_cases.domain') }}</label>
                                <select id="case-domain-key" v-model="form.domain_key" class="form-select">
                                    <option value="">-</option>
                                    <option value="legal">legal</option>
                                    <option value="health">health</option>
                                    <option value="education">education</option>
                                    <option value="code">code</option>
                                    <option value="marketing">marketing</option>
                                    <option value="general_info">general_info</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label for="case-difficulty" class="form-label">
                                    {{ t('ai_benchmark_cases.difficulty') }} <span class="text-danger">*</span>
                                </label>
                                <select id="case-difficulty" v-model="form.difficulty" class="form-select">
                                    <option value="easy">{{ t('ai_benchmark_cases.difficulty_easy') }}</option>
                                    <option value="hard">{{ t('ai_benchmark_cases.difficulty_hard') }}</option>
                                    <option value="adversarial">{{ t('ai_benchmark_cases.difficulty_adversarial') }}</option>
                                    <option value="insufficient_info">{{ t('ai_benchmark_cases.difficulty_insufficient_info') }}</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label for="case-risk-level" class="form-label">{{ t('ai_benchmark_cases.risk_level') }}</label>
                                <select id="case-risk-level" v-model="form.risk_level" class="form-select">
                                    <option value="low">low</option>
                                    <option value="medium">medium</option>
                                    <option value="high">high</option>
                                    <option value="critical">critical</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label for="case-language" class="form-label">{{ t('ai_benchmark_cases.language') }}</label>
                                <select id="case-language" v-model="form.language" class="form-select">
                                    <option value="ar">ar</option>
                                    <option value="en">en</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label for="case-country" class="form-label">{{ t('ai_benchmark_cases.country_code') }}</label>
                                <input id="case-country" v-model="form.country_code" type="text" maxlength="2" class="form-control text-uppercase">
                            </div>

                            <div class="col-md-4">
                                <label for="case-task-type" class="form-label">{{ t('ai_benchmark_cases.task_type') }}</label>
                                <input id="case-task-type" v-model="form.task_type" type="text" class="form-control">
                            </div>

                            <div class="col-md-6">
                                <label for="case-expected-behavior" class="form-label">
                                    {{ t('ai_benchmark_cases.expected_behavior') }} <span class="text-danger">*</span>
                                </label>
                                <select id="case-expected-behavior" v-model="form.expected_behavior" class="form-select">
                                    <option value="answer">{{ t('ai_benchmark_cases.behavior_answer') }}</option>
                                    <option value="abstain">{{ t('ai_benchmark_cases.behavior_abstain') }}</option>
                                    <option value="ask_clarification">{{ t('ai_benchmark_cases.behavior_ask_clarification') }}</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_benchmark_cases.status') }}</label>
                                <div
                                    class="toggle toggle-success mb-0"
                                    :class="{ on: form.is_active }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_active = !form.is_active"
                                    @keydown.enter.space.prevent="form.is_active = !form.is_active"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="case-keywords" class="form-label">{{ t('ai_benchmark_cases.expected_answer_keywords') }}</label>
                                <input id="case-keywords" v-model="keywordsText" type="text" class="form-control" :placeholder="t('ai_benchmark_cases.expected_answer_keywords_hint')">
                            </div>

                            <div class="col-12">
                                <label for="case-notes" class="form-label">{{ t('ai_benchmark_cases.notes') }}</label>
                                <textarea id="case-notes" v-model="form.notes" rows="2" class="form-control"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('ai_benchmark_cases.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, required } from '@vuelidate/validators';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';

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
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');
const keywordsText = ref('');

const form = reactive({
    prompt: '',
    domain_key: '',
    difficulty: 'easy',
    risk_level: 'low',
    language: 'ar',
    country_code: '',
    task_type: 'qa',
    expected_behavior: 'answer',
    notes: '',
    is_active: true,
});

const rules = computed(() => ({
    prompt: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('ai_benchmark_cases.prompt') }),
            required,
        ),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_benchmark_cases.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_benchmark_cases.create_title')
));

function fieldClass(field) {
    const feedback = fieldFeedback(v$.value[field], serverErrors[field]?.[0], form[field]);
    return { 'is-invalid': feedback.show && feedback.invalid, 'is-valid': feedback.show && feedback.valid };
}

function fieldMessage(field) {
    return v$.value[field]?.$errors?.[0]?.$message || serverErrors[field]?.[0] || null;
}

function onFieldInput(field) {
    delete serverErrors[field];
    v$.value.$touch();
}

function resetForm() {
    form.prompt = '';
    form.domain_key = '';
    form.difficulty = 'easy';
    form.risk_level = 'low';
    form.language = 'ar';
    form.country_code = '';
    form.task_type = 'qa';
    form.expected_behavior = 'answer';
    form.notes = '';
    form.is_active = true;
    keywordsText.value = '';
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.prompt = record?.prompt ?? '';
    form.domain_key = record?.domain_key ?? '';
    form.difficulty = record?.difficulty ?? 'easy';
    form.risk_level = record?.risk_level ?? 'low';
    form.language = record?.language ?? 'ar';
    form.country_code = record?.country_code ?? '';
    form.task_type = record?.task_type ?? 'qa';
    form.expected_behavior = record?.expected_behavior ?? 'answer';
    form.notes = record?.notes ?? '';
    form.is_active = Boolean(record?.is_active ?? true);
    keywordsText.value = Array.isArray(record?.expected_answer_keywords) ? record.expected_answer_keywords.join(', ') : '';
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function openModal() {
    if (! modalElement.value) return;
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

async function submit() {
    v$.value.$touch();

    if (v$.value.$invalid) {
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;
    applyApiErrors(serverErrors, {});

    const keywords = keywordsText.value
        .split(',')
        .map((keyword) => keyword.trim())
        .filter((keyword) => keyword.length > 0);

    const payload = { ...form, expected_answer_keywords: keywords.length ? keywords : null };

    try {
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/ai-benchmark-cases/${props.record.id}`, payload);
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-benchmark-cases', payload);
            showSuccess(extractApiMessage(response, t('toast.created')));
        }

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

watch(() => props.show, (show) => {
    if (show) {
        if (isEdit.value && props.record) {
            fillForm(props.record);
        } else {
            resetForm();
        }
        openModal();
    } else {
        closeModal();
    }
});

onMounted(() => {
    modalElement.value?.addEventListener('hidden.bs.modal', () => emit('close'));
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
