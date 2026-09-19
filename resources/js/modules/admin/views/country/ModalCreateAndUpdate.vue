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
                            <label for="country-name" class="form-label">
                                {{ t('countries.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-text"></i>
                                </span>
                                <input
                                    id="country-name"
                                    v-model="form.translations[activeLocale]"
                                    type="text"
                                    class="form-control"
                                    :class="activeTranslationInputClass"
                                    :placeholder="t('countries.name_placeholder')"
                                    @input="onTranslationInput(activeLocale)"
                                >
                                <FormFieldFeedback v-bind="activeTranslationFeedback" />
                            </div>
                            <div v-if="activeTranslationMessage" class="invalid-feedback d-block">
                                {{ activeTranslationMessage }}
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="country-code" class="form-label">
                                    {{ t('countries.code') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-code-s-slash-line"></i>
                                    </span>
                                    <input
                                        id="country-code"
                                        v-model="form.code"
                                        type="text"
                                        maxlength="10"
                                        class="form-control text-uppercase"
                                        :class="codeInputClass"
                                        :placeholder="t('countries.code_placeholder')"
                                        @input="onFieldInput('code')"
                                    >
                                    <FormFieldFeedback v-bind="codeFeedback" />
                                </div>
                                <div v-if="codeMessage" class="invalid-feedback d-block">
                                    {{ codeMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="country-dial-code" class="form-label">
                                    {{ t('countries.dial_code') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">+</span>
                                    <input
                                        id="country-dial-code"
                                        v-model="form.dial_code"
                                        type="text"
                                        inputmode="numeric"
                                        maxlength="4"
                                        class="form-control"
                                        :class="dialCodeInputClass"
                                        :placeholder="t('countries.dial_code_placeholder')"
                                        @input="onFieldInput('dial_code')"
                                    >
                                    <FormFieldFeedback v-bind="dialCodeFeedback" />
                                </div>
                                <div v-if="dialCodeMessage" class="invalid-feedback d-block">
                                    {{ dialCodeMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="country-phone-starts-with" class="form-label">
                                    {{ t('countries.phone_starts_with') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-phone-line"></i>
                                    </span>
                                    <input
                                        id="country-phone-starts-with"
                                        v-model="form.phone_starts_with"
                                        type="text"
                                        inputmode="numeric"
                                        maxlength="2"
                                        class="form-control"
                                        :class="phoneStartsWithInputClass"
                                        :placeholder="t('countries.phone_starts_with_placeholder')"
                                        @input="onFieldInput('phone_starts_with')"
                                    >
                                    <FormFieldFeedback v-bind="phoneStartsWithFeedback" />
                                </div>
                                <div v-if="phoneStartsWithMessage" class="invalid-feedback d-block">
                                    {{ phoneStartsWithMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="country-phone-length" class="form-label">
                                    {{ t('countries.phone_length') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-hashtag"></i>
                                    </span>
                                    <input
                                        id="country-phone-length"
                                        v-model="form.phone_length"
                                        type="number"
                                        min="5"
                                        max="15"
                                        class="form-control"
                                        :class="phoneLengthInputClass"
                                        :placeholder="t('countries.phone_length_placeholder')"
                                        @input="onFieldInput('phone_length')"
                                    >
                                    <FormFieldFeedback v-bind="phoneLengthFeedback" />
                                </div>
                                <div v-if="phoneLengthMessage" class="invalid-feedback d-block">
                                    {{ phoneLengthMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <FlagSelect
                                    v-model="form.flag_id"
                                    input-id="country-flag"
                                    :label="t('countries.flag')"
                                    :placeholder="t('countries.flag_placeholder')"
                                    required
                                    :invalid="flagFeedback.show && flagFeedback.invalid"
                                    :error="flagMessage"
                                    :show="show"
                                    :load-on-show="true"
                                    @update:model-value="onSelectChange('flag_id')"
                                />
                            </div>

                            <div class="col-md-6">
                                <CurrencySelect
                                    v-model="form.currency_id"
                                    input-id="country-currency"
                                    :label="t('countries.currency')"
                                    :placeholder="t('countries.currency_placeholder')"
                                    required
                                    :invalid="currencyFeedback.show && currencyFeedback.invalid"
                                    :error="currencyMessage"
                                    :show="show"
                                    :load-on-show="true"
                                    @update:model-value="onSelectChange('currency_id')"
                                />
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('countries.is_default') }}</label>
                                <div
                                    class="toggle toggle-primary mb-0 catalog-modal-toggle"
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
                                <label class="form-label d-block mb-2">{{ t('countries.status') }}</label>
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
                            {{ submitting ? t('countries.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, integer, maxValue, minValue } from '@vuelidate/validators';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../components/catalog/CatalogTranslationTabs.vue';
import CurrencySelect from '../../../../components/catalog/CurrencySelect.vue';
import FlagSelect from '../../../../components/catalog/FlagSelect.vue';
import FormFieldFeedback from '../../../../components/ui/FormFieldFeedback.vue';
import useCatalogTranslations from '../../../../composables/useCatalogTranslations';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../composables/useToast';
import useValidation from '../../../../composables/useValidation';
import {
    formatDialCodeForPayload,
    normalizeDialCode,
    setupCatalogModalWatcher,
} from '../../../../utils/catalog';

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
        default: '/api/admin/v1/countries',
    },
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const {
    stringFieldRules,
    requiredField,
    digitsBetween,
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
    code_alpha3: '',
    dial_code: '',
    phone_starts_with: '',
    phone_length: '',
    flag_id: null,
    currency_id: null,
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
    nameKey: 'countries.name',
    minLength: 2,
    maxLength: 50,
    getV$: () => v$.value,
});

const rules = computed(() => ({
    translations: translationRules.value,
    code: stringFieldRules('countries.code', 10),
    dial_code: {
        required: requiredField('countries.dial_code'),
        digitsBetween: digitsBetween('countries.dial_code', 1, 4),
    },
    phone_starts_with: {
        required: requiredField('countries.phone_starts_with'),
        digitsBetween: digitsBetween('countries.phone_starts_with', 1, 2),
    },
    phone_length: {
        required: requiredField('countries.phone_length'),
        integer: helpers.withMessage(
            () => t('validation.integer', { field: t('countries.phone_length') }),
            integer,
        ),
        minValue: helpers.withMessage(
            () => t('validation.min.numeric', { field: t('countries.phone_length'), min: 5 }),
            minValue(5),
        ),
        maxValue: helpers.withMessage(
            () => t('validation.max.numeric', { field: t('countries.phone_length'), max: 15 }),
            maxValue(15),
        ),
    },
    flag_id: {
        required: requiredField('countries.flag'),
    },
    currency_id: {
        required: requiredField('countries.currency'),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

function recordName(record, lang = locale.value) {
    const translation = record?.translations?.find((item) => item.locale === lang);

    return translation?.name || record?.name || '';
}

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('countries.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('countries.edit_title');
    }

    const name = recordName(record);

    return name
        ? `${t('countries.edit_title')} #${record.id} ${name}`
        : `${t('countries.edit_title')} #${record.id}`;
});

function buildFieldFeedback(fieldKey) {
    return computed(() => fieldFeedback(
        v$.value[fieldKey],
        serverErrors[fieldKey]?.[0],
        form[fieldKey],
    ));
}

function buildFieldInputClass(feedback) {
    return computed(() => ({
        'is-invalid': feedback.value.show && feedback.value.invalid,
        'is-valid': feedback.value.show && feedback.value.valid,
    }));
}

function buildFieldMessage(fieldKey, feedback) {
    return computed(() => {
        if (! feedback.value.invalid) {
            return null;
        }

        return v$.value[fieldKey].$errors[0]?.$message || serverErrors[fieldKey]?.[0] || null;
    });
}

const codeFeedback = buildFieldFeedback('code');
const codeInputClass = buildFieldInputClass(codeFeedback);
const codeMessage = buildFieldMessage('code', codeFeedback);

const dialCodeFeedback = buildFieldFeedback('dial_code');
const dialCodeInputClass = buildFieldInputClass(dialCodeFeedback);
const dialCodeMessage = buildFieldMessage('dial_code', dialCodeFeedback);

const phoneStartsWithFeedback = buildFieldFeedback('phone_starts_with');
const phoneStartsWithInputClass = buildFieldInputClass(phoneStartsWithFeedback);
const phoneStartsWithMessage = buildFieldMessage('phone_starts_with', phoneStartsWithFeedback);

const phoneLengthFeedback = buildFieldFeedback('phone_length');
const phoneLengthInputClass = buildFieldInputClass(phoneLengthFeedback);
const phoneLengthMessage = buildFieldMessage('phone_length', phoneLengthFeedback);

const flagFeedback = buildFieldFeedback('flag_id');
const flagMessage = buildFieldMessage('flag_id', flagFeedback);

const currencyFeedback = buildFieldFeedback('currency_id');
const currencyMessage = buildFieldMessage('currency_id', currencyFeedback);

function onFieldInput(field) {
    clearServerError(field);
    v$.value.$touch();
}

function onSelectChange(field) {
    clearServerError(field);
    v$.value[field]?.$touch();
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
    form.code_alpha3 = '';
    form.dial_code = '';
    form.phone_starts_with = '';
    form.phone_length = '';
    form.flag_id = null;
    form.currency_id = null;
    form.is_default = false;
    form.status = true;
    resetTranslations();
    resetValidation();
}

function fillForm(record) {
    form.code = record?.code ?? '';
    form.code_alpha3 = record?.code_alpha3 ?? '';
    form.dial_code = normalizeDialCode(record?.dial_code ?? '');
    form.phone_starts_with = record?.phone_starts_with ?? '';
    form.phone_length = record?.phone_length ?? '';
    form.flag_id = record?.flag_id ?? record?.flag?.id ?? null;
    form.currency_id = record?.currency_id ?? record?.currency?.id ?? null;
    form.is_default = Boolean(record?.is_default ?? false);
    form.status = Boolean(record?.status ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    return {
        code: form.code.trim(),
        code_alpha3: form.code_alpha3.trim() || null,
        dial_code: formatDialCodeForPayload(form.dial_code),
        phone_starts_with: form.phone_starts_with.trim(),
        phone_length: form.phone_length !== '' && form.phone_length !== null
            ? Number(form.phone_length)
            : null,
        flag_id: form.flag_id,
        currency_id: form.currency_id,
        is_default: form.is_default,
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
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/countries/${props.record.id}`, buildPayload());
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/countries', buildPayload());
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

.catalog-modal-toggle.toggle-primary.on {
    background-color: rgb(var(--primary-rgb, 132, 90, 223)) !important;
}
</style>
