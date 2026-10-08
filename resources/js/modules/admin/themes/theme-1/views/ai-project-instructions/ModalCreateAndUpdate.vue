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
                            <div class="col-md-6">
                                <label for="project-instruction-project" class="form-label">
                                    {{ t('ai_project_instructions.project_id') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="project-instruction-project"
                                    v-model.number="form.project_id"
                                    type="number"
                                    min="1"
                                    class="form-control"
                                    :class="fieldClass('project_id')"
                                    :disabled="isEdit"
                                    :placeholder="t('ai_project_instructions.project_id_placeholder')"
                                    @input="onFieldInput('project_id')"
                                >
                                <div v-if="fieldMessage('project_id')" class="invalid-feedback d-block">{{ fieldMessage('project_id') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="project-instruction-priority" class="form-label">{{ t('ai_project_instructions.priority') }}</label>
                                <input id="project-instruction-priority" v-model.number="form.priority" type="number" min="0" class="form-control">
                            </div>

                            <div class="col-12">
                                <label for="project-instruction-text" class="form-label">
                                    {{ t('ai_project_instructions.instruction') }} <span class="text-danger">*</span>
                                </label>
                                <textarea
                                    id="project-instruction-text"
                                    v-model="form.instruction"
                                    class="form-control"
                                    :class="fieldClass('instruction')"
                                    rows="4"
                                    :placeholder="t('ai_project_instructions.instruction_placeholder')"
                                    @input="onFieldInput('instruction')"
                                ></textarea>
                                <div v-if="fieldMessage('instruction')" class="invalid-feedback d-block">{{ fieldMessage('instruction') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_project_instructions.is_active') }}</label>
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
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('ai_project_instructions.saving') : t('save_changes') }}
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

const form = reactive({
    project_id: null,
    instruction: '',
    priority: 0,
    is_active: true,
});

const rules = computed(() => ({
    project_id: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('ai_project_instructions.project_id') }),
            required,
        ),
    },
    instruction: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('ai_project_instructions.instruction') }),
            required,
        ),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_project_instructions.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_project_instructions.create_title')
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
    form.project_id = null;
    form.instruction = '';
    form.priority = 0;
    form.is_active = true;
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.project_id = record?.project_id ?? null;
    form.instruction = record?.instruction ?? '';
    form.priority = record?.priority ?? 0;
    form.is_active = Boolean(record?.is_active ?? true);
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
            delete payload.project_id;
            response = await adminAxios.put(`/api/admin/v1/ai-project-instructions/${props.record.id}`, payload);
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-project-instructions', payload);
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
