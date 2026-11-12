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
                        <!-- Translated text: one tab per stored language (like countries / FAQs) -->
                        <CatalogTranslationTabs
                            :languages="storableLanguages"
                            :active-locale="activeLocale"
                            :translation-tab-class="translationTabClass"
                            :translation-tab-feedback="translationTabFeedback"
                            @update:active-locale="activeLocale = $event"
                        />

                        <div v-if="translationsGroupMessage" class="alert alert-danger py-2 px-3 mb-3">
                            {{ translationsGroupMessage }}
                        </div>

                        <div class="row g-3 mb-2">
                            <div class="col-12">
                                <label for="offer-name" class="form-label">
                                    {{ t('ai_site_offers.name') }} <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                                    <input
                                        id="offer-name"
                                        v-model="form.translations[activeLocale].name"
                                        type="text"
                                        maxlength="150"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'name')"
                                        :placeholder="isPack ? t('ai_site_offers.pack_name_placeholder') : t('ai_site_offers.name_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'name')"
                                    >
                                    <FormFieldFeedback v-bind="fieldFeedbackFor(activeLocale, 'name')" />
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'name')" class="invalid-feedback d-block">
                                    {{ fieldMessage(activeLocale, 'name') }}
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="offer-description" class="form-label">{{ t('ai_site_offers.description') }}</label>
                                <textarea
                                    id="offer-description"
                                    v-model="form.translations[activeLocale].description"
                                    class="form-control"
                                    :class="fieldInputClass(activeLocale, 'description')"
                                    rows="3"
                                    maxlength="2000"
                                    :placeholder="t('ai_site_offers.description_placeholder')"
                                    @input="onTranslationInput(activeLocale, 'description')"
                                ></textarea>
                                <div v-if="fieldMessage(activeLocale, 'description')" class="invalid-feedback d-block">
                                    {{ fieldMessage(activeLocale, 'description') }}
                                </div>
                                <div v-else class="form-text">{{ t('ai_site_offers.text_hint') }}</div>
                            </div>
                        </div>

                        <!-- What the customer gets -->
                        <h6 class="catalog-form-section"><i class="ri-settings-3-line me-1"></i>{{ t('ai_site_offers.sections.main') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="offer-code" class="form-label">{{ t('ai_site_offers.code') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-code-s-slash-line"></i></span>
                                    <input
                                        id="offer-code"
                                        v-model="form.code"
                                        type="text"
                                        maxlength="60"
                                        dir="ltr"
                                        class="form-control"
                                        :class="inputClass('code')"
                                        :placeholder="isPack ? 'edit_pack_10' : 'site_single'"
                                        @input="onFieldInput('code')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackFor('code')" />
                                </div>
                                <div v-if="messageFor('code')" class="invalid-feedback d-block">{{ messageFor('code') }}</div>
                                <div v-else class="form-text">{{ t('ai_site_offers.code_hint') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="offer-gens" class="form-label">{{ t('ai_site_offers.generations_included') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-magic-line"></i></span>
                                    <input
                                        id="offer-gens"
                                        v-model.number="form.generations_included"
                                        type="number"
                                        min="1"
                                        max="500"
                                        class="form-control"
                                        :class="inputClass('generations_included')"
                                        @input="onFieldInput('generations_included')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackFor('generations_included')" />
                                </div>
                                <div v-if="messageFor('generations_included')" class="invalid-feedback d-block">{{ messageFor('generations_included') }}</div>
                                <div v-else class="form-text">{{ isPack ? t('ai_site_offers.pack_generations_hint') : t('ai_site_offers.generations_hint') }}</div>
                            </div>
                        </div>

                        <!-- Display -->
                        <h6 class="catalog-form-section"><i class="ri-eye-line me-1"></i>{{ t('ai_site_offers.sections.display') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <label for="offer-sort" class="form-label">{{ t('ai_site_offers.sort_order') }}</label>
                                <input id="offer-sort" v-model.number="form.sort_order" type="number" min="0" max="65535" class="form-control" :class="inputClass('sort_order')" @input="onFieldInput('sort_order')">
                                <div v-if="messageFor('sort_order')" class="invalid-feedback d-block">{{ messageFor('sort_order') }}</div>
                                <div v-else class="form-text">{{ t('ai_site_offers.sort_order_hint') }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_site_offers.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                                <div class="form-text">{{ t('ai_site_offers.status_hint') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('ai_site_offers.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, integer, maxValue, minValue, required } from '@vuelidate/validators';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useCatalogTranslationFields from '../../../../../../composables/useCatalogTranslationFields';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../../../utils/catalog';

const RESOURCE_URI = '/api/admin/v1/ai-site-offers';

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
    // 'site' (a whole site) or 'edit_pack' (more edits for an existing site) - picked by the route.
    kind: { type: String, default: 'site' },
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { stringFieldRules, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');
const isPack = computed(() => props.kind === 'edit_pack');

const DEFAULTS = {
    code: '',
    generations_included: 20,
    sort_order: 0,
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
    fields: [
        { name: 'name', labelKey: 'ai_site_offers.name', max: 150, min: 2 },
        { name: 'description', labelKey: 'ai_site_offers.description', max: 2000, required: false },
    ],
    getV$: () => v$.value,
});

function numberRules(labelKey, { isRequired = false, min = 0, max = null } = {}) {
    const label = () => t(labelKey);
    const rules = {
        integer: helpers.withMessage(() => t('validation.integer', { field: label() }), integer),
        minValue: helpers.withMessage(() => t('validation.min.numeric', { field: label(), min }), minValue(min)),
    };

    if (isRequired) {
        rules.required = helpers.withMessage(() => t('validation.required', { field: label() }), required);
    }

    if (max !== null) {
        rules.maxValue = helpers.withMessage(() => t('validation.max.numeric', { field: label(), max }), maxValue(max));
    }

    return rules;
}

const rules = computed(() => ({
    translations: translationRules.value,
    code: {
        ...stringFieldRules('ai_site_offers.code', 60),
        alphaDash: helpers.withMessage(() => t('ai_site_offers.code_hint'), (value) => ! value || /^[A-Za-z0-9_-]+$/.test(value)),
    },
    generations_included: numberRules('ai_site_offers.generations_included', { isRequired: true, min: 1, max: 500 }),
    sort_order: numberRules('ai_site_offers.sort_order', { max: 65535 }),
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t(isPack.value ? 'ai_site_offers.add_pack' : 'ai_site_offers.add');
    }

    const record = props.record;
    const name = record?.translations?.find((item) => item.locale === locale.value)?.name || record?.name || '';
    const title = t(isPack.value ? 'ai_site_offers.edit_pack' : 'ai_site_offers.edit');

    return record?.id ? `${title} #${record.id} ${name}`.trim() : title;
});

// --- plain fields: same feedback as the countries form -----------------------------------------

function feedbackFor(field) {
    return fieldFeedback(v$.value[field], serverErrors[field]?.[0], form[field]);
}

function inputClass(field) {
    const feedback = feedbackFor(field);

    return { 'is-invalid': feedback.show && feedback.invalid, 'is-valid': feedback.show && feedback.valid && field === 'code' };
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

// --- fill / reset / payload -------------------------------------------------------------------

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

    form.status = Boolean(record?.status ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    return {
        code: String(form.code ?? '').trim(),
        kind: props.kind,
        generations_included: form.generations_included,
        sort_order: form.sort_order === '' || form.sort_order === null ? 0 : form.sort_order,
        status: form.status,
        translations: buildTranslationsPayload().map((translation) => ({
            ...translation,
            description: translation.description || null,
        })),
    };
}

// --- modal ------------------------------------------------------------------------------------

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
