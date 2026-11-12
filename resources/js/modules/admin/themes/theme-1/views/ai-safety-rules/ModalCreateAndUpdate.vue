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
                        <!-- The rule's name: one tab per stored language (like countries) -->
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
                                <label for="safety-rule-name" class="form-label">{{ t('ai_safety_rules.name') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                                    <input
                                        id="safety-rule-name"
                                        v-model="form.translations[activeLocale].name"
                                        type="text"
                                        maxlength="150"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'name')"
                                        :placeholder="t('ai_safety_rules.name_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'name')"
                                    >
                                    <FormFieldFeedback v-bind="fieldFeedbackFor(activeLocale, 'name')" />
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'name')" class="invalid-feedback d-block">{{ fieldMessage(activeLocale, 'name') }}</div>
                                <div v-else class="form-text">{{ t('ai_safety_rules.text_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-shield-check-line me-1"></i>{{ t('ai_safety_rules.sections.main') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="safety-rule-policy" class="form-label">{{ t('ai_safety_rules.policy') }} <span class="text-danger">*</span></label>
                                <Select
                                    v-model="form.safety_policy_id"
                                    input-id="safety-rule-policy"
                                    :options="policyOptions"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('ai_safety_rules.select_policy')"
                                    :filter="true"
                                    :invalid="feedbackFor('safety_policy_id').show && feedbackFor('safety_policy_id').invalid"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('safety_policy_id')"
                                >
                                    <template #option="{ option }">
                                        <span>{{ option.name }}</span>
                                        <span v-if="option.deleted" class="badge bg-danger-transparent ms-2">{{ t('catalog.deleted_badge') }}</span>
                                        <span v-else-if="!option.status" class="badge bg-secondary-transparent ms-2">{{ t('ai_safety_rules.filter_inactive') }}</span>
                                    </template>
                                </Select>
                                <div v-if="messageFor('safety_policy_id')" class="invalid-feedback d-block">{{ messageFor('safety_policy_id') }}</div>
                                <div v-else class="form-text">{{ t('ai_safety_rules.policy_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="safety-rule-action" class="form-label">{{ t('ai_safety_rules.action') }}</label>
                                <Select
                                    v-model="form.action"
                                    input-id="safety-rule-action"
                                    :options="actionOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.action)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('action')"
                                />
                                <div v-if="serverErrors.action" class="invalid-feedback d-block">{{ serverErrors.action[0] }}</div>
                                <div v-else class="form-text">{{ t(`ai_safety_rules.action_${form.action}_hint`) }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="safety-rule-priority" class="form-label">{{ t('ai_safety_rules.priority') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-sort-desc"></i></span>
                                    <input id="safety-rule-priority" v-model.number="form.priority" type="number" min="0" class="form-control" :class="inputClass('priority')" @input="onFieldInput('priority')">
                                    <FormFieldFeedback v-bind="feedbackFor('priority')" />
                                </div>
                                <div v-if="messageFor('priority')" class="invalid-feedback d-block">{{ messageFor('priority') }}</div>
                                <div v-else class="form-text">{{ t('ai_safety_rules.priority_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_safety_rules.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_safety_rules.status_hint') }}</div>
                            </div>
                        </div>

                        <!-- The condition is technical JSON (keywords / risk score), never translated. -->
                        <h6 class="catalog-form-section"><i class="ri-code-box-line me-1"></i>{{ t('ai_safety_rules.sections.condition') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-12">
                                <label for="safety-rule-condition" class="form-label">{{ t('ai_safety_rules.condition') }}</label>
                                <textarea
                                    id="safety-rule-condition"
                                    v-model="conditionText"
                                    class="form-control font-monospace"
                                    rows="4"
                                    dir="ltr"
                                    :class="{ 'is-invalid': conditionError || serverErrors.condition, 'is-valid': conditionTouched && !conditionError && conditionText.trim() !== '' }"
                                    :placeholder="t('ai_safety_rules.condition_placeholder')"
                                    @input="onConditionInput"
                                ></textarea>
                                <div v-if="conditionError" class="invalid-feedback d-block">{{ t('ai_safety_rules.condition_invalid_json') }}</div>
                                <div v-else-if="serverErrors.condition" class="invalid-feedback d-block">{{ serverErrors.condition[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_safety_rules.condition_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_safety_rules.saving') : t('save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, integer, minValue, required } from '@vuelidate/validators';
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

const RESOURCE_URI = '/api/admin/v1/ai-safety-rules';

const ACTIONS = ['allow', 'block', 'review', 'require_confirmation', 'sanitize'];

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
const policies = ref([]);
const currentPolicy = ref(null);
const conditionText = ref('');
const conditionError = ref(false);
const conditionTouched = ref(false);
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const DEFAULTS = { safety_policy_id: null, action: 'review', priority: 0, status: true };

const form = reactive({ ...DEFAULTS, translations: {} });

const actionOptions = computed(() => ACTIONS.map((value) => ({ value, label: t(`ai_safety_rules.action_${value}`) })));

// The picker lists the policies not in the trash; a trashed policy the rule already belongs to stays listed.
const policyOptions = computed(() => {
    const current = currentPolicy.value;

    return current && current.deleted && ! policies.value.some((row) => row.id === current.id)
        ? [...policies.value, current]
        : policies.value;
});

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
    fields: [
        { name: 'name', labelKey: 'ai_safety_rules.name', max: 150, min: 2 },
    ],
    getV$: () => v$.value,
});

const isBlank = (value) => value === null || value === undefined || value === '';

const rules = computed(() => ({
    translations: translationRules.value,
    safety_policy_id: {
        required: helpers.withMessage(() => t('validation.required', { field: t('ai_safety_rules.policy') }), required),
    },
    priority: {
        integer: helpers.withMessage(() => t('validation.integer', { field: t('ai_safety_rules.priority') }), (value) => isBlank(value) || integer.$validator(value)),
        minValue: helpers.withMessage(() => t('validation.min.numeric', { field: t('ai_safety_rules.priority'), min: 0 }), (value) => isBlank(value) || minValue(0).$validator(value)),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('ai_safety_rules.create_title');
    }

    const record = props.record;
    const name = record?.translations?.find((item) => item.locale === locale.value)?.name || record?.name || '';

    return record?.id ? `${t('ai_safety_rules.edit_title')} #${record.id} ${name}`.trim() : t('ai_safety_rules.edit_title');
});

async function loadOptions() {
    await ensureLanguagesLoaded();

    try {
        // Policy picker: ai-safety-rules.view may read it (AiAdminScreens::READERS).
        const { data } = await adminAxios.get('/api/admin/v1/ai-safety-policies/dropdown');
        policies.value = data.data ?? [];
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

/** The condition as typed: empty = no condition (never matches), otherwise a JSON object; undefined = invalid. */
function parseCondition() {
    const trimmed = conditionText.value.trim();

    if (trimmed === '') {
        conditionError.value = false;
        return null;
    }

    try {
        const parsed = JSON.parse(trimmed);
        conditionError.value = parsed === null || typeof parsed !== 'object' || Array.isArray(parsed);

        return conditionError.value ? undefined : parsed;
    } catch {
        conditionError.value = true;

        return undefined;
    }
}

function onConditionInput() {
    delete serverErrors.condition;
    conditionTouched.value = true;
    parseCondition();
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
    conditionError.value = false;
    conditionTouched.value = false;
}

function resetForm() {
    Object.assign(form, DEFAULTS);
    conditionText.value = '';
    currentPolicy.value = null;
    resetTranslations();
    resetValidation();
}

function fillForm(record) {
    form.safety_policy_id = record?.safety_policy_id ?? null;
    form.action = record?.action ?? 'review';
    form.priority = record?.priority ?? 0;
    form.status = Boolean(record?.status ?? record?.is_active ?? true);
    conditionText.value = record?.condition ? JSON.stringify(record.condition, null, 2) : '';
    currentPolicy.value = record?.policy ?? null;
    fillTranslations(record);
    resetValidation();
}

function buildPayload(condition) {
    return {
        safety_policy_id: form.safety_policy_id,
        action: form.action,
        priority: isBlank(form.priority) ? 0 : form.priority,
        condition,
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
    conditionTouched.value = true;
    const condition = parseCondition();

    if (v$.value.$invalid || condition === undefined) {
        focusInvalidTranslationTab();
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;
    applyApiErrors(serverErrors, {});

    try {
        const response = isEdit.value && props.record?.id
            ? await adminAxios.put(`${RESOURCE_URI}/${props.record.id}`, buildPayload(condition))
            : await adminAxios.post(RESOURCE_URI, buildPayload(condition));

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
    onOpen: loadOptions,
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
