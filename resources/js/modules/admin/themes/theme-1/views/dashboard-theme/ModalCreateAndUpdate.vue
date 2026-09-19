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
                        <h6 class="modal-title mb-0">{{ modalTitle }}</h6>
                        <button type="button" class="btn-close catalog-modal-close" aria-label="Close" @click="close"></button>
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

                        <div class="mb-4">
                            <label class="form-label d-block">{{ t('dashboard_themes.preview_image') }}</label>
                            <div class="service-category-image-row">
                                <span class="service-category-image-box">
                                    <img :src="imagePreview" alt="" class="service-category-image-box__img">
                                    <label class="service-category-image-box__badge">
                                        <input
                                            ref="imageInput"
                                            type="file"
                                            accept="image/jpeg,image/jpg,image/png,image/webp"
                                            class="position-absolute w-100 h-100 op-0"
                                            @change="onImageChange"
                                        >
                                        <i class="fe fe-camera"></i>
                                    </label>
                                </span>
                                <div class="service-category-image-actions">
                                    <button
                                        type="button"
                                        class="btn btn-primary btn-sm btn-wave service-category-image-actions__btn"
                                        @click="imageInput?.click()"
                                    >
                                        <i class="ri-image-edit-line"></i>
                                        <span>{{ t('dashboard_themes.change_preview') }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-wave service-category-image-actions__btn"
                                        :class="hasCustomImage ? 'btn-outline-danger' : 'btn-light'"
                                        :disabled="! hasCustomImage"
                                        @click="removeImage"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                        <span>{{ t('dashboard_themes.remove_preview') }}</span>
                                    </button>
                                </div>
                            </div>
                            <div v-if="serverErrors.preview_image?.[0]" class="invalid-feedback d-block">
                                {{ serverErrors.preview_image[0] }}
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="theme-name" class="form-label">
                                {{ t('dashboard_themes.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-text"></i>
                                </span>
                                <input
                                    id="theme-name"
                                    v-model="form.translations[activeLocale]"
                                    type="text"
                                    maxlength="100"
                                    class="form-control"
                                    :class="activeTranslationInputClass"
                                    :placeholder="t('dashboard_themes.name_placeholder')"
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
                                <label for="theme-slug" class="form-label">
                                    {{ t('dashboard_themes.slug') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-link"></i>
                                    </span>
                                    <input
                                        id="theme-slug"
                                        v-model="form.slug"
                                        type="text"
                                        maxlength="50"
                                        class="form-control"
                                        :class="slugInputClass"
                                        :placeholder="t('dashboard_themes.slug_placeholder')"
                                        @input="onSlugInput"
                                    >
                                    <FormFieldFeedback v-bind="slugFeedback" />
                                </div>
                                <div v-if="slugMessage" class="invalid-feedback d-block">
                                    {{ slugMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="theme-path" class="form-label">
                                    {{ t('dashboard_themes.path') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-folder-line"></i>
                                    </span>
                                    <input
                                        id="theme-path"
                                        v-model="form.path"
                                        type="text"
                                        maxlength="100"
                                        class="form-control"
                                        :class="pathInputClass"
                                        :placeholder="t('dashboard_themes.path_placeholder')"
                                        @input="onPathInput"
                                    >
                                    <FormFieldFeedback v-bind="pathFeedback" />
                                </div>
                                <div v-if="pathMessage" class="invalid-feedback d-block">
                                    {{ pathMessage }}
                                </div>
                                <small class="text-muted">{{ t('dashboard_themes.path_hint') }}</small>
                            </div>
                        </div>

                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="theme-sort-order" class="form-label">{{ t('dashboard_themes.sort_order') }}</label>
                                <input
                                    id="theme-sort-order"
                                    v-model.number="form.sort_order"
                                    type="number"
                                    min="0"
                                    max="65535"
                                    class="form-control"
                                    :class="sortOrderInputClass"
                                    @input="onSortOrderInput"
                                >
                                <div v-if="sortOrderMessage" class="invalid-feedback d-block">
                                    {{ sortOrderMessage }}
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label d-block mb-2">{{ t('dashboard_themes.is_default') }}</label>
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

                            <div class="col-md-4">
                                <label class="form-label d-block mb-2">{{ t('dashboard_themes.status') }}</label>
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
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, integer, maxLength, maxValue, minValue, required } from '@vuelidate/validators';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useCatalogTranslations from '../../../../../../composables/useCatalogTranslations';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { displayTranslatedName, setupCatalogModalWatcher } from '../../../../../../utils/catalog';

const RESOURCE_URI = '/api/admin/v1/dashboard-themes';
const DEFAULT_IMAGE = '/dashboard/themes/theme-1/assets/images/media/media-1.jpg';

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
const imageInput = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const imageFile = ref(null);
const removeImageFlag = ref(false);
const imagePreviewUrl = ref('');
const savedImageUrl = ref('');
const slugTouched = ref(false);
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    slug: '',
    path: '',
    status: true,
    is_default: false,
    sort_order: 0,
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
    onTranslationInput: baseOnTranslationInput,
    resetTranslations,
    fillTranslations,
    buildTranslationsPayload,
    focusInvalidTranslationTab,
} = useCatalogTranslations({
    form,
    serverErrors,
    nameKey: 'dashboard_themes.name',
    minLength: 2,
    maxLength: 100,
    getV$: () => v$.value,
});

const slugPattern = helpers.withMessage(
    () => t('validation.regex', { attribute: t('dashboard_themes.slug') }),
    helpers.regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/),
);

const pathPattern = helpers.withMessage(
    () => t('validation.regex', { attribute: t('dashboard_themes.path') }),
    helpers.regex(/^[a-z0-9]+(?:-[a-z0-9_-]+)*$/i),
);

const rules = computed(() => ({
    translations: translationRules.value,
    slug: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('dashboard_themes.slug') }),
            required,
        ),
        maxLength: helpers.withMessage(
            () => t('validation.max.string', { field: t('dashboard_themes.slug'), max: 50 }),
            maxLength(50),
        ),
        slugPattern,
    },
    path: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('dashboard_themes.path') }),
            required,
        ),
        maxLength: helpers.withMessage(
            () => t('validation.max.string', { field: t('dashboard_themes.path'), max: 100 }),
            maxLength(100),
        ),
        pathPattern,
    },
    sort_order: {
        integer: helpers.withMessage(
            () => t('validation.integer', { attribute: t('dashboard_themes.sort_order') }),
            integer,
        ),
        minValue: helpers.withMessage(
            () => t('validation.min.numeric', { attribute: t('dashboard_themes.sort_order'), min: 0 }),
            minValue(0),
        ),
        maxValue: helpers.withMessage(
            () => t('validation.max.numeric', { attribute: t('dashboard_themes.sort_order'), max: 65535 }),
            maxValue(65535),
        ),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const slugFeedback = computed(() => fieldFeedback(v$.value.slug, serverErrors.slug));
const pathFeedback = computed(() => fieldFeedback(v$.value.path, serverErrors.path));

const slugInputClass = computed(() => slugFeedback.value.inputClass);
const pathInputClass = computed(() => pathFeedback.value.inputClass);
const sortOrderInputClass = computed(() => (serverErrors.sort_order?.length ? 'is-invalid' : ''));

const slugMessage = computed(() => slugFeedback.value.message || serverErrors.slug?.[0] || '');
const pathMessage = computed(() => pathFeedback.value.message || serverErrors.path?.[0] || '');
const sortOrderMessage = computed(() => serverErrors.sort_order?.[0] || '');

const hasCustomImage = computed(() => Boolean(
    imageFile.value || (savedImageUrl.value && ! removeImageFlag.value),
));

const imagePreview = computed(() => {
    if (imagePreviewUrl.value) {
        return imagePreviewUrl.value;
    }

    if (removeImageFlag.value || ! savedImageUrl.value) {
        return DEFAULT_IMAGE;
    }

    return savedImageUrl.value;
});

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('dashboard_themes.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('dashboard_themes.edit_title');
    }

    const name = displayTranslatedName(record, locale.value);

    return name
        ? `${t('dashboard_themes.edit_title')} #${record.id} ${name}`
        : `${t('dashboard_themes.edit_title')} #${record.id}`;
});

function slugify(value) {
    return String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .replace(/-{2,}/g, '-');
}

function onTranslationInput(localeCode) {
    baseOnTranslationInput(localeCode);

    if (! slugTouched.value && ! isEdit.value && localeCode === activeLocale.value) {
        form.slug = slugify(form.translations[localeCode]);
    }
}

function onSlugInput() {
    slugTouched.value = true;
    form.slug = slugify(form.slug);
    delete serverErrors.slug;
}

function onPathInput() {
    form.path = String(form.path ?? '').trim();
    delete serverErrors.path;
}

function onSortOrderInput() {
    delete serverErrors.sort_order;
}

function resetImageState() {
    imageFile.value = null;
    removeImageFlag.value = false;
    savedImageUrl.value = '';

    if (imagePreviewUrl.value) {
        URL.revokeObjectURL(imagePreviewUrl.value);
        imagePreviewUrl.value = '';
    }

    if (imageInput.value) {
        imageInput.value.value = '';
    }
}

function onImageChange(event) {
    const file = event.target.files?.[0];

    if (! file) {
        return;
    }

    if (imagePreviewUrl.value) {
        URL.revokeObjectURL(imagePreviewUrl.value);
    }

    imageFile.value = file;
    removeImageFlag.value = false;
    imagePreviewUrl.value = URL.createObjectURL(file);
    delete serverErrors.preview_image;
}

function removeImage() {
    resetImageState();
    removeImageFlag.value = true;
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    form.slug = '';
    form.path = '';
    form.status = true;
    form.is_default = false;
    form.sort_order = 0;
    slugTouched.value = false;
    resetTranslations();
    resetImageState();
    resetValidation();
}

function fillForm(record) {
    form.slug = record?.slug ?? '';
    form.path = record?.path ?? '';
    form.status = Boolean(record?.status ?? true);
    form.is_default = Boolean(record?.is_default ?? false);
    form.sort_order = record?.sort_order ?? 0;
    slugTouched.value = true;
    fillTranslations(record);
    resetImageState();
    savedImageUrl.value = record?.preview_image_thumb || record?.preview_image || '';
    resetValidation();
}

function buildFormData() {
    const formData = new FormData();

    formData.append('slug', form.slug.trim());
    formData.append('path', form.path.trim());
    formData.append('status', form.status ? '1' : '0');
    formData.append('is_default', form.is_default ? '1' : '0');
    formData.append('sort_order', String(Number(form.sort_order) || 0));

    buildTranslationsPayload().forEach((translation, index) => {
        formData.append(`translations[${index}][locale]`, translation.locale);
        formData.append(`translations[${index}][name]`, translation.name);
    });

    if (imageFile.value) {
        formData.append('preview_image', imageFile.value);
    }

    if (removeImageFlag.value) {
        formData.append('remove_preview_image', '1');
    }

    return formData;
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

onMounted(() => {
    setupCatalogModalWatcher({
        props,
        fillForm,
        resetForm,
        openModal,
        closeModal,
        resourceUri: RESOURCE_URI,
    });

    modalElement.value?.addEventListener('hidden.bs.modal', onModalHidden);
});

onUnmounted(() => {
    modalElement.value?.removeEventListener('hidden.bs.modal', onModalHidden);

    if (imagePreviewUrl.value) {
        URL.revokeObjectURL(imagePreviewUrl.value);
    }
});

watch(
    () => props.show,
    async (visible) => {
        if (visible) {
            await ensureLanguagesLoaded();
        }
    },
);
</script>

<style scoped>
.catalog-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.catalog-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
}

.catalog-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    opacity: 0.65;
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

.service-category-image-row {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    flex-wrap: wrap;
}

.service-category-image-actions {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    min-width: 9.75rem;
}

.service-category-image-actions__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    padding-inline: 0.875rem;
    font-weight: 500;
    white-space: nowrap;
}

.service-category-image-actions__btn i {
    font-size: 1rem;
    line-height: 1;
}

.service-category-image-actions__btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.service-category-image-box {
    position: relative;
    display: inline-flex;
    width: 5rem;
    height: 5rem;
    flex-shrink: 0;
    overflow: hidden;
    border-radius: 0.5rem;
    background: #eef1f5;
    border: 1px solid var(--default-border, #dee2e6);
}

.service-category-image-box__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.service-category-image-box__badge {
    position: absolute;
    inset-inline-end: 0.35rem;
    inset-block-end: 0.35rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    margin: 0;
    border-radius: 999px;
    background: rgb(var(--primary-rgb, 132, 90, 223));
    color: #fff;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

.service-category-image-box__badge i {
    font-size: 0.875rem;
    line-height: 1;
}
</style>
