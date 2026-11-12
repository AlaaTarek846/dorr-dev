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

                <!-- No language tabs: a rule holds no text people read (a provider, two switches and JSON). -->
                <!-- Header and footer stay, only the body scrolls (catalog-list.css .catalog-scroll-form). -->
                <form class="catalog-scroll-form" @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <div class="row g-3 mb-2">
                            <div class="col-12">
                                <label for="provider-data-rule-provider" class="form-label">{{ t('ai_provider_data_rules.provider') }} <span class="text-danger">*</span></label>
                                <Select
                                    v-model="form.provider_id"
                                    input-id="provider-data-rule-provider"
                                    :options="providers"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('ai_provider_data_rules.select_provider')"
                                    :filter="true"
                                    :filter-fields="['name', 'key']"
                                    :disabled="isEdit"
                                    :invalid="feedbackFor('provider_id').show && feedbackFor('provider_id').invalid"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('provider_id')"
                                >
                                    <template #option="{ option }">
                                        <span>{{ option.name }}</span>
                                        <span v-if="option.key" class="text-muted fs-11 ms-2" dir="ltr">{{ option.key }}</span>
                                    </template>
                                </Select>
                                <div v-if="messageFor('provider_id')" class="invalid-feedback d-block">{{ messageFor('provider_id') }}</div>
                                <div v-else class="form-text">{{ t('ai_provider_data_rules.provider_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_provider_data_rules.sanitize_pii') }}</label>
                                <div class="toggle toggle-primary mb-0 catalog-modal-toggle" :class="{ on: form.sanitize_pii }" role="switch" tabindex="0" :aria-checked="form.sanitize_pii" @click="form.sanitize_pii = !form.sanitize_pii" @keydown.enter.space.prevent="form.sanitize_pii = !form.sanitize_pii">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_provider_data_rules.sanitize_pii_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_provider_data_rules.sanitize_secrets') }}</label>
                                <div class="toggle toggle-primary mb-0 catalog-modal-toggle" :class="{ on: form.sanitize_secrets }" role="switch" tabindex="0" :aria-checked="form.sanitize_secrets" @click="form.sanitize_secrets = !form.sanitize_secrets" @keydown.enter.space.prevent="form.sanitize_secrets = !form.sanitize_secrets">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_provider_data_rules.sanitize_secrets_hint') }}</div>
                            </div>

                            <div class="col-12">
                                <label for="provider-data-rule-transformation" class="form-label">{{ t('ai_provider_data_rules.transformation_rules') }}</label>
                                <textarea
                                    id="provider-data-rule-transformation"
                                    v-model="transformationText"
                                    class="form-control font-monospace"
                                    rows="4"
                                    dir="ltr"
                                    :class="{ 'is-invalid': transformationError || serverErrors.transformation_rules }"
                                    :placeholder="t('ai_provider_data_rules.transformation_rules_placeholder')"
                                    @input="transformationError = false; onFieldInput('transformation_rules')"
                                ></textarea>
                                <div v-if="transformationError" class="invalid-feedback d-block">{{ t('ai_provider_data_rules.transformation_rules_invalid_json') }}</div>
                                <div v-else-if="serverErrors.transformation_rules" class="invalid-feedback d-block">{{ serverErrors.transformation_rules[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_provider_data_rules.transformation_rules_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_provider_data_rules.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_provider_data_rules.status_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_provider_data_rules.saving') : t('save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, required } from '@vuelidate/validators';
import Select from 'primevue/select';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../../../utils/catalog';

const RESOURCE_URI = '/api/admin/v1/ai-provider-data-rules';

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
    provider_id: null,
    sanitize_pii: true,
    sanitize_secrets: true,
    status: true,
};

const form = reactive({ ...DEFAULTS });

const isBlank = (value) => value === null || value === undefined || value === '';
const providers = ref([]);
const transformationText = ref('');
const transformationError = ref(false);

async function loadProviders() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-providers', { params: { all: 1 } });
        providers.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

/** The JSON box as an object: null when empty, undefined when it isn't valid JSON (blocks the save). */
function parseTransformationRules() {
    const trimmed = transformationText.value.trim();

    if (! trimmed) {
        transformationError.value = false;
        return null;
    }

    try {
        const parsed = JSON.parse(trimmed);
        transformationError.value = parsed === null || typeof parsed !== 'object';
        return transformationError.value ? undefined : parsed;
    } catch (error) {
        transformationError.value = true;
        return undefined;
    }
}

const rules = computed(() => ({
    provider_id: { required: helpers.withMessage(() => t('validation.required', { field: t('ai_provider_data_rules.provider') }), required) },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_provider_data_rules.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_provider_data_rules.create_title')
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
    form.provider_id = record?.provider_id ?? null;
    form.sanitize_pii = Boolean(record?.sanitize_pii ?? true);
    form.sanitize_secrets = Boolean(record?.sanitize_secrets ?? true);
    transformationText.value = record?.transformation_rules ? JSON.stringify(record.transformation_rules, null, 2) : '';
    transformationError.value = false;
    form.status = Boolean(record?.status ?? record?.is_active ?? true);
    resetValidation();
}

function buildPayload() {
    return {
        provider_id: form.provider_id,
        sanitize_pii: form.sanitize_pii,
        sanitize_secrets: form.sanitize_secrets,
        transformation_rules: parseTransformationRules(),
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
    const payload = buildPayload();

    if (payload.transformation_rules === undefined) {
        showWarning(t('toast.validation_error'));
        return;
    }

    if (v$.value.$invalid) {
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;
    applyApiErrors(serverErrors, {});

    try {
        const response = isEdit.value && props.record?.id
            ? await adminAxios.put(`${RESOURCE_URI}/${props.record.id}`, payload)
            : await adminAxios.post(RESOURCE_URI, payload);

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
    onOpen: loadProviders,
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
