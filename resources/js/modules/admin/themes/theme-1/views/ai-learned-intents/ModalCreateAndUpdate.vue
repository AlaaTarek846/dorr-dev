<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header catalog-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">{{ modalTitle }}</h6>
                        <button type="button" class="btn-close catalog-modal-close" aria-label="Close" @click="close"></button>
                    </div>
                </div>

                <!-- No language tabs: the phrase is the users' own words the router matches, not a label to translate. -->
                <form class="catalog-scroll-form" @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <div class="row g-3 mb-2">
                            <div class="col-12">
                                <label for="li-phrase" class="form-label">{{ t('ai_learned_intents.phrase') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-chat-quote-line"></i></span>
                                    <input
                                        id="li-phrase"
                                        v-model="form.phrase"
                                        type="text"
                                        dir="auto"
                                        maxlength="120"
                                        class="form-control"
                                        :class="inputClass('phrase')"
                                        :disabled="isEdit"
                                        :placeholder="t('ai_learned_intents.phrase_placeholder')"
                                        @input="onFieldInput('phrase')"
                                    >
                                    <FormFieldFeedback v-if="!isEdit" v-bind="feedbackFor('phrase')" />
                                </div>
                                <div v-if="messageFor('phrase')" class="invalid-feedback d-block">{{ messageFor('phrase') }}</div>
                                <div v-else class="form-text">{{ isEdit ? t('ai_learned_intents.phrase_locked') : t('ai_learned_intents.phrase_help') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="li-mode" class="form-label">{{ t('ai_learned_intents.match_mode') }}</label>
                                <select id="li-mode" v-model="form.match_mode" class="form-select" :disabled="isEdit">
                                    <option value="phrase">{{ t('ai_learned_intents.mode_phrase') }}</option>
                                    <option value="exact">{{ t('ai_learned_intents.mode_exact') }}</option>
                                </select>
                                <div class="form-text">{{ t(`ai_learned_intents.mode_help_${form.match_mode}`) }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="li-intent" class="form-label">{{ t('ai_learned_intents.intent') }} <span class="text-danger">*</span></label>
                                <Select
                                    v-model="form.intent"
                                    input-id="li-intent"
                                    :options="intentOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.intent)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('intent')"
                                />
                                <div v-if="serverErrors.intent" class="invalid-feedback d-block">{{ serverErrors.intent[0] }}</div>
                            </div>

                            <div v-if="form.intent === 'file_output'" class="col-md-6">
                                <label for="li-format" class="form-label">{{ t('ai_learned_intents.file_format') }}</label>
                                <select id="li-format" v-model="form.file_format" class="form-select">
                                    <option :value="null">{{ t('ai_learned_intents.format_auto') }}</option>
                                    <option value="pdf">PDF</option>
                                    <option value="docx">Word (DOCX)</option>
                                    <option value="xlsx">Excel (XLSX)</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_learned_intents.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_learned_intents.status_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_learned_intents.saving') : t('save_changes') }}</button>
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

const RESOURCE_URI = '/api/admin/v1/ai-learned-intents';

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
    // Must mirror Modules\AI\Support\AiChatIntent::ACTIONS (passed by the list).
    intents: { type: Array, default: () => [] },
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

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_learned_intents.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_learned_intents.create_title')
));

const intentOptions = computed(() => props.intents.map((value) => ({ value, label: t(`ai_learned_intents.intents.${value}`) })));

const DEFAULTS = { phrase: '', match_mode: 'phrase', intent: 'image_generation', file_format: null, status: true };

const form = reactive({ ...DEFAULTS });

const rules = computed(() => (isEdit.value ? {} : {
    phrase: {
        required: helpers.withMessage(() => t('validation.required', { field: t('ai_learned_intents.phrase') }), required),
        minLength: helpers.withMessage(() => t('ai_learned_intents.phrase_too_short'), (value) => String(value ?? '').trim().length >= 2),
        maxLength: helpers.withMessage(() => t('validation.max.string', { field: t('ai_learned_intents.phrase'), max: 120 }), maxLength(120)),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

function feedbackFor(field) {
    return fieldFeedback(v$.value[field], serverErrors[field]?.[0], form[field]);
}

function inputClass(field) {
    const feedback = feedbackFor(field);

    return { 'is-invalid': feedback.show && feedback.invalid, 'is-valid': feedback.show && feedback.valid && ! isEdit.value };
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
    Object.assign(form, DEFAULTS, { intent: props.intents[0] ?? 'image_generation' });
    resetValidation();
}

function fillForm(record) {
    form.phrase = record?.phrase ?? '';
    form.match_mode = record?.match_mode ?? 'phrase';
    form.intent = record?.intent ?? 'image_generation';
    form.file_format = record?.file_format ?? null;
    form.status = Boolean(record?.status);
    resetValidation();
}

function buildPayload() {
    const fileFormat = form.intent === 'file_output' ? form.file_format : null;

    // The phrase and match type are what was learned: set once, never changed.
    return isEdit.value
        ? { intent: form.intent, file_format: fileFormat, status: form.status }
        : { phrase: form.phrase.trim(), match_mode: form.match_mode, intent: form.intent, file_format: fileFormat, status: form.status };
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
</style>
