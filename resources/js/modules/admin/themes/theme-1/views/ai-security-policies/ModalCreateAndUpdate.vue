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
                                <label for="security-policy-name" class="form-label">
                                    {{ t('ai_security_policies.name') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="security-policy-name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="fieldClass('name')"
                                    :placeholder="t('ai_security_policies.name_placeholder')"
                                    @input="onFieldInput('name')"
                                >
                                <div v-if="fieldMessage('name')" class="invalid-feedback d-block">{{ fieldMessage('name') }}</div>
                            </div>

                            <div class="col-12">
                                <label for="security-policy-description" class="form-label">{{ t('ai_security_policies.description') }}</label>
                                <textarea
                                    id="security-policy-description"
                                    v-model="form.description"
                                    class="form-control"
                                    rows="2"
                                    :placeholder="t('ai_security_policies.description_placeholder')"
                                ></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_security_policies.authentication_required') }}</label>
                                <div
                                    class="toggle toggle-primary mb-0"
                                    :class="{ on: form.authentication_required }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.authentication_required = !form.authentication_required"
                                    @keydown.enter.space.prevent="form.authentication_required = !form.authentication_required"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_security_policies.authorization_required') }}</label>
                                <div
                                    class="toggle toggle-primary mb-0"
                                    :class="{ on: form.authorization_required }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.authorization_required = !form.authorization_required"
                                    @keydown.enter.space.prevent="form.authorization_required = !form.authorization_required"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_security_policies.tenant_isolation_required') }}</label>
                                <div
                                    class="toggle toggle-primary mb-0"
                                    :class="{ on: form.tenant_isolation_required }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.tenant_isolation_required = !form.tenant_isolation_required"
                                    @keydown.enter.space.prevent="form.tenant_isolation_required = !form.tenant_isolation_required"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_security_policies.rate_limit_enabled') }}</label>
                                <div
                                    class="toggle toggle-primary mb-0"
                                    :class="{ on: form.rate_limit_enabled }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.rate_limit_enabled = !form.rate_limit_enabled"
                                    @keydown.enter.space.prevent="form.rate_limit_enabled = !form.rate_limit_enabled"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_security_policies.is_active') }}</label>
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
                            {{ submitting ? t('ai_security_policies.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
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
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    name: '',
    description: '',
    authentication_required: true,
    authorization_required: true,
    tenant_isolation_required: true,
    rate_limit_enabled: true,
    is_active: true,
});

const rules = computed(() => ({
    name: stringFieldRules('ai_security_policies.name', 150),
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_security_policies.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_security_policies.create_title')
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
    form.name = '';
    form.description = '';
    form.authentication_required = true;
    form.authorization_required = true;
    form.tenant_isolation_required = true;
    form.rate_limit_enabled = true;
    form.is_active = true;
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.name = record?.name ?? '';
    form.description = record?.description ?? '';
    form.authentication_required = Boolean(record?.authentication_required ?? true);
    form.authorization_required = Boolean(record?.authorization_required ?? true);
    form.tenant_isolation_required = Boolean(record?.tenant_isolation_required ?? true);
    form.rate_limit_enabled = Boolean(record?.rate_limit_enabled ?? true);
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

    try {
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/ai-security-policies/${props.record.id}`, { ...form });
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-security-policies', { ...form });
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
