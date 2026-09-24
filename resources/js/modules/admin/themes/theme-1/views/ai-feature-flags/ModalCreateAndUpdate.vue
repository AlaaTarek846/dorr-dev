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
                                <label for="flag-key" class="form-label">
                                    {{ t('ai_feature_flags.key') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="flag-key"
                                    v-model="form.key"
                                    type="text"
                                    class="form-control"
                                    :class="fieldClass('key')"
                                    @input="onFieldInput('key')"
                                >
                                <div v-if="fieldMessage('key')" class="invalid-feedback d-block">{{ fieldMessage('key') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="flag-target-type" class="form-label">
                                    {{ t('ai_feature_flags.target') }} <span class="text-danger">*</span>
                                </label>
                                <select id="flag-target-type" v-model="form.target_type" class="form-select">
                                    <option value="provider">provider</option>
                                    <option value="model">model</option>
                                    <option value="tool">tool</option>
                                </select>
                            </div>

                            <div class="col-md-4" v-if="form.target_type !== 'tool'">
                                <label for="flag-provider" class="form-label">{{ t('ai_feature_flags.provider') }}</label>
                                <select id="flag-provider" v-model="form.provider_id" class="form-select">
                                    <option :value="null">-</option>
                                    <option v-for="provider in providers" :key="provider.id" :value="provider.id">{{ provider.name }}</option>
                                </select>
                            </div>

                            <div class="col-md-4" v-if="form.target_type === 'model'">
                                <label for="flag-model-key" class="form-label">{{ t('ai_feature_flags.model_key') }}</label>
                                <input id="flag-model-key" v-model="form.model_key" type="text" class="form-control">
                            </div>

                            <div class="col-md-4" v-if="form.target_type === 'tool'">
                                <label for="flag-tool-key" class="form-label">{{ t('ai_feature_flags.tool_key') }}</label>
                                <input id="flag-tool-key" v-model="form.tool_key" type="text" class="form-control">
                            </div>

                            <div class="col-md-4">
                                <label for="flag-country" class="form-label">{{ t('ai_feature_flags.country_code') }}</label>
                                <input id="flag-country" v-model="form.country_code" type="text" maxlength="2" class="form-control" :placeholder="t('ai_feature_flags.all_countries')">
                            </div>

                            <div class="col-md-4">
                                <label for="flag-domain" class="form-label">{{ t('ai_feature_flags.domain') }}</label>
                                <input id="flag-domain" v-model="form.domain" type="text" class="form-control" :placeholder="t('ai_feature_flags.all_domains')">
                            </div>

                            <div class="col-md-4">
                                <label for="flag-environment" class="form-label">{{ t('ai_feature_flags.environment') }}</label>
                                <input id="flag-environment" v-model="form.environment" type="text" class="form-control">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_feature_flags.status') }}</label>
                                <div
                                    class="toggle toggle-success mb-0"
                                    :class="{ on: form.is_enabled }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_enabled = !form.is_enabled"
                                    @keydown.enter.space.prevent="form.is_enabled = !form.is_enabled"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="flag-description" class="form-label">{{ t('ai_feature_flags.description') }}</label>
                                <textarea id="flag-description" v-model="form.description" rows="2" class="form-control"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('ai_feature_flags.saving') : t('save_changes') }}
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
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    key: '',
    target_type: 'provider',
    provider_id: null,
    model_key: '',
    tool_key: '',
    country_code: '',
    domain: '',
    environment: 'production',
    is_enabled: true,
    description: '',
});

const rules = computed(() => ({
    key: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('ai_feature_flags.key') }),
            required,
        ),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_feature_flags.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_feature_flags.create_title')
));

async function loadOptions() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-providers');
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
    form.key = '';
    form.target_type = 'provider';
    form.provider_id = null;
    form.model_key = '';
    form.tool_key = '';
    form.country_code = '';
    form.domain = '';
    form.environment = 'production';
    form.is_enabled = true;
    form.description = '';
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.key = record?.key ?? '';
    form.target_type = record?.target_type ?? 'provider';
    form.provider_id = record?.provider?.id ?? null;
    form.model_key = record?.model_key ?? '';
    form.tool_key = record?.tool_key ?? '';
    form.country_code = record?.country_code ?? '';
    form.domain = record?.domain ?? '';
    form.environment = record?.environment ?? 'production';
    form.is_enabled = Boolean(record?.is_enabled ?? true);
    form.description = record?.description ?? '';
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

    try {
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/ai-feature-flags/${props.record.id}`, { ...form });
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-feature-flags', { ...form });
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
