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
                            <label for="language-name" class="form-label">
                                {{ t('languages.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-text"></i>
                                </span>
                                <input
                                    id="language-name"
                                    v-model="form.translations[activeLocale]"
                                    type="text"
                                    maxlength="255"
                                    class="form-control"
                                    :class="activeTranslationInputClass"
                                    :placeholder="t('languages.name_placeholder')"
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
                                <label for="language-code" class="form-label">
                                    {{ t('languages.code') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-code-s-slash-line"></i>
                                    </span>
                                    <input
                                        id="language-code"
                                        v-model="form.code"
                                        type="text"
                                        maxlength="10"
                                        class="form-control text-lowercase"
                                        :class="codeInputClass"
                                        :placeholder="t('languages.code_placeholder')"
                                        @input="onCodeInput"
                                    >
                                    <FormFieldFeedback v-bind="codeFeedback" />
                                </div>
                                <div v-if="codeMessage" class="invalid-feedback d-block">
                                    {{ codeMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="language-direction" class="form-label">
                                    {{ t('languages.direction') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <select
                                    id="language-direction"
                                    v-model="form.direction"
                                    class="form-select"
                                    :class="directionInputClass"
                                    @change="onDirectionChange"
                                >
                                    <option value="">{{ t('languages.direction_placeholder') }}</option>
                                    <option value="ltr">{{ t('languages.direction_ltr') }}</option>
                                    <option value="rtl">{{ t('languages.direction_rtl') }}</option>
                                </select>
                                <div v-if="directionMessage" class="invalid-feedback d-block">
                                    {{ directionMessage }}
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <FlagSelect
                                v-model="form.flag_id"
                                input-id="language-flag"
                                :label="t('languages.flag')"
                                :placeholder="t('languages.flag_placeholder')"
                                required
                                :invalid="flagFeedback.show && flagFeedback.invalid"
                                :error="flagMessage"
                                @update:model-value="onFlagChange"
                            />
                        </div>

                        <div class="row g-3">
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label d-block mb-2">{{ t('languages.is_default_website') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.is_default_website }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_default_website = !form.is_default_website"
                                    @keydown.enter.space.prevent="form.is_default_website = !form.is_default_website"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label d-block mb-2">{{ t('languages.is_default_dashboard') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.is_default_dashboard }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_default_dashboard = !form.is_default_dashboard"
                                    @keydown.enter.space.prevent="form.is_default_dashboard = !form.is_default_dashboard"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label d-block mb-2">{{ t('languages.stores_translation') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.stores_translation }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.stores_translation = !form.stores_translation"
                                    @keydown.enter.space.prevent="form.stores_translation = !form.stores_translation"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label d-block mb-2">{{ t('languages.status') }}</label>
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
                            {{ submitting ? t('languages.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../components/catalog/CatalogTranslationTabs.vue';
import FlagSelect from '../../../../components/catalog/FlagSelect.vue';
import FormFieldFeedback from '../../../../components/ui/FormFieldFeedback.vue';
import useCatalogTranslations from '../../../../composables/useCatalogTranslations';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../composables/useToast';
import useValidation from '../../../../composables/useValidation';
import {
    displayTranslatedName,
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
        default: '/api/admin/v1/languages',
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
    direction: 'ltr',
    flag_id: null,
    is_default_website: false,
    is_default_dashboard: false,
    stores_translation: true,
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
    nameKey: 'languages.name',
    getV$: () => v$.value,
});

const rules = computed(() => ({
    code: stringFieldRules('languages.code', 10),
    direction: {
        required: requiredField('languages.direction'),
    },
    flag_id: {
        required: requiredField('languages.flag'),
    },
    translations: translationRules.value,
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('languages.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('languages.edit_title');
    }

    const name = displayTranslatedName(record, locale.value);

    return name
        ? `${t('languages.edit_title')} #${record.id} ${name}`
        : `${t('languages.edit_title')} #${record.id}`;
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

const directionFeedback = computed(() => fieldFeedback(
    v$.value.direction,
    serverErrors.direction?.[0],
    form.direction,
));

const directionInputClass = computed(() => ({
    'is-invalid': directionFeedback.value.show && directionFeedback.value.invalid,
    'is-valid': directionFeedback.value.show && directionFeedback.value.valid,
}));

const directionMessage = computed(() => {
    if (! directionFeedback.value.invalid) {
        return null;
    }

    return v$.value.direction.$errors[0]?.$message || serverErrors.direction?.[0] || null;
});

const flagFeedback = computed(() => fieldFeedback(
    v$.value.flag_id,
    serverErrors.flag_id?.[0],
    form.flag_id,
));

const flagMessage = computed(() => {
    if (! flagFeedback.value.invalid) {
        return null;
    }

    return v$.value.flag_id.$errors[0]?.$message || serverErrors.flag_id?.[0] || null;
});

function onCodeInput() {
    clearServerError('code');
    v$.value.code.$touch();
}

function onDirectionChange() {
    clearServerError('direction');
    v$.value.direction.$touch();
}

function onFlagChange() {
    clearServerError('flag_id');
    v$.value.flag_id.$touch();
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
    form.direction = 'ltr';
    form.flag_id = null;
    form.is_default_website = false;
    form.is_default_dashboard = false;
    form.stores_translation = true;
    form.status = true;
    resetTranslations();
    resetValidation();
}

function fillForm(record) {
    form.code = record?.code ?? '';
    form.direction = record?.direction ?? 'ltr';
    form.flag_id = record?.flag_id ?? record?.flag?.id ?? null;
    form.is_default_website = Boolean(record?.is_default_website ?? false);
    form.is_default_dashboard = Boolean(record?.is_default_dashboard ?? false);
    form.stores_translation = Boolean(record?.stores_translation ?? true);
    form.status = Boolean(record?.status ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    return {
        code: form.code.trim().toLowerCase(),
        direction: form.direction,
        flag_id: form.flag_id,
        is_default_website: form.is_default_website,
        is_default_dashboard: form.is_default_dashboard,
        stores_translation: form.stores_translation,
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
            response = await adminAxios.put(`/api/admin/v1/languages/${props.record.id}`, buildPayload());
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/languages', buildPayload());
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

</style>
