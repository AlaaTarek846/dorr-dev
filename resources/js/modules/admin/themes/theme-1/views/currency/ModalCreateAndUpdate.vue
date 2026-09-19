<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header catalog-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ modalTitle }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close catalog-modal-close"
                            aria-label="Close"
                            @click="close"
                        ></button>
                    </div>
                </div>

                <form @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <CatalogTranslationTabs
                            :languages="storableLanguages"
                            :active-locale="activeLocale"
                            :translation-tab-class="translationTabClass"
                            :translation-tab-feedback="translationTabFeedback"
                            @update:active-locale="activeLocale = $event"
                        />

                        <div class="mb-3">
                            <label for="currency-name" class="form-label">
                                {{ t('currencies.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-text"></i>
                                </span>
                                <input
                                    id="currency-name"
                                    v-model="form.translations[activeLocale]"
                                    type="text"
                                    class="form-control"
                                    :class="activeTranslationInputClass"
                                    :placeholder="t('currencies.name_placeholder')"
                                    @input="onTranslationInput(activeLocale)"
                                >
                                <FormFieldFeedback v-bind="activeTranslationFeedback" />
                            </div>
                            <div v-if="activeTranslationMessage" class="invalid-feedback d-block">
                                {{ activeTranslationMessage }}
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="currency-code" class="form-label">
                                    {{ t('currencies.code') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-code-s-slash-line"></i>
                                    </span>
                                    <input
                                        id="currency-code"
                                        v-model="form.code"
                                        type="text"
                                        maxlength="5"
                                        class="form-control text-uppercase"
                                        :class="codeInputClass"
                                        :placeholder="t('currencies.code_placeholder')"
                                        @input="onCodeInput"
                                    >
                                    <FormFieldFeedback v-bind="codeFeedback" />
                                </div>
                                <div v-if="codeMessage" class="invalid-feedback d-block">
                                    {{ codeMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="currency-symbol" class="form-label">
                                    {{ t('currencies.symbol') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-money-dollar-circle-line"></i>
                                    </span>
                                    <input
                                        id="currency-symbol"
                                        v-model="form.symbol"
                                        type="text"
                                        maxlength="5"
                                        class="form-control"
                                        :class="symbolInputClass"
                                        :placeholder="t('currencies.symbol_placeholder')"
                                        @input="onSymbolInput"
                                    >
                                    <FormFieldFeedback v-bind="symbolFeedback" />
                                </div>
                                <div v-if="symbolMessage" class="invalid-feedback d-block">
                                    {{ symbolMessage }}
                                </div>
                            </div>
                        </div>

                        <div class="currency-symbol-preview mb-4">
                            <div
                                class="currency-symbol-preview__card"
                                :class="{ 'currency-symbol-preview__card--empty': ! form.symbol.trim() && ! normalizedCode }"
                            >
                                <span class="currency-symbol-preview__badge">
                                    {{ form.symbol.trim() || normalizedCode || '?' }}
                                </span>
                                <div class="currency-symbol-preview__content">
                                    <span class="currency-symbol-preview__label">{{ t('currencies.symbol_preview') }}</span>
                                    <span v-if="normalizedCode || form.symbol.trim()" class="currency-symbol-preview__value">
                                        {{ previewLabel }}
                                    </span>
                                    <span v-else class="currency-symbol-preview__hint">{{ t('currencies.symbol_preview_hint') }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="currency-decimal-places" class="form-label">
                                    {{ t('currencies.decimal_places') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-hashtag"></i>
                                    </span>
                                    <input
                                        id="currency-decimal-places"
                                        v-model.number="form.decimal_places"
                                        type="number"
                                        min="0"
                                        max="8"
                                        step="1"
                                        class="form-control"
                                        :class="decimalPlacesInputClass"
                                        :placeholder="t('currencies.decimal_places_placeholder')"
                                        @input="onDecimalPlacesInput"
                                    >
                                    <FormFieldFeedback v-bind="decimalPlacesFeedback" />
                                </div>
                                <div v-if="decimalPlacesMessage" class="invalid-feedback d-block">
                                    {{ decimalPlacesMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="currency-exchange-rate" class="form-label">
                                    {{ t('currencies.exchange_rate') }}
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-exchange-dollar-line"></i>
                                    </span>
                                    <input
                                        id="currency-exchange-rate"
                                        v-model="form.exchange_rate"
                                        type="number"
                                        min="0"
                                        step="any"
                                        class="form-control"
                                        :class="exchangeRateInputClass"
                                        :placeholder="t('currencies.exchange_rate_placeholder')"
                                        @input="onExchangeRateInput"
                                    >
                                    <FormFieldFeedback v-bind="exchangeRateFeedback" />
                                </div>
                                <div v-if="exchangeRateMessage" class="invalid-feedback d-block">
                                    {{ exchangeRateMessage }}
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('currencies.is_default') }}</label>
                                <div
                                    class="toggle toggle-warning mb-0 catalog-modal-toggle"
                                    :class="{ on: form.is_default }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_default = !form.is_default"
                                    @keydown.enter.space.prevent="form.is_default = !form.is_default"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('currencies.status') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.status }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.status = !form.status"
                                    @keydown.enter.space.prevent="form.status = !form.status"
                                >
                                    <span></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">
                            {{ t('close') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('currencies.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, integer, maxValue, minValue, numeric } from '@vuelidate/validators';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import useCatalogTranslations from '../../../../../../composables/useCatalogTranslations';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useValidation from '../../../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../../../utils/catalog';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    type: {
        type: String,
        default: 'create',
    },
    record: {
        type: Object,
        default: null,
    },
    resourceUri: {
        type: String,
        default: '/api/admin/v1/currencies',
    },
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const {
    stringFieldRules,
    requiredField,
    applyApiErrors,
    fieldFeedback,
} = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    code: '',
    symbol: '',
    decimal_places: 2,
    exchange_rate: '1',
    is_default: false,
    status: true,
    translations: {},
});

const {
    activeLocale,
    storableLanguages,
    translationRules,
    ensureLanguagesLoaded,
    translationTabFeedback,
    translationTabClass,
    activeTranslationFeedback,
    activeTranslationInputClass,
    activeTranslationMessage,
    onTranslationInput,
    resetTranslations,
    fillTranslations,
    buildTranslationsPayload,
    focusInvalidTranslationTab,
} = useCatalogTranslations({
    form,
    serverErrors,
    nameKey: 'currencies.name',
    minLength: 2,
    maxLength: 50,
    getV$: () => v$.value,
});

const normalizedCode = computed(() => form.code.trim().toUpperCase());

const previewLabel = computed(() => {
    const parts = [];

    if (normalizedCode.value) {
        parts.push(normalizedCode.value);
    }

    if (form.symbol.trim()) {
        parts.push(form.symbol.trim());
    }

    return parts.join(' · ');
});

const rules = computed(() => ({
    code: stringFieldRules('currencies.code', 5, 2),
    symbol: stringFieldRules('currencies.symbol', 5, 1),
    decimal_places: {
        required: requiredField('currencies.decimal_places'),
        integer: helpers.withMessage(
            () => t('validation.integer', { field: t('currencies.decimal_places') }),
            integer,
        ),
        minValue: helpers.withMessage(
            () => t('validation.min.numeric', { field: t('currencies.decimal_places'), min: 0 }),
            minValue(0),
        ),
        maxValue: helpers.withMessage(
            () => t('validation.max.numeric', { field: t('currencies.decimal_places'), max: 8 }),
            maxValue(8),
        ),
    },
    exchange_rate: {
        numeric: helpers.withMessage(
            () => t('currencies.validation.exchange_rate_numeric'),
            numeric,
        ),
        minValue: helpers.withMessage(
            () => t('currencies.validation.exchange_rate_min'),
            minValue(0),
        ),
    },
    translations: translationRules.value,
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

function recordName(record, lang = locale.value) {
    const translation = record?.translations?.find((item) => item.locale === lang);

    return translation?.name || record?.name || '';
}

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('currencies.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('currencies.edit_title');
    }

    const name = recordName(record);

    return name
        ? `${t('currencies.edit_title')} #${record.id} ${name}`
        : `${t('currencies.edit_title')} #${record.id}`;
});

const codeFeedback = computed(() => fieldFeedback(
    v$.value.code,
    serverErrors.code?.[0],
    form.code,
));

const codeInputClass = computed(() => ({
    'is-invalid': codeFeedback.value.show && codeFeedback.value.invalid,
    'is-valid': codeFeedback.value.show && codeFeedback.value.valid,
}));

const codeMessage = computed(() => {
    if (! codeFeedback.value.invalid) {
        return null;
    }

    return v$.value.code.$errors[0]?.$message || serverErrors.code?.[0] || null;
});

const symbolFeedback = computed(() => fieldFeedback(
    v$.value.symbol,
    serverErrors.symbol?.[0],
    form.symbol,
));

const symbolInputClass = computed(() => ({
    'is-invalid': symbolFeedback.value.show && symbolFeedback.value.invalid,
    'is-valid': symbolFeedback.value.show && symbolFeedback.value.valid,
}));

const symbolMessage = computed(() => {
    if (! symbolFeedback.value.invalid) {
        return null;
    }

    return v$.value.symbol.$errors[0]?.$message || serverErrors.symbol?.[0] || null;
});

const decimalPlacesFeedback = computed(() => fieldFeedback(
    v$.value.decimal_places,
    serverErrors.decimal_places?.[0],
    form.decimal_places,
));

const decimalPlacesInputClass = computed(() => ({
    'is-invalid': decimalPlacesFeedback.value.show && decimalPlacesFeedback.value.invalid,
    'is-valid': decimalPlacesFeedback.value.show && decimalPlacesFeedback.value.valid,
}));

const decimalPlacesMessage = computed(() => {
    if (! decimalPlacesFeedback.value.invalid) {
        return null;
    }

    return v$.value.decimal_places.$errors[0]?.$message || serverErrors.decimal_places?.[0] || null;
});

const exchangeRateFeedback = computed(() => fieldFeedback(
    v$.value.exchange_rate,
    serverErrors.exchange_rate?.[0],
    form.exchange_rate,
));

const exchangeRateInputClass = computed(() => ({
    'is-invalid': exchangeRateFeedback.value.show && exchangeRateFeedback.value.invalid,
    'is-valid': exchangeRateFeedback.value.show && exchangeRateFeedback.value.valid,
}));

const exchangeRateMessage = computed(() => {
    if (! exchangeRateFeedback.value.invalid) {
        return null;
    }

    return v$.value.exchange_rate.$errors[0]?.$message || serverErrors.exchange_rate?.[0] || null;
});

function onCodeInput() {
    clearServerError('code');
    v$.value.$touch();
}

function onSymbolInput() {
    clearServerError('symbol');
    v$.value.$touch();
}

function onDecimalPlacesInput() {
    clearServerError('decimal_places');
    v$.value.$touch();
}

function onExchangeRateInput() {
    clearServerError('exchange_rate');
    v$.value.$touch();
}

function clearServerError(field) {
    delete serverErrors[field];
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    form.code = '';
    form.symbol = '';
    form.decimal_places = 2;
    form.exchange_rate = '1';
    form.is_default = false;
    form.status = true;
    resetTranslations();
    resetValidation();
}

function fillForm(record) {
    form.code = record?.code ?? '';
    form.symbol = record?.symbol ?? '';
    form.decimal_places = record?.decimal_places ?? 2;
    form.exchange_rate = record?.exchange_rate != null ? String(record.exchange_rate) : '1';
    form.is_default = Boolean(record?.is_default ?? false);
    form.status = Boolean(record?.status ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    return {
        code: form.code.trim().toUpperCase(),
        symbol: form.symbol.trim(),
        decimal_places: Number(form.decimal_places),
        exchange_rate: form.exchange_rate === '' ? null : Number(form.exchange_rate),
        is_default: form.is_default,
        status: form.status,
        translations: buildTranslationsPayload(),
    };
}

function openModal() {
    if (! modalElement.value) {
        return;
    }

    modalInstance ??= new window.bootstrap.Modal(modalElement.value);
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
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/currencies/${props.record.id}`, buildPayload());
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/currencies', buildPayload());
            showSuccess(extractApiMessage(response, t('toast.created')));
        }

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
    resourceUri: props.resourceUri,
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

.catalog-modal-toggle.toggle-success.on {
    background-color: #26bf94;
}

.catalog-modal-toggle.toggle-warning.on {
    background-color: #f5b849;
}

.currency-symbol-preview__card {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.875rem 1rem;
    border: 1px dashed rgba(var(--primary-rgb, 132, 90, 223), 0.35);
    border-radius: 0.5rem;
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.04);
    transition: border-color 0.2s ease, background-color 0.2s ease;
}

.currency-symbol-preview__card--empty {
    border-color: var(--default-border, #dee2e6);
    background: #f8f9fa;
}

.currency-symbol-preview__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3rem;
    height: 2.25rem;
    border-radius: 0.375rem;
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.12);
    color: rgb(var(--primary-rgb, 132, 90, 223));
    font-weight: 700;
    font-size: 1.125rem;
    flex-shrink: 0;
}

.currency-symbol-preview__content {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    min-width: 0;
}

.currency-symbol-preview__label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #8c9097;
}

.currency-symbol-preview__value {
    font-size: 1rem;
    font-weight: 600;
    color: rgb(var(--primary-rgb, 132, 90, 223));
}

.currency-symbol-preview__hint {
    font-size: 0.8125rem;
    color: #8c9097;
}
</style>
