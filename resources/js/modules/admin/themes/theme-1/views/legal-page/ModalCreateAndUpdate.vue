<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header legal-page-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ modalTitle }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close legal-page-modal-close"
                            aria-label="Close"
                            @click="close"
                        ></button>
                    </div>
                </div>

                <form v-if="activeLocale" @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
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

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="legal-page-type" class="form-label">
                                    {{ t('legal_pages.type') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <Select
                                    id="legal-page-type"
                                    v-model="form.type"
                                    :options="typeChoices"
                                    option-label="label"
                                    option-value="value"
                                    :placeholder="t('legal_pages.type_placeholder')"
                                    :invalid="Boolean(serverErrors.type?.[0])"
                                    append-to="self"
                                    autofocus
                                    class="w-100"
                                    @change="clearServerError('type')"
                                />
                                <div v-if="serverErrors.type?.[0]" class="invalid-feedback d-block">
                                    {{ serverErrors.type[0] }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="legal-page-service" class="form-label">{{ t('legal_pages.service') }}</label>
                                <Select
                                    id="legal-page-service"
                                    v-model="form.service_id"
                                    :options="serviceChoices"
                                    option-label="name"
                                    option-value="id"
                                    :placeholder="t('legal_pages.service_placeholder')"
                                    :filter="true"
                                    filter-placeholder="Search..."
                                    :filter-fields="['name']"
                                    :show-clear="true"
                                    :invalid="Boolean(serverErrors.service_id?.[0])"
                                    append-to="self"
                                    auto-filter-focus
                                    class="w-100"
                                    @change="clearServerError('service_id')"
                                />
                                <div v-if="serverErrors.service_id?.[0]" class="invalid-feedback d-block">
                                    {{ serverErrors.service_id[0] }}
                                </div>
                            </div>
                        </div>

                        <div class="mb-3 mt-3">
                            <label class="form-label">
                                {{ t('legal_pages.content') }}
                                <span class="text-danger">*</span>
                            </label>
                            <CatalogRichTextEditor
                                :model-value="form.translations[activeLocale].content"
                                :placeholder="t('legal_pages.content_placeholder')"
                                :label="t('rich_text_editor_label')"
                                :dir="activeLocale === 'ar' ? 'rtl' : 'ltr'"
                                :invalid="fieldInputClass(activeLocale, 'content')['is-invalid']"
                                :error-message="fieldMessage(activeLocale, 'content')"
                                @update:model-value="onContentInput($event)"
                            />
                        </div>

                        <div class="row g-3 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('legal_pages.status') }}</label>
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

                    <div class="modal-footer legal-page-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">
                            {{ t('close') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('legal_pages.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>

                <div v-else class="modal-body text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">{{ t('legal_pages.loading') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from 'primevue/select';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogRichTextEditor from '../../../../../../components/catalog/CatalogRichTextEditor.vue';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import useCatalogTranslationFields from '../../../../../../composables/useCatalogTranslationFields';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';

const RESOURCE_URI = '/api/admin/v1/legal-pages';

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
    defaultType: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { applyApiErrors } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const serviceOptions = ref([]);
const usedServiceIds = ref([]);
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    type: 'privacy',
    service_id: null,
    status: true,
    translations: {},
});

const {
    activeLocale,
    storableLanguages,
    translationRules,
    ensureLanguagesLoaded,
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
        { name: 'content', labelKey: 'legal_pages.content', max: 65535, min: 2 },
    ],
    getV$: () => v$.value,
});

const rules = computed(() => ({
    translations: translationRules.value,
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const typeChoices = computed(() => [
    { value: 'privacy', label: t('legal_pages.type_privacy') },
    { value: 'term', label: t('legal_pages.type_term') },
]);

const serviceChoices = computed(() => {
    const used = new Set(usedServiceIds.value);

    return serviceOptions.value.filter((option) => ! used.has(Number(option.id)));
});

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('legal_pages.create_title');
    }

    return props.record?.id
        ? `${t('legal_pages.edit_title')} #${props.record.id}`
        : t('legal_pages.edit_title');
});

async function loadServiceOptions() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/service-categories/dropdown');

        serviceOptions.value = data.data ?? [];
    } catch {
        serviceOptions.value = [];
    }
}

async function loadUsedServiceIds() {
    try {
        const { data } = await adminAxios.get(RESOURCE_URI, { params: { all: 1 } });

        // A service is only "taken" by another page of the SAME type, because the
        // unique constraint is (type, service_id, deleted_at).
        usedServiceIds.value = (data.data ?? [])
            .filter((item) => item.id !== props.record?.id
                && item.service_id != null
                && item.type === form.type)
            .map((item) => Number(item.service_id));
    } catch {
        usedServiceIds.value = [];
    }
}

function clearServerError(field) {
    delete serverErrors[field];
}

function onContentInput(value) {
    form.translations[activeLocale.value].content = value;
    onTranslationInput(activeLocale.value, 'content');
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    form.type = props.defaultType === 'term' ? 'term' : 'privacy';
    form.service_id = null;
    form.status = true;
    resetTranslations();
    resetValidation();
}

function fillForm(record) {
    form.type = record?.type ?? (props.defaultType === 'term' ? 'term' : 'privacy');
    form.service_id = record?.service_id ?? null;
    form.status = Boolean(record?.status ?? true);
    fillTranslations(record);
    resetValidation();
}

function buildPayload() {
    return {
        type: form.type,
        service_id: form.service_id || null,
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
            response = await adminAxios.put(`${RESOURCE_URI}/${props.record.id}`, buildPayload());
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post(RESOURCE_URI, buildPayload());
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
            await loadServiceOptions();

            if (isEdit.value && props.record) {
                fillForm(props.record);
            } else {
                resetForm();
            }

            await loadUsedServiceIds();
            openModal();
        } else {
            closeModal();
        }
    },
);

watch(
    () => [props.record?.id, form.type],
    async ([id]) => {
        if (props.show && isEdit.value && id) {
            await loadUsedServiceIds();
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
.legal-page-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.legal-page-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.legal-page-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
    opacity: 0.65;
    background-size: 0.65rem;
}

.legal-page-modal-close:hover {
    opacity: 1;
}

.legal-page-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}
</style>