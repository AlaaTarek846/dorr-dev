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
                                <label for="safety-rule-policy" class="form-label">
                                    {{ t('ai_safety_rules.policy') }} <span class="text-danger">*</span>
                                </label>
                                <select
                                    id="safety-rule-policy"
                                    v-model="form.safety_policy_id"
                                    class="form-select"
                                    :class="fieldClass('safety_policy_id')"
                                    @change="onFieldInput('safety_policy_id')"
                                >
                                    <option :value="null">{{ t('ai_safety_rules.select_policy') }}</option>
                                    <option v-for="policy in policies" :key="policy.id" :value="policy.id">{{ policy.name }}</option>
                                </select>
                                <div v-if="fieldMessage('safety_policy_id')" class="invalid-feedback d-block">{{ fieldMessage('safety_policy_id') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="safety-rule-name" class="form-label">
                                    {{ t('ai_safety_rules.name') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="safety-rule-name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="fieldClass('name')"
                                    :placeholder="t('ai_safety_rules.name_placeholder')"
                                    @input="onFieldInput('name')"
                                >
                                <div v-if="fieldMessage('name')" class="invalid-feedback d-block">{{ fieldMessage('name') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="safety-rule-action" class="form-label">{{ t('ai_safety_rules.action') }}</label>
                                <select id="safety-rule-action" v-model="form.action" class="form-select">
                                    <option value="allow">{{ t('ai_safety_rules.action_allow') }}</option>
                                    <option value="block">{{ t('ai_safety_rules.action_block') }}</option>
                                    <option value="review">{{ t('ai_safety_rules.action_review') }}</option>
                                    <option value="require_confirmation">{{ t('ai_safety_rules.action_require_confirmation') }}</option>
                                    <option value="sanitize">{{ t('ai_safety_rules.action_sanitize') }}</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="safety-rule-priority" class="form-label">{{ t('ai_safety_rules.priority') }}</label>
                                <input id="safety-rule-priority" v-model.number="form.priority" type="number" min="0" class="form-control">
                            </div>

                            <div class="col-12">
                                <label for="safety-rule-condition" class="form-label">{{ t('ai_safety_rules.condition') }}</label>
                                <textarea
                                    id="safety-rule-condition"
                                    v-model="conditionText"
                                    class="form-control font-monospace"
                                    rows="3"
                                    :class="{ 'is-invalid': conditionError }"
                                    :placeholder="t('ai_safety_rules.condition_placeholder')"
                                ></textarea>
                                <div v-if="conditionError" class="invalid-feedback d-block">{{ t('ai_safety_rules.condition_invalid_json') }}</div>
                                <small class="text-muted">{{ t('ai_safety_rules.condition_hint') }}</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_safety_rules.is_active') }}</label>
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
                            {{ submitting ? t('ai_safety_rules.saving') : t('save_changes') }}
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
const policies = ref([]);
const conditionText = ref('');
const conditionError = ref(false);
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    safety_policy_id: null,
    name: '',
    action: 'review',
    priority: 0,
    is_active: true,
});

const rules = computed(() => ({
    safety_policy_id: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('ai_safety_rules.policy') }),
            required,
        ),
    },
    name: stringFieldRules('ai_safety_rules.name', 150),
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_safety_rules.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_safety_rules.create_title')
));

async function loadPolicies() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-safety-policies');
        policies.value = data.data ?? [];
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
    form.safety_policy_id = null;
    form.name = '';
    form.action = 'review';
    form.priority = 0;
    form.is_active = true;
    conditionText.value = '';
    conditionError.value = false;
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.safety_policy_id = record?.safety_policy_id ?? null;
    form.name = record?.name ?? '';
    form.action = record?.action ?? 'review';
    form.priority = record?.priority ?? 0;
    form.is_active = Boolean(record?.is_active ?? true);
    conditionText.value = record?.condition ? JSON.stringify(record.condition, null, 2) : '';
    conditionError.value = false;
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

function parseCondition() {
    const trimmed = conditionText.value.trim();

    if (! trimmed) {
        conditionError.value = false;
        return null;
    }

    try {
        const parsed = JSON.parse(trimmed);
        conditionError.value = false;
        return parsed;
    } catch (error) {
        conditionError.value = true;
        return undefined;
    }
}

async function submit() {
    v$.value.$touch();
    const condition = parseCondition();

    if (v$.value.$invalid || condition === undefined) {
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;
    applyApiErrors(serverErrors, {});

    const payload = { ...form, condition };

    try {
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/ai-safety-rules/${props.record.id}`, payload);
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-safety-rules', payload);
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
    loadPolicies();
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
