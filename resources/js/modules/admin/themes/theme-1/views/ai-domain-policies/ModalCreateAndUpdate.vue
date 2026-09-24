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
                                <label for="policy-domain-key" class="form-label">
                                    {{ t('ai_domain_policies.domain') }} <span class="text-danger">*</span>
                                </label>
                                <select id="policy-domain-key" v-model="form.domain_key" class="form-select" :disabled="isEdit">
                                    <option value="legal">legal</option>
                                    <option value="health">health</option>
                                    <option value="education">education</option>
                                    <option value="code">code</option>
                                    <option value="marketing">marketing</option>
                                    <option value="general_info">general_info</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="policy-name" class="form-label">
                                    {{ t('ai_domain_policies.name') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="policy-name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="fieldClass('name')"
                                    @input="onFieldInput('name')"
                                >
                                <div v-if="fieldMessage('name')" class="invalid-feedback d-block">{{ fieldMessage('name') }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="policy-risk-level" class="form-label">{{ t('ai_domain_policies.risk_level') }}</label>
                                <select id="policy-risk-level" v-model="form.risk_level" class="form-select">
                                    <option value="low">{{ t('ai_domain_policies.risk_low') }}</option>
                                    <option value="medium">{{ t('ai_domain_policies.risk_medium') }}</option>
                                    <option value="high">{{ t('ai_domain_policies.risk_high') }}</option>
                                    <option value="critical">{{ t('ai_domain_policies.risk_critical') }}</option>
                                </select>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label d-block mb-2">{{ t('ai_domain_policies.status') }}</label>
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
                                <label class="form-label d-block mb-2">{{ t('ai_domain_policies.controls') }}</label>
                                <div class="d-flex flex-wrap gap-4">
                                    <div class="form-check">
                                        <input id="policy-requires-jurisdiction" v-model="form.requires_jurisdiction" class="form-check-input" type="checkbox">
                                        <label class="form-check-label" for="policy-requires-jurisdiction">{{ t('ai_domain_policies.requires_jurisdiction') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input id="policy-requires-triage" v-model="form.requires_triage" class="form-check-input" type="checkbox">
                                        <label class="form-check-label" for="policy-requires-triage">{{ t('ai_domain_policies.requires_triage') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input id="policy-sandbox-required" v-model="form.sandbox_required" class="form-check-input" type="checkbox">
                                        <label class="form-check-label" for="policy-sandbox-required">{{ t('ai_domain_policies.sandbox_required') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input id="policy-allowlist-enforced" v-model="form.allowlist_enforced" class="form-check-input" type="checkbox">
                                        <label class="form-check-label" for="policy-allowlist-enforced">{{ t('ai_domain_policies.allowlist_enforced') }}</label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="policy-description" class="form-label">{{ t('ai_domain_policies.description') }}</label>
                                <textarea id="policy-description" v-model="form.description" rows="2" class="form-control"></textarea>
                            </div>

                            <div class="col-12">
                                <label for="policy-system-prompt" class="form-label">{{ t('ai_domain_policies.system_prompt_addition') }}</label>
                                <textarea id="policy-system-prompt" v-model="form.system_prompt_addition" rows="3" class="form-control"></textarea>
                                <span class="form-text">{{ t('ai_domain_policies.system_prompt_addition_hint') }}</span>
                            </div>

                            <div class="col-12">
                                <label for="policy-disclaimer" class="form-label">{{ t('ai_domain_policies.disclaimer_text') }}</label>
                                <textarea id="policy-disclaimer" v-model="form.disclaimer_text" rows="2" class="form-control"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('ai_domain_policies.saving') : t('save_changes') }}
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
    domain_key: 'legal',
    name: '',
    description: '',
    risk_level: 'medium',
    requires_jurisdiction: false,
    requires_triage: false,
    sandbox_required: false,
    allowlist_enforced: false,
    system_prompt_addition: '',
    disclaimer_text: '',
    is_active: true,
});

const rules = computed(() => ({
    name: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('ai_domain_policies.name') }),
            required,
        ),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_domain_policies.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_domain_policies.create_title')
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
    form.domain_key = 'legal';
    form.name = '';
    form.description = '';
    form.risk_level = 'medium';
    form.requires_jurisdiction = false;
    form.requires_triage = false;
    form.sandbox_required = false;
    form.allowlist_enforced = false;
    form.system_prompt_addition = '';
    form.disclaimer_text = '';
    form.is_active = true;
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.domain_key = record?.domain_key ?? 'legal';
    form.name = record?.name ?? '';
    form.description = record?.description ?? '';
    form.risk_level = record?.risk_level ?? 'medium';
    form.requires_jurisdiction = Boolean(record?.requires_jurisdiction);
    form.requires_triage = Boolean(record?.requires_triage);
    form.sandbox_required = Boolean(record?.sandbox_required);
    form.allowlist_enforced = Boolean(record?.allowlist_enforced);
    form.system_prompt_addition = record?.system_prompt_addition ?? '';
    form.disclaimer_text = record?.disclaimer_text ?? '';
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
            response = await adminAxios.put(`/api/admin/v1/ai-domain-policies/${props.record.id}`, { ...form });
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-domain-policies', { ...form });
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
