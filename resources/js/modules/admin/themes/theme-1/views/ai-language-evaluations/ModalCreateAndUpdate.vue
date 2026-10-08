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
                                <label for="evaluation-name" class="form-label">
                                    {{ t('ai_language_evaluations.name') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="evaluation-name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="fieldClass('name')"
                                    :placeholder="t('ai_language_evaluations.name_placeholder')"
                                    @input="onFieldInput('name')"
                                >
                                <div v-if="fieldMessage('name')" class="invalid-feedback d-block">{{ fieldMessage('name') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="evaluation-language" class="form-label">
                                    {{ t('ai_language_evaluations.language') }} <span class="text-danger">*</span>
                                </label>
                                <select
                                    id="evaluation-language"
                                    v-model="form.language_id"
                                    class="form-select"
                                    :class="fieldClass('language_id')"
                                    @change="onFieldInput('language_id')"
                                >
                                    <option :value="null">{{ t('ai_language_evaluations.select_language') }}</option>
                                    <option v-for="language in languages" :key="language.id" :value="language.id">{{ language.name }}</option>
                                </select>
                                <div v-if="fieldMessage('language_id')" class="invalid-feedback d-block">{{ fieldMessage('language_id') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="evaluation-variant" class="form-label">{{ t('ai_language_evaluations.variant') }}</label>
                                <select id="evaluation-variant" v-model="form.variant_id" class="form-select">
                                    <option :value="null">{{ t('ai_language_evaluations.select_variant') }}</option>
                                    <option v-for="variant in filteredVariants" :key="variant.id" :value="variant.id">{{ variant.name }}</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label for="evaluation-test-cases" class="form-label">{{ t('ai_language_evaluations.test_case_count') }}</label>
                                <input id="evaluation-test-cases" v-model.number="form.test_case_count" type="number" min="0" class="form-control">
                            </div>

                            <div class="col-md-4">
                                <label for="evaluation-pass-rate" class="form-label">{{ t('ai_language_evaluations.pass_rate') }}</label>
                                <input id="evaluation-pass-rate" v-model.number="form.pass_rate" type="number" min="0" max="100" step="0.01" class="form-control">
                            </div>

                            <div class="col-md-4">
                                <label for="evaluation-status" class="form-label">{{ t('ai_language_evaluations.status') }}</label>
                                <select id="evaluation-status" v-model="form.status" class="form-select">
                                    <option value="pending">{{ t('ai_language_evaluations.status_pending') }}</option>
                                    <option value="running">{{ t('ai_language_evaluations.status_running') }}</option>
                                    <option value="passed">{{ t('ai_language_evaluations.status_passed') }}</option>
                                    <option value="failed">{{ t('ai_language_evaluations.status_failed') }}</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label for="evaluation-notes" class="form-label">{{ t('ai_language_evaluations.notes') }}</label>
                                <textarea
                                    id="evaluation-notes"
                                    v-model="form.notes"
                                    class="form-control"
                                    rows="3"
                                    :placeholder="t('ai_language_evaluations.notes_placeholder')"
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('ai_language_evaluations.saving') : t('save_changes') }}
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
const { stringFieldRules, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const languages = ref([]);
const variants = ref([]);
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    name: '',
    language_id: null,
    variant_id: null,
    test_case_count: 0,
    pass_rate: null,
    status: 'pending',
    notes: '',
});

const filteredVariants = computed(() => (
    form.language_id ? variants.value.filter((variant) => variant.language_id === form.language_id) : variants.value
));

const rules = computed(() => ({
    name: stringFieldRules('ai_language_evaluations.name', 150),
    language_id: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('ai_language_evaluations.language') }),
            required,
        ),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_language_evaluations.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_language_evaluations.create_title')
));

async function loadOptions() {
    try {
        const [languagesRes, variantsRes] = await Promise.all([
            adminAxios.get('/api/admin/v1/ai-languages'),
            adminAxios.get('/api/admin/v1/ai-language-variants'),
        ]);
        languages.value = languagesRes.data.data ?? [];
        variants.value = variantsRes.data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

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
    form.name = '';
    form.language_id = null;
    form.variant_id = null;
    form.test_case_count = 0;
    form.pass_rate = null;
    form.status = 'pending';
    form.notes = '';
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.name = record?.name ?? '';
    form.language_id = record?.language_id ?? record?.language?.id ?? null;
    form.variant_id = record?.variant_id ?? record?.variant?.id ?? null;
    form.test_case_count = record?.test_case_count ?? 0;
    form.pass_rate = record?.pass_rate ?? null;
    form.status = record?.status ?? 'pending';
    form.notes = record?.notes ?? '';
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

    const payload = { ...form };

    try {
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/ai-language-evaluations/${props.record.id}`, payload);
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-language-evaluations', payload);
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
    loadOptions();
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
