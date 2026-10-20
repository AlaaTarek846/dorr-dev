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
                            <label for="region-name" class="form-label">
                                {{ t('regions.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-text"></i>
                                </span>
                                <input
                                    id="region-name"
                                    v-model="form.translations[activeLocale]"
                                    type="text"
                                    class="form-control"
                                    :class="activeTranslationInputClass"
                                    :placeholder="t('regions.name_placeholder')"
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
                                <label for="region-country" class="form-label">
                                    {{ t('regions.country') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <Select
                                    v-model="form.country_id"
                                    input-id="region-country"
                                    :options="countries"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('regions.country_placeholder')"
                                    filter
                                    :filter-placeholder="t('search_placeholder')"
                                    show-clear
                                    :loading="countriesLoading"
                                    :invalid="Boolean(countryMessage)"
                                    append-to="self"
                                    class="w-100"
                                    @update:model-value="onSelectChange('country_id')"
                                />
                                <div v-if="countryMessage" class="invalid-feedback d-block">
                                    {{ countryMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('regions.status') }}</label>
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
                            {{ submitting ? t('regions.saving') : t('save_changes') }}
                        </button>
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
import useCatalogTranslations from '../../../../../../composables/useCatalogTranslations';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
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
    /** The country picked on the list page, preselected for a new region. */
    defaultCountryId: {
        type: Number,
        default: null,
    },
    resourceUri: {
        type: String,
        default: '/api/admin/v1/regions',
    },
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { requiredField, applyApiErrors } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    country_id: null,
    status: true,
    translations: {},
});

const countries = ref([]);
const countriesLoading = ref(false);

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
    nameKey: 'regions.name',
    minLength: 2,
    maxLength: 80,
    getV$: () => v$.value,
});

const rules = computed(() => ({
    translations: translationRules.value,
    country_id: {
        required: requiredField('regions.country'),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

function recordName(record, lang = locale.value) {
    const translation = record?.translations?.find((item) => item.locale === lang);

    return translation?.name || record?.name || '';
}

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('regions.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('regions.edit_title');
    }

    const name = recordName(record);

    return name
        ? `${t('regions.edit_title')} #${record.id} ${name}`
        : `${t('regions.edit_title')} #${record.id}`;
});

const countryMessage = computed(() => {
    const field = v$.value.country_id;

    if (field.$error) {
        return field.$errors[0]?.$message || serverErrors.country_id?.[0] || null;
    }

    return serverErrors.country_id?.[0] || null;
});

function onSelectChange(field) {
    delete serverErrors[field];
    v$.value[field]?.$touch();
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    form.country_id = props.defaultCountryId ?? null;
    form.status = true;
    resetTranslations();
    resetValidation();
}

function fillForm(record) {
    form.country_id = record?.country_id ?? record?.country?.id ?? null;
    form.status = Boolean(record?.status ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    return {
        country_id: form.country_id,
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
            response = await adminAxios.put(`${props.resourceUri}/${props.record.id}`, buildPayload());
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post(props.resourceUri, buildPayload());
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

async function loadCountries() {
    if (countries.value.length || countriesLoading.value) {
        return;
    }

    countriesLoading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/countries/dropdown');
        countries.value = data?.data ?? [];
    } catch {
        countries.value = [];
    } finally {
        countriesLoading.value = false;
    }
}

setupCatalogModalWatcher({
    props,
    fillForm,
    resetForm,
    openModal,
    closeModal,
    resourceUri: props.resourceUri,
    onOpen: async () => {
        await ensureLanguagesLoaded();
        await loadCountries();
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
    oparegion: 0.65;
    background-size: 0.65rem;
}

.catalog-modal-close:hover {
    oparegion: 1;
}

.catalog-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}

.catalog-modal-toggle.toggle-primary.on {
    background-color: rgb(var(--primary-rgb, 132, 90, 223)) !important;
}
</style>
