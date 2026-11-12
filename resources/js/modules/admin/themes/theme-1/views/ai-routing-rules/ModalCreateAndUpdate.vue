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

                <!-- No language tabs: a rule holds no text people read (ids, a model id, a priority, JSON settings). -->
                <!-- Header and footer stay, only the body scrolls (catalog-list.css .catalog-scroll-form). -->
                <form class="catalog-scroll-form" @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <h6 class="catalog-form-section catalog-form-section--first"><i class="ri-route-line me-1"></i>{{ t('ai_routing_rules.sections.match') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="rule-policy" class="form-label">{{ t('ai_routing_rules.policy') }} <span class="text-danger">*</span></label>
                                <Select
                                    v-model="form.routing_policy_id"
                                    input-id="rule-policy"
                                    :options="policyOptions"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('ai_routing_rules.select_policy')"
                                    :filter="true"
                                    :invalid="feedbackFor('routing_policy_id').show && feedbackFor('routing_policy_id').invalid"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('routing_policy_id')"
                                >
                                    <template #option="{ option }">
                                        <span>{{ option.name }}</span>
                                        <span v-if="option.deleted" class="badge bg-danger-transparent ms-2">{{ t('catalog.deleted_badge') }}</span>
                                        <span v-else-if="!option.status" class="badge bg-secondary-transparent ms-2">{{ t('ai_routing_rules.filter_inactive') }}</span>
                                    </template>
                                </Select>
                                <div v-if="messageFor('routing_policy_id')" class="invalid-feedback d-block">{{ messageFor('routing_policy_id') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="rule-intent" class="form-label">{{ t('ai_routing_rules.intent') }}</label>
                                <Select
                                    v-model="form.intent_id"
                                    input-id="rule-intent"
                                    :options="intentOptions"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('ai_routing_rules.any_intent')"
                                    :filter="true"
                                    :filter-fields="['name', 'key']"
                                    :show-clear="true"
                                    :invalid="Boolean(serverErrors.intent_id)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('intent_id')"
                                >
                                    <template #option="{ option }">
                                        <span>{{ option.name }}</span>
                                        <span class="text-muted fs-11 ms-2" dir="ltr">{{ option.key }}</span>
                                        <span v-if="option.deleted" class="badge bg-danger-transparent ms-2">{{ t('catalog.deleted_badge') }}</span>
                                        <span v-else-if="!option.status" class="badge bg-secondary-transparent ms-2">{{ t('ai_routing_rules.filter_inactive') }}</span>
                                    </template>
                                </Select>
                                <div v-if="serverErrors.intent_id" class="invalid-feedback d-block">{{ serverErrors.intent_id[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_routing_rules.intent_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-robot-2-line me-1"></i>{{ t('ai_routing_rules.sections.target') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="rule-provider" class="form-label">{{ t('ai_routing_rules.provider') }}</label>
                                <Select
                                    v-model="form.provider_id"
                                    input-id="rule-provider"
                                    :options="providers"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('ai_routing_rules.no_provider')"
                                    :show-clear="true"
                                    :invalid="Boolean(serverErrors.provider_id)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('provider_id')"
                                />
                                <div v-if="serverErrors.provider_id" class="invalid-feedback d-block">{{ serverErrors.provider_id[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_routing_rules.provider_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="rule-model-key" class="form-label">{{ t('ai_routing_rules.model_key') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-cpu-line"></i></span>
                                    <input
                                        id="rule-model-key"
                                        v-model="form.model_key"
                                        type="text"
                                        maxlength="150"
                                        dir="ltr"
                                        class="form-control"
                                        :class="inputClass('model_key')"
                                        :placeholder="t('ai_routing_rules.model_key_placeholder')"
                                        @input="onFieldInput('model_key')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackFor('model_key')" />
                                </div>
                                <div v-if="messageFor('model_key')" class="invalid-feedback d-block">{{ messageFor('model_key') }}</div>
                                <div v-else class="form-text">{{ t('ai_routing_rules.model_key_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="rule-priority" class="form-label">{{ t('ai_routing_rules.priority') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-sort-desc"></i></span>
                                    <input id="rule-priority" v-model.number="form.priority" type="number" min="0" class="form-control" :class="inputClass('priority')" @input="onFieldInput('priority')">
                                    <FormFieldFeedback v-bind="feedbackFor('priority')" />
                                </div>
                                <div v-if="messageFor('priority')" class="invalid-feedback d-block">{{ messageFor('priority') }}</div>
                                <div v-else class="form-text">{{ t('ai_routing_rules.priority_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_routing_rules.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_routing_rules.status_hint') }}</div>
                            </div>
                        </div>

                        <!-- selection_config (JSON on the rule) as its two documented keys -->
                        <h6 class="catalog-form-section"><i class="ri-equalizer-line me-1"></i>{{ t('ai_routing_rules.sections.config') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <label for="rule-max-latency" class="form-label">{{ t('ai_routing_rules.max_latency_ms') }}</label>
                                <div class="input-group">
                                    <input id="rule-max-latency" v-model.number="form.max_latency_ms" type="number" min="0" class="form-control" :class="inputClass('max_latency_ms')" :placeholder="t('ai_routing_rules.max_latency_ms_placeholder')" @input="onFieldInput('max_latency_ms')">
                                    <FormFieldFeedback v-bind="feedbackFor('max_latency_ms')" />
                                </div>
                                <div v-if="messageFor('max_latency_ms')" class="invalid-feedback d-block">{{ messageFor('max_latency_ms') }}</div>
                                <div v-else class="form-text">{{ t('ai_routing_rules.max_latency_ms_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="rule-min-quality" class="form-label">{{ t('ai_routing_rules.min_quality') }}</label>
                                <div class="input-group">
                                    <input id="rule-min-quality" v-model.number="form.min_quality" type="number" min="0" max="1" step="0.01" class="form-control" :class="inputClass('min_quality')" :placeholder="t('ai_routing_rules.min_quality_placeholder')" @input="onFieldInput('min_quality')">
                                    <FormFieldFeedback v-bind="feedbackFor('min_quality')" />
                                </div>
                                <div v-if="messageFor('min_quality')" class="invalid-feedback d-block">{{ messageFor('min_quality') }}</div>
                                <div v-else class="form-text">{{ t('ai_routing_rules.min_quality_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_routing_rules.saving') : t('save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, integer, maxLength, maxValue, minValue, required } from '@vuelidate/validators';
import Select from 'primevue/select';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../../../utils/catalog';

const RESOURCE_URI = '/api/admin/v1/ai-routing-rules';

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
const currentPolicy = ref(null);
const currentIntent = ref(null);
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const DEFAULTS = {
    routing_policy_id: null,
    intent_id: null,
    provider_id: null,
    model_key: '',
    priority: 0,
    // selection_config (ai_routing_rules.selection_config) as its two documented keys, put back together on save.
    max_latency_ms: null,
    min_quality: null,
    status: true,
};

const form = reactive({ ...DEFAULTS });

// The pickers list rows not in the trash; a trashed policy / intent the rule already points at stays listed.
function withCurrent(list, current) {
    return current && current.deleted && ! list.some((row) => row.id === current.id) ? [...list, current] : list;
}

const policyOptions = computed(() => withCurrent(policies.value, currentPolicy.value));
const intentOptions = computed(() => withCurrent(intents.value, currentIntent.value));

const isBlank = (value) => value === null || value === undefined || value === '';

function numberRules(labelKey, { min = 0, max = null, whole = true } = {}) {
    const label = () => t(labelKey);
    const rules = {
        minValue: helpers.withMessage(() => t('validation.min.numeric', { field: label(), min }), (value) => isBlank(value) || minValue(min).$validator(value)),
    };

    if (whole) {
        rules.integer = helpers.withMessage(() => t('validation.integer', { field: label() }), (value) => isBlank(value) || integer.$validator(value));
    }

    if (max !== null) {
        rules.maxValue = helpers.withMessage(() => t('validation.max.numeric', { field: label(), max }), (value) => isBlank(value) || maxValue(max).$validator(value));
    }

    return rules;
}

const rules = computed(() => ({
    routing_policy_id: {
        required: helpers.withMessage(() => t('validation.required', { field: t('ai_routing_rules.policy') }), required),
    },
    model_key: {
        maxLength: helpers.withMessage(() => t('validation.max.string', { field: t('ai_routing_rules.model_key'), max: 150 }), maxLength(150)),
    },
    priority: numberRules('ai_routing_rules.priority'),
    max_latency_ms: numberRules('ai_routing_rules.max_latency_ms'),
    min_quality: numberRules('ai_routing_rules.min_quality', { max: 1, whole: false }),
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_routing_rules.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_routing_rules.create_title')
));

async function loadOptions() {
    try {
        // Policy / intent pickers: ai-routing-rules.view may read them (AiAdminScreens::READERS).
        const [policiesRes, intentsRes, providersRes] = await Promise.all([
            adminAxios.get('/api/admin/v1/ai-routing-policies/dropdown'),
            adminAxios.get('/api/admin/v1/ai-intents/dropdown'),
            adminAxios.get('/api/admin/v1/ai-providers'),
        ]);
        policies.value = policiesRes.data.data ?? [];
        intents.value = intentsRes.data.data ?? [];
        providers.value = providersRes.data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

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
    currentPolicy.value = null;
    currentIntent.value = null;
    resetValidation();
}

function fillForm(record) {
    form.routing_policy_id = record?.routing_policy_id ?? null;
    form.intent_id = record?.intent_id ?? null;
    form.provider_id = record?.provider_id ?? null;
    form.model_key = record?.model_key ?? '';
    form.priority = record?.priority ?? 0;
    form.max_latency_ms = record?.selection_config?.max_latency_ms ?? null;
    form.min_quality = record?.selection_config?.min_quality ?? null;
    form.status = Boolean(record?.status ?? true);
    currentPolicy.value = record?.policy ?? null;
    currentIntent.value = record?.intent ?? null;
    resetValidation();
}

function buildPayload() {
    const config = {
        ...(isBlank(form.max_latency_ms) ? {} : { max_latency_ms: form.max_latency_ms }),
        ...(isBlank(form.min_quality) ? {} : { min_quality: form.min_quality }),
    };

    return {
        routing_policy_id: form.routing_policy_id,
        intent_id: form.intent_id ?? null,
        provider_id: form.provider_id ?? null,
        model_key: String(form.model_key ?? '').trim() || null,
        priority: isBlank(form.priority) ? 0 : form.priority,
        selection_config: Object.keys(config).length ? config : null,
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
    onOpen: loadOptions,
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
