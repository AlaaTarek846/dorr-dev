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
                        <!-- The text the admin reads: one tab per stored language (like countries) -->
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
                                <label for="safety-policy-name" class="form-label">{{ t('ai_safety_policies.name') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                                    <input
                                        id="safety-policy-name"
                                        v-model="form.translations[activeLocale].name"
                                        type="text"
                                        maxlength="150"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'name')"
                                        :placeholder="t('ai_safety_policies.name_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'name')"
                                    >
                                    <FormFieldFeedback v-bind="fieldFeedbackFor(activeLocale, 'name')" />
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'name')" class="invalid-feedback d-block">{{ fieldMessage(activeLocale, 'name') }}</div>
                            </div>

                            <div class="col-12">
                                <label for="safety-policy-description" class="form-label">{{ t('ai_safety_policies.description') }}</label>
                                <textarea
                                    id="safety-policy-description"
                                    v-model="form.translations[activeLocale].description"
                                    class="form-control"
                                    :class="fieldInputClass(activeLocale, 'description')"
                                    rows="2"
                                    maxlength="1000"
                                    :placeholder="t('ai_safety_policies.description_placeholder')"
                                    @input="onTranslationInput(activeLocale, 'description')"
                                ></textarea>
                                <div v-if="fieldMessage(activeLocale, 'description')" class="invalid-feedback d-block">{{ fieldMessage(activeLocale, 'description') }}</div>
                                <div v-else class="form-text">{{ t('ai_safety_policies.text_hint') }}</div>
                            </div>
                        </div>

                        <h6 class="catalog-form-section"><i class="ri-settings-3-line me-1"></i>{{ t('ai_safety_policies.sections.main') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <label for="safety-policy-risk" class="form-label">{{ t('ai_safety_policies.risk_level') }}</label>
                                <Select
                                    v-model="form.risk_level"
                                    input-id="safety-policy-risk"
                                    :options="riskOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.risk_level)"
                                    append-to="body"
                                    class="w-100"
                                    @update:model-value="onFieldInput('risk_level')"
                                />
                                <div v-if="serverErrors.risk_level" class="invalid-feedback d-block">{{ serverErrors.risk_level[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_safety_policies.risk_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_safety_policies.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_safety_policies.status_hint') }}</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label d-block">{{ t('ai_safety_policies.applies_to') }}</label>
                                <div class="d-flex flex-wrap gap-3">
                                    <div v-for="context in contexts" :key="context" class="form-check">
                                        <input :id="`applies-to-${context}`" v-model="form.applies_to" class="form-check-input" type="checkbox" :value="context" @change="onFieldInput('applies_to')">
                                        <label class="form-check-label" :for="`applies-to-${context}`">{{ t(`ai_safety_policies.context_${context}`) }}</label>
                                    </div>
                                </div>
                                <div v-if="serverErrors.applies_to" class="invalid-feedback d-block">{{ serverErrors.applies_to[0] }}</div>
                                <div v-else class="form-text">{{ t('ai_safety_policies.applies_to_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">{{ submitting ? t('ai_safety_policies.saving') : t('save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
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

const RESOURCE_URI = '/api/admin/v1/ai-safety-policies';

const RISKS = ['low', 'medium', 'high', 'critical'];

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { applyApiErrors } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
// Where a policy applies (applies_to) - stored as given; the guard checks every message today.
const contexts = ['chat', 'agent', 'tool', 'code'];
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const DEFAULTS = { risk_level: 'medium', status: true };

const form = reactive({ ...DEFAULTS, applies_to: [], translations: {} });

const riskOptions = computed(() => RISKS.map((value) => ({ value, label: t(`ai_safety_policies.risk_${value}`) })));

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
        { name: 'name', labelKey: 'ai_safety_policies.name', max: 150, min: 2 },
        { name: 'description', labelKey: 'ai_safety_policies.description', max: 1000, required: false },
    ],
    getV$: () => v$.value,
});

const rules = computed(() => ({
    translations: translationRules.value,
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('ai_safety_policies.create_title');
    }

    const record = props.record;
    const name = record?.translations?.find((item) => item.locale === locale.value)?.name || record?.name || '';

    return record?.id ? `${t('ai_safety_policies.edit_title')} #${record.id} ${name}`.trim() : t('ai_safety_policies.edit_title');
});

function onFieldInput(field) {
    delete serverErrors[field];
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    Object.assign(form, DEFAULTS);
    form.applies_to = [];
    resetTranslations();
    resetValidation();
}

function fillForm(record) {
    form.risk_level = record?.risk_level ?? 'medium';
    form.applies_to = Array.isArray(record?.applies_to) ? [...record.applies_to] : [];
    form.status = Boolean(record?.status ?? record?.is_active ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    return {
        risk_level: form.risk_level,
        applies_to: form.applies_to,
        status: form.status,
        translations: buildTranslationsPayload().map((translation) => ({
            ...translation,
            description: translation.description || null,
        })),
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
    onOpen: ensureLanguagesLoaded,
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
