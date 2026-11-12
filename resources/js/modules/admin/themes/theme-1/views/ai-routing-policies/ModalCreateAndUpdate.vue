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

                <!-- Header and footer stay, only the body scrolls (catalog-list.css .catalog-scroll-form). -->
                <form v-if="activeLocale" class="catalog-scroll-form" @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <!-- The name the admin reads: one tab per stored language (like countries) -->
                        <CatalogTranslationTabs
                            :languages="storableLanguages"
                            :active-locale="activeLocale"
                            :translation-tab-class="translationTabClass"
                            :translation-tab-feedback="translationTabFeedback"
                            @update:active-locale="activeLocale = $event"
                        />

                        <div v-if="translationsGroupMessage" class="alert alert-danger py-2 px-3 mb-3">{{ translationsGroupMessage }}</div>

                        <div class="row g-3 mb-2">
                            <div class="col-12">
                                <label for="policy-name" class="form-label">{{ t('ai_routing_policies.name') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                                    <input
                                        id="policy-name"
                                        v-model="form.translations[activeLocale].name"
                                        type="text"
                                        maxlength="150"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'name')"
                                        :placeholder="t('ai_routing_policies.name_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'name')"
                                    >
                                    <FormFieldFeedback v-bind="fieldFeedbackFor(activeLocale, 'name')" />
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'name')" class="invalid-feedback d-block">{{ fieldMessage(activeLocale, 'name') }}</div>
                                <div v-else class="form-text">{{ t('ai_routing_policies.text_hint') }}</div>
                            </div>
                        </div>

                        <!-- Where it applies -->
                        <h6 class="catalog-form-section"><i class="ri-focus-3-line me-1"></i>{{ t('ai_routing_policies.sections.scope') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="policy-scope-type" class="form-label">{{ t('ai_routing_policies.scope_type') }}</label>
                                <select id="policy-scope-type" v-model="form.scope_type" class="form-select">
                                    <option value="global">{{ t('ai_routing_policies.scope_global') }}</option>
                                    <option value="country">{{ t('ai_routing_policies.scope_country') }}</option>
                                    <option value="service">{{ t('ai_routing_policies.scope_service') }}</option>
                                </select>
                                <div class="form-text">{{ t(`ai_routing_policies.scope_hint_${form.scope_type}`) }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="policy-country-code" class="form-label">{{ t('ai_routing_policies.country_code') }}</label>
                                <div class="input-group">
                                    <input
                                        id="policy-country-code"
                                        v-model="form.country_code"
                                        type="text"
                                        maxlength="2"
                                        dir="ltr"
                                        class="form-control text-uppercase"
                                        :class="inputClass('country_code')"
                                        placeholder="SA"
                                        @input="onFieldInput('country_code')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackFor('country_code')" />
                                </div>
                                <div v-if="messageFor('country_code')" class="invalid-feedback d-block">{{ messageFor('country_code') }}</div>
                                <div v-else class="form-text">{{ t('ai_routing_policies.country_code_hint') }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="policy-service-key" class="form-label">{{ t('ai_routing_policies.service_key') }}</label>
                                <div class="input-group">
                                    <input
                                        id="policy-service-key"
                                        v-model="form.service_key"
                                        type="text"
                                        maxlength="100"
                                        dir="ltr"
                                        class="form-control"
                                        :class="inputClass('service_key')"
                                        @input="onFieldInput('service_key')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackFor('service_key')" />
                                </div>
                                <div v-if="messageFor('service_key')" class="invalid-feedback d-block">{{ messageFor('service_key') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="policy-plan" class="form-label">{{ t('ai_routing_policies.plan') }}</label>
                                <Select
                                    v-model="form.plan_id"
                                    input-id="policy-plan"
                                    :options="plans"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('ai_routing_policies.no_plan')"
                                    :filter="true"
                                    :filter-fields="['name', 'code']"
                                    :show-clear="true"
                                    :invalid="Boolean(serverErrors.plan_id)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('plan_id')"
                                >
                                    <template #option="{ option }">
                                        <span>{{ option.name }}</span>
                                        <span v-if="!option.status" class="badge bg-secondary-transparent ms-2">{{ t('ai_routing_policies.filter_inactive') }}</span>
                                    </template>
                                </Select>
                                <div v-if="serverErrors.plan_id" class="invalid-feedback d-block">{{ serverErrors.plan_id[0] }}</div>
                            </div>
                        </div>

                        <!-- How it picks -->
                        <h6 class="catalog-form-section"><i class="ri-settings-3-line me-1"></i>{{ t('ai_routing_policies.sections.selection') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <label for="policy-strategy" class="form-label">{{ t('ai_routing_policies.selection_strategy') }}</label>
                                <select id="policy-strategy" v-model="form.selection_strategy" class="form-select">
                                    <option value="priority">{{ t('ai_routing_policies.strategy_priority') }}</option>
                                    <option value="round_robin">{{ t('ai_routing_policies.strategy_round_robin') }}</option>
                                    <option value="latency_optimized">{{ t('ai_routing_policies.strategy_latency_optimized') }}</option>
                                </select>
                                <div class="form-text">{{ t('ai_routing_policies.strategy_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="policy-priority" class="form-label">{{ t('ai_routing_policies.priority') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-sort-desc"></i></span>
                                    <input id="policy-priority" v-model.number="form.priority" type="number" min="0" class="form-control" :class="inputClass('priority')" @input="onFieldInput('priority')">
                                    <FormFieldFeedback v-bind="feedbackFor('priority')" />
                                </div>
                                <div v-if="messageFor('priority')" class="invalid-feedback d-block">{{ messageFor('priority') }}</div>
                                <div v-else class="form-text">{{ t('ai_routing_policies.priority_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_routing_policies.fallback_enabled') }}</label>
                                <div class="toggle toggle-primary mb-0 catalog-modal-toggle" :class="{ on: form.fallback_enabled }" role="switch" tabindex="0" :aria-checked="form.fallback_enabled" @click="form.fallback_enabled = !form.fallback_enabled" @keydown.enter.space.prevent="form.fallback_enabled = !form.fallback_enabled">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_routing_policies.fallback_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_routing_policies.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_routing_policies.status_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_routing_policies.saving') : t('save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, integer, maxLength, minValue } from '@vuelidate/validators';
import Select from 'primevue/select';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useCatalogTranslationFields from '../../../../../../composables/useCatalogTranslationFields';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../../../utils/catalog';

const RESOURCE_URI = '/api/admin/v1/ai-routing-policies';

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const plans = ref([]);
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const DEFAULTS = {
    scope_type: 'global',
    country_code: '',
    service_key: '',
    plan_id: null,
    selection_strategy: 'priority',
    fallback_enabled: true,
    priority: 0,
    status: true,
};

const form = reactive({ ...DEFAULTS, translations: {} });

const {
    activeLocale,
    storableLanguages,
    translationRules,
    ensureLanguagesLoaded,
    fieldFeedbackFor,
    fieldInputClass,
    fieldMessage,
    translationTabFeedback,
    translationTabClass,
    translationsGroupMessage,
    onTranslationInput,
    resetTranslations,
    fillTranslations,
    buildTranslationsPayload,
    focusInvalidTranslationTab,
} = useCatalogTranslationFields({
    form,
    serverErrors,
    fields: [{ name: 'name', labelKey: 'ai_routing_policies.name', max: 150, min: 2 }],
    getV$: () => v$.value,
});

const rules = computed(() => ({
    translations: translationRules.value,
    country_code: {
        exactLength: helpers.withMessage(
            () => t('ai_routing_policies.country_code_hint'),
            (value) => ! value || String(value).trim().length === 2,
        ),
    },
    service_key: {
        maxLength: helpers.withMessage(() => t('validation.max.string', { field: t('ai_routing_policies.service_key'), max: 100 }), maxLength(100)),
    },
    priority: {
        integer: helpers.withMessage(() => t('validation.integer', { field: t('ai_routing_policies.priority') }), integer),
        minValue: helpers.withMessage(() => t('validation.min.numeric', { field: t('ai_routing_policies.priority'), min: 0 }), minValue(0)),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('ai_routing_policies.create_title');
    }

    const record = props.record;
    const name = record?.translations?.find((item) => item.locale === locale.value)?.name || record?.name || '';

    return record?.id ? `${t('ai_routing_policies.edit_title')} #${record.id} ${name}`.trim() : t('ai_routing_policies.edit_title');
});

async function loadPlans() {
    try {
        // The plan picker (any admin; not the ai-plans.view list).
        const { data } = await adminAxios.get('/api/admin/v1/ai-plans/dropdown');
        plans.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function feedbackFor(field) {
    return fieldFeedback(v$.value[field], serverErrors[field]?.[0], form[field]);
}

function inputClass(field) {
    const feedback = feedbackFor(field);

    return { 'is-invalid': feedback.show && feedback.invalid, 'is-valid': feedback.show && feedback.valid && form[field] !== '' && form[field] !== null };
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
    resetTranslations();
    resetValidation();
}

function fillForm(record) {
    for (const key of Object.keys(DEFAULTS)) {
        form[key] = record?.[key] ?? DEFAULTS[key];
    }

    // "cost_optimized" is gone (no model has a price to compare): an old policy using it routes by priority.
    if (! ['priority', 'round_robin', 'latency_optimized'].includes(form.selection_strategy)) {
        form.selection_strategy = 'priority';
    }
    form.fallback_enabled = Boolean(record?.fallback_enabled ?? true);
    form.status = Boolean(record?.status ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    const countryCode = String(form.country_code ?? '').trim().toUpperCase();
    const serviceKey = String(form.service_key ?? '').trim();

    return {
        scope_type: form.scope_type,
        country_code: countryCode || null,
        service_key: serviceKey || null,
        plan_id: form.plan_id ?? null,
        selection_strategy: form.selection_strategy,
        fallback_enabled: form.fallback_enabled,
        priority: form.priority === '' || form.priority === null ? 0 : form.priority,
        status: form.status,
        translations: buildTranslationsPayload(),
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
        focusInvalidTranslationTab();
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
            focusInvalidTranslationTab();
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
    onOpen: async () => {
        await Promise.all([ensureLanguagesLoaded(), loadPlans()]);
    },
});

onMounted(async () => {
    await ensureLanguagesLoaded();
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
</style>
