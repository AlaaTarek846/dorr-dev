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

                <!-- No language tabs: the instruction is a prompt sent to the AI as it is (technical, never translated). -->
                <!-- Header and footer stay, only the body scrolls (catalog-list.css .catalog-scroll-form). -->
                <form class="catalog-scroll-form" @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <label for="conv-instruction-conversation_id" class="form-label">{{ t('ai_conversation_instructions.conversation_id') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-chat-3-line"></i></span>
                                    <input
                                        id="conv-instruction-conversation_id"
                                        v-model.number="form.conversation_id"
                                        type="number"
                                        min="1"
                                        class="form-control"
                                        :class="inputClass('conversation_id')"
                                        :disabled="isEdit"
                                        :placeholder="t('ai_conversation_instructions.conversation_id_placeholder')"
                                        @input="onFieldInput('conversation_id')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackFor('conversation_id')" />
                                </div>
                                <div v-if="messageFor('conversation_id')" class="invalid-feedback d-block">{{ messageFor('conversation_id') }}</div>
                                <div v-else class="form-text">{{ t('ai_conversation_instructions.conversation_id_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="conv-instruction-source" class="form-label">{{ t('ai_conversation_instructions.source_type') }}</label>
                                <Select
                                    v-model="form.source_type"
                                    input-id="conv-instruction-source"
                                    :options="sourceOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.source_type)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('source_type')"
                                />
                                <div v-if="serverErrors.source_type" class="invalid-feedback d-block">{{ serverErrors.source_type[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_conversation_instructions.source_hint') }}</div>
                            </div>

                            <div class="col-12">
                                <label for="conv-instruction-text" class="form-label">{{ t('ai_conversation_instructions.instruction') }} <span class="text-danger">*</span></label>
                                <textarea
                                    id="conv-instruction-text"
                                    v-model="form.instruction"
                                    class="form-control"
                                    :class="inputClass('instruction')"
                                    rows="5"
                                    maxlength="5000"
                                    :placeholder="t('ai_conversation_instructions.instruction_placeholder')"
                                    @input="onFieldInput('instruction')"
                                ></textarea>
                                <div v-if="messageFor('instruction')" class="invalid-feedback d-block">{{ messageFor('instruction') }}</div>
                                <div v-else class="form-text">{{ t('ai_conversation_instructions.instruction_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="conv-instruction-priority" class="form-label">{{ t('ai_conversation_instructions.priority') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-sort-desc"></i></span>
                                    <input id="conv-instruction-priority" v-model.number="form.priority" type="number" min="0" class="form-control" :class="inputClass('priority')" @input="onFieldInput('priority')">
                                    <FormFieldFeedback v-bind="feedbackFor('priority')" />
                                </div>
                                <div v-if="messageFor('priority')" class="invalid-feedback d-block">{{ messageFor('priority') }}</div>
                                <div v-else class="form-text">{{ t('ai_conversation_instructions.priority_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_conversation_instructions.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_conversation_instructions.status_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_conversation_instructions.saving') : t('save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, integer, maxLength, minValue, required } from '@vuelidate/validators';
import Select from 'primevue/select';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../../../utils/catalog';

const RESOURCE_URI = '/api/admin/v1/ai-conversation-instructions';

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

const DEFAULTS = {
    conversation_id: null,
    source_type: 'user',
    instruction: '',
    priority: 0,
    status: true,
};

const form = reactive({ ...DEFAULTS });

const isBlank = (value) => value === null || value === undefined || value === '';

const SOURCES = ['user', 'system', 'template'];

const sourceOptions = computed(() => SOURCES.map((value) => ({ value, label: t(`ai_conversation_instructions.source_${value}`) })));

const rules = computed(() => ({
    instruction: {
        required: helpers.withMessage(() => t('validation.required', { field: t('ai_conversation_instructions.instruction') }), required),
        maxLength: helpers.withMessage(() => t('validation.max.string', { field: t('ai_conversation_instructions.instruction'), max: 5000 }), maxLength(5000)),
    },
    priority: {
        integer: helpers.withMessage(() => t('validation.integer', { field: t('ai_conversation_instructions.priority') }), (value) => isBlank(value) || integer.$validator(value)),
        minValue: helpers.withMessage(() => t('validation.min.numeric', { field: t('ai_conversation_instructions.priority'), min: 0 }), (value) => isBlank(value) || minValue(0).$validator(value)),
    },
    conversation_id: { required: helpers.withMessage(() => t('validation.required', { field: t('ai_conversation_instructions.conversation_id') }), required) },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_conversation_instructions.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_conversation_instructions.create_title')
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
    delete serverErrors[field];
    v$.value[field]?.$touch();
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    Object.assign(form, DEFAULTS);
    resetValidation();
}

function fillForm(record) {
    form.conversation_id = record?.conversation_id ?? null;
    form.source_type = record?.source_type ?? 'user';
    form.instruction = record?.instruction ?? '';
    form.priority = record?.priority ?? 0;
    form.status = Boolean(record?.status ?? record?.is_active ?? true);
    resetValidation();
}

function buildPayload() {
    return {
        conversation_id: form.conversation_id,
        source_type: form.source_type,
        instruction: String(form.instruction ?? '').trim(),
        priority: isBlank(form.priority) ? 0 : form.priority,
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
    onOpen: undefined,
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
