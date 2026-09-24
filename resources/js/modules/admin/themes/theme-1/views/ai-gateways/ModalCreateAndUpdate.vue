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
                                <label for="gateway-name" class="form-label">
                                    {{ t('ai_gateways.name') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="gateway-name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="fieldClass('name')"
                                    :placeholder="t('ai_gateways.name_placeholder')"
                                    @input="onFieldInput('name')"
                                >
                                <div v-if="fieldMessage('name')" class="invalid-feedback d-block">{{ fieldMessage('name') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="gateway-environment" class="form-label">{{ t('ai_gateways.environment') }}</label>
                                <select id="gateway-environment" v-model="form.environment" class="form-select">
                                    <option value="production">{{ t('ai_gateways.env_production') }}</option>
                                    <option value="staging">{{ t('ai_gateways.env_staging') }}</option>
                                    <option value="development">{{ t('ai_gateways.env_development') }}</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="gateway-policy" class="form-label">{{ t('ai_gateways.default_policy') }}</label>
                                <select id="gateway-policy" v-model="form.default_policy_id" class="form-select">
                                    <option :value="null">{{ t('ai_gateways.no_policy') }}</option>
                                    <option v-for="policy in policies" :key="policy.id" :value="policy.id">{{ policy.name }}</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_gateways.is_active') }}</label>
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
                            {{ submitting ? t('ai_gateways.saving') : t('save_changes') }}
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
const policies = ref([]);
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    name: '',
    environment: 'production',
    default_policy_id: null,
    is_active: true,
});

const rules = computed(() => ({
    name: stringFieldRules('ai_gateways.name', 150),
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_gateways.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_gateways.create_title')
));

async function loadPolicies() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-routing-policies');
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
    form.name = '';
    form.environment = 'production';
    form.default_policy_id = null;
    form.is_active = true;
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.name = record?.name ?? '';
    form.environment = record?.environment ?? 'production';
    form.default_policy_id = record?.default_policy_id ?? null;
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
            response = await adminAxios.put(`/api/admin/v1/ai-gateways/${props.record.id}`, { ...form });
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-gateways', { ...form });
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
