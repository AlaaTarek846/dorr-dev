<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header flag-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ modalTitle }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close flag-modal-close"
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
                            <label for="flag-name" class="form-label">
                                {{ t('flags.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-text"></i>
                                </span>
                                <input
                                    id="flag-name"
                                    v-model="form.translations[activeLocale]"
                                    type="text"
                                    maxlength="50"
                                    class="form-control"
                                    :class="activeTranslationInputClass"
                                    :placeholder="t('flags.name_placeholder')"
                                    @input="onTranslationInput(activeLocale)"
                                >
                                <FormFieldFeedback v-bind="activeTranslationFeedback" />
                            </div>
                            <div v-if="activeTranslationMessage" class="invalid-feedback d-block">
                                {{ activeTranslationMessage }}
                            </div>
                        </div>

                        <div class="row g-3 align-items-end">
                            <div class="col-md-7">
                                <label for="flag-code" class="form-label">
                                    {{ t('flags.code') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-code-s-slash-line"></i>
                                    </span>
                                    <input
                                        id="flag-code"
                                        v-model="form.code"
                                        type="text"
                                        maxlength="3"
                                        class="form-control text-uppercase"
                                        :class="codeInputClass"
                                        :placeholder="t('flags.code_placeholder')"
                                        @input="onCodeInput"
                                    >
                                    <FormFieldFeedback v-bind="codeFeedback" />
                                </div>
                                <div v-if="codeMessage" class="invalid-feedback d-block">
                                    {{ codeMessage }}
                                </div>

                                <div class="flag-code-preview mt-3">
                                    <div
                                        class="flag-code-preview__card"
                                        :class="{ 'flag-code-preview__card--empty': ! normalizedCode }"
                                    >
                                        <div class="flag-code-preview__image-wrap">
                                            <img
                                                v-if="normalizedCode && ! previewImageError"
                                                :src="flagPreviewUrl"
                                                :alt="normalizedCode"
                                                class="flag-code-preview__image"
                                                width="48"
                                                height="36"
                                                @error="previewImageError = true"
                                            >
                                            <span v-else class="flag-code-preview__placeholder">
                                                <i class="ri-flag-line"></i>
                                            </span>
                                        </div>
                                        <div class="flag-code-preview__content">
                                            <span class="flag-code-preview__label">{{ t('flags.code_preview') }}</span>
                                            <span v-if="normalizedCode" class="flag-code-preview__code">{{ normalizedCode }}</span>
                                            <span v-else class="flag-code-preview__hint">{{ t('flags.code_preview_hint') }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label d-block mb-2">{{ t('flags.status') }}</label>
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

                    <div class="modal-footer flag-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">
                            {{ t('close') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('flags.saving') : t('save_changes') }}
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
import adminAxios from '../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../components/catalog/CatalogTranslationTabs.vue';
import useCatalogTranslations from '../../../../composables/useCatalogTranslations';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../composables/useToast';
import FormFieldFeedback from '../../../../components/ui/FormFieldFeedback.vue';
import useValidation from '../../../../composables/useValidation';

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
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const {
    flagCodeRules,
    applyApiErrors,
    fieldFeedback,
} = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const previewImageError = ref(false);
const serverErrors = reactive({});
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    code: '',
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
    nameKey: 'flags.name',
    maxLength: 50,
    getV$: () => v$.value,
});

const normalizedCode = computed(() => form.code.trim().toUpperCase());

const flagPreviewUrl = computed(() => {
    if (! normalizedCode.value) {
        return '';
    }

    return `https://flagsapi.com/${normalizedCode.value}/flat/64.png`;
});

const rules = computed(() => ({
    code: flagCodeRules(),
    translations: translationRules.value,
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

function recordName(record, lang = locale.value) {
    const translation = record?.translations?.find((item) => item.locale === lang);

    return translation?.name || record?.name || '';
}

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('flags.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('flags.edit_title');
    }

    const name = recordName(record);

    return name
        ? `${t('flags.edit_title')} #${record.id} ${name}`
        : `${t('flags.edit_title')} #${record.id}`;
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

function onCodeInput() {
    previewImageError.value = false;
    clearServerError('code');
    v$.value.code.$touch();
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
    form.status = true;
    resetTranslations();
    previewImageError.value = false;
    resetValidation();
}

function fillForm(record) {
    form.code = record?.code ?? '';
    form.status = Boolean(record?.status ?? true);
    fillTranslations(record);
    previewImageError.value = false;
    resetValidation();
}

function buildPayload() {
    return {
        code: form.code.trim().toUpperCase(),
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
            response = await adminAxios.put(`/api/admin/v1/flags/${props.record.id}`, buildPayload());
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/flags', buildPayload());
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

watch(
    () => props.show,
    async (visible) => {
        if (visible) {
            await ensureLanguagesLoaded();

            if (isEdit.value && props.record) {
                fillForm(props.record);
            } else {
                resetForm();
            }

            openModal();
        } else {
            closeModal();
        }
    },
);

watch(
    () => props.record,
    (record) => {
        if (props.show && isEdit.value && record) {
            fillForm(record);
        }
    },
);

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
.flag-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.flag-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.flag-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
    opacity: 0.65;
    background-size: 0.65rem;
}

.flag-modal-close:hover {
    opacity: 1;
}

.flag-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}

.flag-lang-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 1rem;
    border: 1px solid var(--default-border, #dee2e6);
    border-radius: 0.375rem;
    background: #fff;
    color: var(--default-text-color, #333335);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
}

.flag-lang-tab.active {
    border-color: rgb(var(--primary-rgb, 132, 90, 223));
    box-shadow: 0 0 0 1px rgba(var(--primary-rgb, 132, 90, 223), 0.25);
    color: rgb(var(--primary-rgb, 132, 90, 223));
}

.flag-lang-tab--error {
    border-color: #e6533c;
    color: #e6533c;
}

.flag-lang-tab--valid {
    border-color: #26bf94;
    color: #26bf94;
}

.flag-lang-tab i {
    font-size: 1rem;
    line-height: 1;
}

.flag-lang-tab__icon {
    border-radius: 2px;
    object-fit: cover;
}

.flag-code-preview__card {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.875rem 1rem;
    border: 1px dashed rgba(var(--primary-rgb, 132, 90, 223), 0.35);
    border-radius: 0.5rem;
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.04);
    transition: border-color 0.2s ease, background-color 0.2s ease;
}

.flag-code-preview__card--empty {
    border-color: var(--default-border, #dee2e6);
    background: #f8f9fa;
}

.flag-code-preview__image-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 3rem;
    height: 2.25rem;
    flex-shrink: 0;
}

.flag-code-preview__image {
    display: block;
    width: 48px;
    height: 36px;
    object-fit: cover;
    border-radius: 0.25rem;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
}

.flag-code-preview__placeholder {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3rem;
    height: 2.25rem;
    border-radius: 0.25rem;
    background: #e9edf1;
    color: #8c9097;
    font-size: 1.25rem;
}

.flag-code-preview__content {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    min-width: 0;
}

.flag-code-preview__label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #8c9097;
}

.flag-code-preview__code {
    font-size: 1.125rem;
    font-weight: 700;
    color: rgb(var(--primary-rgb, 132, 90, 223));
    letter-spacing: 0.06em;
}

.flag-code-preview__hint {
    font-size: 0.8125rem;
    color: #8c9097;
}
</style>
