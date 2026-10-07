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
                                <label for="rule-policy" class="form-label">
                                    {{ t('ai_routing_rules.policy') }} <span class="text-danger">*</span>
                                </label>
                                <select
                                    id="rule-policy"
                                    v-model="form.routing_policy_id"
                                    class="form-select"
                                    :class="fieldClass('routing_policy_id')"
                                    @change="onFieldInput('routing_policy_id')"
                                >
                                    <option :value="null">{{ t('ai_routing_rules.select_policy') }}</option>
                                    <option v-for="policy in policies" :key="policy.id" :value="policy.id">{{ policy.name }}</option>
                                </select>
                                <div v-if="fieldMessage('routing_policy_id')" class="invalid-feedback d-block">{{ fieldMessage('routing_policy_id') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="rule-intent" class="form-label">{{ t('ai_routing_rules.intent') }}</label>
                                <select id="rule-intent" v-model="form.intent_id" class="form-select">
                                    <option :value="null">{{ t('ai_routing_rules.any_intent') }}</option>
                                    <option v-for="intent in intents" :key="intent.id" :value="intent.id">{{ intent.name }}</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="rule-provider" class="form-label">{{ t('ai_routing_rules.provider') }}</label>
                                <select id="rule-provider" v-model="form.provider_id" class="form-select">
                                    <option :value="null">{{ t('ai_routing_rules.no_provider') }}</option>
                                    <option v-for="provider in providers" :key="provider.id" :value="provider.id">{{ provider.name }}</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="rule-model-key" class="form-label">{{ t('ai_routing_rules.model_key') }}</label>
                                <input
                                    id="rule-model-key"
                                    v-model="form.model_key"
                                    type="text"
                                    class="form-control"
                                    :placeholder="t('ai_routing_rules.model_key_placeholder')"
                                >
                            </div>

                            <div class="col-md-6">
                                <label for="rule-priority" class="form-label">{{ t('ai_routing_rules.priority') }}</label>
                                <input id="rule-priority" v-model.number="form.priority" type="number" min="0" class="form-control">
                            </div>

                            <div class="col-md-6">
                                <label for="rule-max-latency" class="form-label">{{ t('ai_routing_rules.max_latency_ms') }}</label>
                                <input
                                    id="rule-max-latency"
                                    v-model.number="form.max_latency_ms"
                                    type="number"
                                    min="0"
                                    class="form-control"
                                    :placeholder="t('ai_routing_rules.max_latency_ms_placeholder')"
                                >
                                <div class="form-text">{{ t('ai_routing_rules.max_latency_ms_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="rule-min-quality" class="form-label">{{ t('ai_routing_rules.min_quality') }}</label>
                                <input
                                    id="rule-min-quality"
                                    v-model.number="form.min_quality"
                                    type="number"
                                    min="0"
                                    max="1"
                                    step="0.01"
                                    class="form-control"
                                    :placeholder="t('ai_routing_rules.min_quality_placeholder')"
                                >
                                <div class="form-text">{{ t('ai_routing_rules.min_quality_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_routing_rules.is_active') }}</label>
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
                            {{ submitting ? t('ai_routing_rules.saving') : t('save_changes') }}
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
const policies = ref([]);
const intents = ref([]);
const providers = ref([]);
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    routing_policy_id: null,
    intent_id: null,
    provider_id: null,
    model_key: '',
    priority: 0,
    // Business gap fix: selection_config (ai_routing_rules.selection_config,
    // documented in its own migration comment as holding keys like
    // max_latency_ms/min_quality) has always been accepted and stored by
    // the backend, but this form never exposed it - the admin had no way
    // to set fine-grained routing preferences from the UI at all. Exposed
    // here as the two documented keys and assembled into selection_config
    // right before sending, so the payload shape the backend already
    // expects doesn't change.
    max_latency_ms: null,
    min_quality: null,
    is_active: true,
});

const rules = computed(() => ({
    routing_policy_id: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('ai_routing_rules.policy') }),
            required,
        ),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_routing_rules.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_routing_rules.create_title')
));

async function loadOptions() {
    try {
        const [policiesRes, intentsRes, providersRes] = await Promise.all([
            adminAxios.get('/api/admin/v1/ai-routing-policies'),
            adminAxios.get('/api/admin/v1/ai-intents'),
            adminAxios.get('/api/admin/v1/ai-providers'),
        ]);
        policies.value = policiesRes.data.data ?? [];
        intents.value = intentsRes.data.data ?? [];
        providers.value = providersRes.data.data ?? [];
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
    form.routing_policy_id = null;
    form.intent_id = null;
    form.provider_id = null;
    form.model_key = '';
    form.priority = 0;
    form.max_latency_ms = null;
    form.min_quality = null;
    form.is_active = true;
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.routing_policy_id = record?.routing_policy_id ?? null;
    form.intent_id = record?.intent_id ?? null;
    form.provider_id = record?.provider_id ?? null;
    form.model_key = record?.model_key ?? '';
    form.priority = record?.priority ?? 0;
    form.max_latency_ms = record?.selection_config?.max_latency_ms ?? null;
    form.min_quality = record?.selection_config?.min_quality ?? null;
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

        const { max_latency_ms, min_quality, ...rest } = form;
        const selection_config = (max_latency_ms !== null && max_latency_ms !== '') || (min_quality !== null && min_quality !== '')
            ? {
                ...(max_latency_ms !== null && max_latency_ms !== '' ? { max_latency_ms } : {}),
                ...(min_quality !== null && min_quality !== '' ? { min_quality } : {}),
            }
            : null;
        const payload = { ...rest, selection_config };

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/ai-routing-rules/${props.record.id}`, payload);
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-routing-rules', payload);
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
