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
                                <label for="provider-data-rule-provider" class="form-label">
                                    {{ t('ai_provider_data_rules.provider') }} <span class="text-danger">*</span>
                                </label>
                                <select
                                    id="provider-data-rule-provider"
                                    v-model="form.provider_id"
                                    class="form-select"
                                    :class="fieldClass('provider_id')"
                                    :disabled="isEdit"
                                    @change="onFieldInput('provider_id')"
                                >
                                    <option :value="null">{{ t('ai_provider_data_rules.select_provider') }}</option>
                                    <option v-for="provider in providers" :key="provider.id" :value="provider.id">{{ provider.name }}</option>
                                </select>
                                <div v-if="fieldMessage('provider_id')" class="invalid-feedback d-block">{{ fieldMessage('provider_id') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_provider_data_rules.sanitize_pii') }}</label>
                                <div
                                    class="toggle toggle-primary mb-0"
                                    :class="{ on: form.sanitize_pii }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.sanitize_pii = !form.sanitize_pii"
                                    @keydown.enter.space.prevent="form.sanitize_pii = !form.sanitize_pii"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_provider_data_rules.sanitize_secrets') }}</label>
                                <div
                                    class="toggle toggle-primary mb-0"
                                    :class="{ on: form.sanitize_secrets }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.sanitize_secrets = !form.sanitize_secrets"
                                    @keydown.enter.space.prevent="form.sanitize_secrets = !form.sanitize_secrets"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="provider-data-rule-transformation" class="form-label">{{ t('ai_provider_data_rules.transformation_rules') }}</label>
                                <textarea
                                    id="provider-data-rule-transformation"
                                    v-model="transformationText"
                                    class="form-control font-monospace"
                                    rows="3"
                                    :class="{ 'is-invalid': transformationError }"
                                    :placeholder="t('ai_provider_data_rules.transformation_rules_placeholder')"
                                ></textarea>
                                <div v-if="transformationError" class="invalid-feedback d-block">{{ t('ai_provider_data_rules.transformation_rules_invalid_json') }}</div>
                                <small class="text-muted">{{ t('ai_provider_data_rules.transformation_rules_hint') }}</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_provider_data_rules.is_active') }}</label>
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
                            {{ submitting ? t('ai_provider_data_rules.saving') : t('save_changes') }}
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
const providers = ref([]);
const transformationText = ref('');
const transformationError = ref(false);
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    provider_id: null,
    sanitize_pii: true,
    sanitize_secrets: true,
    is_active: true,
});

const rules = computed(() => ({
    provider_id: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('ai_provider_data_rules.provider') }),
            required,
        ),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? t('ai_provider_data_rules.edit_title') : t('ai_provider_data_rules.create_title')
));

async function loadProviders() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-providers', { params: { all: 1 } });
        providers.value = data.data ?? [];
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
    form.provider_id = null;
    form.sanitize_pii = true;
    form.sanitize_secrets = true;
    form.is_active = true;
    transformationText.value = '';
    transformationError.value = false;
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.provider_id = record?.provider_id ?? null;
    form.sanitize_pii = Boolean(record?.sanitize_pii ?? true);
    form.sanitize_secrets = Boolean(record?.sanitize_secrets ?? true);
    form.is_active = Boolean(record?.is_active ?? true);
    transformationText.value = record?.transformation_rules ? JSON.stringify(record.transformation_rules, null, 2) : '';
    transformationError.value = false;
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

function parseTransformationRules() {
    const trimmed = transformationText.value.trim();

    if (! trimmed) {
        transformationError.value = false;
        return null;
    }

    try {
        const parsed = JSON.parse(trimmed);
        transformationError.value = false;
        return parsed;
    } catch (error) {
        transformationError.value = true;
        return undefined;
    }
}

async function submit() {
    v$.value.$touch();
    const transformationRules = parseTransformationRules();

    if (v$.value.$invalid || transformationRules === undefined) {
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;
    applyApiErrors(serverErrors, {});

    const payload = { ...form, transformation_rules: transformationRules };

    try {
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/ai-provider-data-rules/${props.record.id}`, payload);
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-provider-data-rules', payload);
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
    loadProviders();
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
