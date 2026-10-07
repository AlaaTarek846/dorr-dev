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
                                <label for="policy-name" class="form-label">
                                    {{ t('ai_routing_policies.name') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="policy-name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="fieldClass('name')"
                                    :placeholder="t('ai_routing_policies.name_placeholder')"
                                    @input="onFieldInput('name')"
                                >
                                <div v-if="fieldMessage('name')" class="invalid-feedback d-block">{{ fieldMessage('name') }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="policy-scope-type" class="form-label">{{ t('ai_routing_policies.scope_type') }}</label>
                                <select id="policy-scope-type" v-model="form.scope_type" class="form-select">
                                    <option value="global">{{ t('ai_routing_policies.scope_global') }}</option>
                                    <option value="country">{{ t('ai_routing_policies.scope_country') }}</option>
                                    <option value="service">{{ t('ai_routing_policies.scope_service') }}</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label for="policy-country-code" class="form-label">{{ t('ai_routing_policies.country_code') }}</label>
                                <input id="policy-country-code" v-model="form.country_code" type="text" maxlength="2" class="form-control text-uppercase">
                            </div>

                            <div class="col-md-4">
                                <label for="policy-service-key" class="form-label">{{ t('ai_routing_policies.service_key') }}</label>
                                <input id="policy-service-key" v-model="form.service_key" type="text" class="form-control">
                            </div>

                            <div class="col-md-6">
                                <label for="policy-plan" class="form-label">{{ t('ai_routing_policies.plan') }}</label>
                                <select id="policy-plan" v-model="form.plan_id" class="form-select">
                                    <option :value="null">{{ t('ai_routing_policies.no_plan') }}</option>
                                    <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="policy-strategy" class="form-label">{{ t('ai_routing_policies.selection_strategy') }}</label>
                                <select id="policy-strategy" v-model="form.selection_strategy" class="form-select">
                                    <option value="priority">{{ t('ai_routing_policies.strategy_priority') }}</option>
                                    <option value="round_robin">{{ t('ai_routing_policies.strategy_round_robin') }}</option>
                                    <option value="cost_optimized">{{ t('ai_routing_policies.strategy_cost_optimized') }}</option>
                                    <option value="latency_optimized">{{ t('ai_routing_policies.strategy_latency_optimized') }}</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="policy-priority" class="form-label">{{ t('ai_routing_policies.priority') }}</label>
                                <input id="policy-priority" v-model.number="form.priority" type="number" min="0" class="form-control">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label d-block mb-2">{{ t('ai_routing_policies.fallback_enabled') }}</label>
                                <div
                                    class="toggle toggle-primary mb-0"
                                    :class="{ on: form.fallback_enabled }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.fallback_enabled = !form.fallback_enabled"
                                    @keydown.enter.space.prevent="form.fallback_enabled = !form.fallback_enabled"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label d-block mb-2">{{ t('ai_routing_policies.is_active') }}</label>
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
                            {{ submitting ? t('ai_routing_policies.saving') : t('save_changes') }}
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
const plans = ref([]);
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    name: '',
    scope_type: 'global',
    country_code: '',
    service_key: '',
    plan_id: null,
    selection_strategy: 'priority',
    fallback_enabled: true,
    priority: 0,
    is_active: true,
});

const rules = computed(() => ({
    name: stringFieldRules('ai_routing_policies.name', 150),
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_routing_policies.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_routing_policies.create_title')
));

async function loadPlans() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-plans');
        plans.value = data.data ?? [];
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
    form.scope_type = 'global';
    form.country_code = '';
    form.service_key = '';
    form.plan_id = null;
    form.selection_strategy = 'priority';
    form.fallback_enabled = true;
    form.priority = 0;
    form.is_active = true;
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.name = record?.name ?? '';
    form.scope_type = record?.scope_type ?? 'global';
    form.country_code = record?.country_code ?? '';
    form.service_key = record?.service_key ?? '';
    form.plan_id = record?.plan_id ?? null;
    form.selection_strategy = record?.selection_strategy ?? 'priority';
    form.fallback_enabled = Boolean(record?.fallback_enabled ?? true);
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

    try {
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/ai-routing-policies/${props.record.id}`, { ...form });
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-routing-policies', { ...form });
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
    loadPlans();
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
