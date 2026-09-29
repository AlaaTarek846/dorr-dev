<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header catalog-modal-header">
                    <h6 class="modal-title mb-0">{{ modalTitle }}</h6>
                    <button type="button" class="btn-close catalog-modal-close" aria-label="Close" @click="close"></button>
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
                            <label class="form-label">{{ t('mobile_app_fonts.name') }} <span class="text-danger">*</span></label>
                            <input
                                v-model="form.translations[activeLocale]"
                                type="text"
                                class="form-control"
                                :class="activeTranslationInputClass"
                                @input="onTranslationInput(activeLocale)"
                            >
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ t('mobile_app_fonts.slug') }} <span class="text-danger">*</span></label>
                            <input v-model="form.slug" type="text" class="form-control" :class="slugInputClass" @input="clearError('slug')">
                            <div v-if="serverErrors.slug?.[0]" class="invalid-feedback d-block">{{ serverErrors.slug[0] }}</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label d-block">{{ t('mobile_app_fonts.status') }}</label>
                                <div class="toggle toggle-success mb-0" :class="{ on: form.status }" role="button" @click="form.status = !form.status">
                                    <span></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label d-block">{{ t('mobile_app_fonts.is_default') }}</label>
                                <div class="toggle toggle-success mb-0" :class="{ on: form.is_default }" role="button" @click="form.is_default = !form.is_default">
                                    <span></span>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ t('mobile_app_fonts.font_files') }}</label>
                            <input type="file" class="form-control" accept=".ttf,.otf,font/ttf,font/otf" multiple @change="onFilesChange">
                            <ul v-if="existingFiles.length" class="list-unstyled mt-2 mb-0 fs-13">
                                <li v-for="file in existingFiles" :key="file.id" class="d-flex align-items-center gap-2 mb-1">
                                    <span>{{ file.file_name }} ({{ file.weight }})</span>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="markRemove(file.id)">{{ t('remove') }}</button>
                                </li>
                            </ul>
                            <div v-if="serverErrors['font_files.0']?.[0]" class="invalid-feedback d-block">{{ serverErrors['font_files.0'][0] }}</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('cancel') }}</button>
                        <button type="submit" class="btn btn-primary" :disabled="submitting">
                            {{ submitting ? t('mobile_app_fonts.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import useVuelidate from '@vuelidate/core';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import useCatalogTranslations from '../../../../../../composables/useCatalogTranslations';

const RESOURCE_URI = '/api/admin/v1/mobile-app-fonts';

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { requiredField, applyApiErrors } = useValidation();

const modalElement = ref(null);
let modalInstance = null;
const submitting = ref(false);
const serverErrors = reactive({});
const newFiles = ref([]);
const removeIds = ref([]);
const existingFiles = ref([]);

const form = reactive({
    slug: '',
    status: true,
    is_default: false,
    sort_order: 0,
    translations: {},
});

let v$;

const {
    storableLanguages,
    activeLocale,
    ensureLanguagesLoaded,
    fillTranslations,
    buildTranslationsPayload,
    translationTabClass,
    translationTabFeedback,
    activeTranslationInputClass,
    onTranslationInput,
    focusInvalidTranslationTab,
    resetTranslations,
    translationRules,
} = useCatalogTranslations({
    form,
    serverErrors,
    nameKey: 'mobile_app_fonts.name',
    minLength: 2,
    maxLength: 100,
    getV$: () => v$.value,
});

const isEdit = computed(() => props.type === 'edit');
const modalTitle = computed(() => (isEdit.value ? t('mobile_app_fonts.edit_title') : t('mobile_app_fonts.create_title')));

const rules = computed(() => ({
    slug: { required: requiredField('mobile_app_fonts.slug') },
    translations: translationRules.value,
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const slugInputClass = computed(() => ({ 'is-invalid': serverErrors.slug?.[0] }));

function clearError(field) {
    delete serverErrors[field];
}

function resetForm() {
    form.slug = '';
    form.status = true;
    form.is_default = false;
    form.sort_order = 0;
    resetTranslations();
    newFiles.value = [];
    removeIds.value = [];
    existingFiles.value = [];
    applyApiErrors(serverErrors, {});
    v$.value.$reset();
}

function fillForm(record) {
    form.slug = record?.slug ?? '';
    form.status = Boolean(record?.status);
    form.is_default = Boolean(record?.is_default);
    form.sort_order = record?.sort_order ?? 0;
    fillTranslations(record);
    existingFiles.value = [...(record?.font_files ?? [])];
    newFiles.value = [];
    removeIds.value = [];
}

function onFilesChange(event) {
    newFiles.value = [...(event.target.files ?? [])];
}

function markRemove(id) {
    removeIds.value.push(id);
    existingFiles.value = existingFiles.value.filter((f) => f.id !== id);
}

function buildFormData() {
    const formData = new FormData();
    formData.append('slug', form.slug.trim());
    formData.append('status', form.status ? '1' : '0');
    formData.append('is_default', form.is_default ? '1' : '0');
    formData.append('sort_order', String(Number(form.sort_order) || 0));
    buildTranslationsPayload().forEach((translation, index) => {
        formData.append(`translations[${index}][locale]`, translation.locale);
        formData.append(`translations[${index}][name]`, translation.name);
    });
    newFiles.value.forEach((file, index) => {
        formData.append(`font_files[${index}]`, file);
    });
    removeIds.value.forEach((id, index) => {
        formData.append(`remove_font_file_ids[${index}]`, String(id));
    });

    return formData;
}

function openModal() {
    if (! modalElement.value) return;
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
        const formData = buildFormData();
        let response;
        if (isEdit.value && props.record?.id) {
            formData.append('_method', 'PUT');
            response = await adminAxios.post(`${RESOURCE_URI}/${props.record.id}`, formData);
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post(RESOURCE_URI, formData);
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
            if (isEdit.value && props.record) fillForm(props.record);
            else resetForm();
            openModal();
        } else {
            closeModal();
        }
    },
);
</script>
