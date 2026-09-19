<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered">
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
                            <label class="form-label d-block">{{ t('service_categories.image') }}</label>
                            <div class="service-category-image-row">
                                <span class="service-category-image-box">
                                    <img :src="imagePreview" alt="" class="service-category-image-box__img">
                                    <label class="service-category-image-box__badge">
                                        <input
                                            ref="imageInput"
                                            type="file"
                                            accept="image/jpeg,image/jpg,image/png,image/webp,image/svg+xml"
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
                                        <span>{{ t('service_categories.change_image') }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-wave service-category-image-actions__btn"
                                        :class="hasCustomImage ? 'btn-outline-danger' : 'btn-light'"
                                        :disabled="! hasCustomImage"
                                        @click="removeImage"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                        <span>{{ t('service_categories.remove_image') }}</span>
                                    </button>
                                </div>
                            </div>
                            <div v-if="serverErrors.image?.[0]" class="invalid-feedback d-block">
                                {{ serverErrors.image[0] }}
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="category-name" class="form-label">
                                {{ t('service_categories.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-text"></i>
                                </span>
                                <input
                                    id="category-name"
                                    v-model="form.translations[activeLocale]"
                                    type="text"
                                    maxlength="100"
                                    class="form-control"
                                    :class="activeTranslationInputClass"
                                    :placeholder="t('service_categories.name_placeholder')"
                                    @input="onTranslationInput(activeLocale)"
                                >
                                <FormFieldFeedback v-bind="activeTranslationFeedback" />
                            </div>
                            <div v-if="activeTranslationMessage" class="invalid-feedback d-block">
                                {{ activeTranslationMessage }}
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="category-parent" class="form-label">{{ t('service_categories.parent') }}</label>
                            <Select
                                id="category-parent"
                                v-model="form.parent_id"
                                :options="parentOptions"
                                option-label="name"
                                option-value="id"
                                :placeholder="t('service_categories.parent_placeholder')"
                                :filter="true"
                                filter-placeholder="Search..."
                                :filter-fields="['name']"
                                :show-clear="true"
                                append-to="self"
                                auto-filter-focus
                                class="w-100"
                            />
                        </div>

                        <div class="mb-3">
                            <label for="category-module-name" class="form-label">{{ t('service_categories.module_name') }}</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-apps-2-line"></i>
                                </span>
                                <input
                                    id="category-module-name"
                                    v-model="form.module_name"
                                    type="text"
                                    maxlength="100"
                                    class="form-control"
                                    :class="{ 'is-invalid': serverErrors.module_name?.[0] }"
                                    :placeholder="t('service_categories.module_name_placeholder')"
                                >
                            </div>
                            <div v-if="serverErrors.module_name?.[0]" class="invalid-feedback d-block">
                                {{ serverErrors.module_name[0] }}
                            </div>
                        </div>

                        <div class="row g-3 mb-3">

                            <div class="col-md-8">
                                <label for="category-sort-order" class="form-label">{{ t('service_categories.sort_order') }}</label>
                                <input
                                    id="category-sort-order"
                                    v-model.number="form.sort_order"
                                    type="number"
                                    min="0"
                                    class="form-control"
                                >
                            </div>

                            <div class="col-md-4">
                                <label class="form-label d-block mb-2">{{ t('service_categories.requires_provider') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.requires_provider }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.requires_provider = !form.requires_provider"
                                    @keydown.enter.space.prevent="form.requires_provider = !form.requires_provider"
                                >
                                    <span></span>
                                </div>
                            </div>

                        </div>

                        <div class="row g-3 mb-3">

                            <div class="col-md-4">
                                <label class="form-label d-block mb-2">{{ t('service_categories.status') }}</label>
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

                            <div class="col-md-4">
                                <label class="form-label d-block mb-2">{{ t('service_categories.is_login_dashboard') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.is_login_dashboard }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_login_dashboard = !form.is_login_dashboard"
                                    @keydown.enter.space.prevent="form.is_login_dashboard = !form.is_login_dashboard"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label d-block mb-2">{{ t('service_categories.is_auto_assign') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.is_auto_assign }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_auto_assign = !form.is_auto_assign"
                                    @keydown.enter.space.prevent="form.is_auto_assign = !form.is_auto_assign"
                                >
                                    <span></span>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('service_categories.saving') : t('save_changes') }}
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
import Select from 'primevue/select';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useCatalogTranslations from '../../../../../../composables/useCatalogTranslations';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { displayTranslatedName, setupCatalogModalWatcher } from '../../../../../../utils/catalog';

const DEFAULT_IMAGE = '/dashboard/themes/theme-1/assets/images/faces/9.jpg';

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
    resourceUri: { type: String, default: '/api/admin/v1/service-categories' },
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { applyApiErrors } = useValidation();

const modalElement = ref(null);
const imageInput = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const parentOptions = ref([]);
const imageFile = ref(null);
const removeImageFlag = ref(false);
const imagePreviewUrl = ref('');
const savedImageUrl = ref('');
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    parent_id: null,
    module_name: '',
    is_login_dashboard: true,
    is_auto_assign: false,
    requires_provider: false,
    status: true,
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
    onTranslationInput,
    resetTranslations,
    fillTranslations,
    buildTranslationsPayload,
    focusInvalidTranslationTab,
} = useCatalogTranslations({
    form,
    serverErrors,
    nameKey: 'service_categories.name',
    minLength: 2,
    maxLength: 100,
    getV$: () => v$.value,
});

const rules = computed(() => ({
    translations: translationRules.value,
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

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
        return t('service_categories.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('service_categories.edit_title');
    }

    const name = displayTranslatedName(record, locale.value);

    return name
        ? `${t('service_categories.edit_title')} #${record.id} ${name}`
        : `${t('service_categories.edit_title')} #${record.id}`;
});

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
    delete serverErrors.image;
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
    form.parent_id = null;
    form.module_name = '';
    form.is_login_dashboard = true;
    form.is_auto_assign = false;
    form.requires_provider = false;
    form.status = true;
    form.sort_order = 0;
    resetTranslations();
    resetImageState();
    resetValidation();
}

function fillForm(record) {
    form.parent_id = record?.parent_id ?? null;
    form.module_name = record?.module_name ?? '';
    form.is_login_dashboard = Boolean(record?.is_login_dashboard ?? true);
    form.is_auto_assign = Boolean(record?.is_auto_assign ?? false);
    form.requires_provider = Boolean(record?.requires_provider ?? false);
    form.status = Boolean(record?.status ?? true);
    form.sort_order = record?.sort_order ?? 0;
    savedImageUrl.value = record?.image_thumb || record?.image || '';
    fillTranslations(record);
    resetImageState();
    savedImageUrl.value = record?.image_thumb || record?.image || '';
    resetValidation();
}

function buildFormData() {
    const formData = new FormData();

    if (form.parent_id) {
        formData.append('parent_id', String(form.parent_id));
    }

    if (form.module_name) {
        formData.append('module_name', form.module_name);
    }

    formData.append('is_login_dashboard', form.is_login_dashboard ? '1' : '0');
    formData.append('is_auto_assign', form.is_auto_assign ? '1' : '0');
    formData.append('requires_provider', form.requires_provider ? '1' : '0');
    formData.append('status', form.status ? '1' : '0');
    formData.append('sort_order', String(Number(form.sort_order) || 0));

    buildTranslationsPayload().forEach((translation, index) => {
        formData.append(`translations[${index}][locale]`, translation.locale);
        formData.append(`translations[${index}][name]`, translation.name);
    });

    if (imageFile.value) {
        formData.append('image', imageFile.value);
    }

    if (removeImageFlag.value) {
        formData.append('remove_image', '1');
    }

    return formData;
}

async function loadParentOptions() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/service-categories/dropdown?parent_id=null');
        const options = data.data ?? [];

        parentOptions.value = options
            .filter((option) => option.id !== props.record?.id)
            .map((option) => ({
                id: option.id,
                name: displayTranslatedName(option, locale.value),
            }));
    } catch {
        parentOptions.value = [];
    }
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
            response = await adminAxios.post(`${props.resourceUri}/${props.record.id}`, formData);
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post(props.resourceUri, formData);
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
    onOpen: loadParentOptions,
});

onMounted(async () => {
    await ensureLanguagesLoaded();
    modalElement.value?.addEventListener('hidden.bs.modal', onModalHidden);
});

onUnmounted(() => {
    modalElement.value?.removeEventListener('hidden.bs.modal', onModalHidden);
    modalInstance?.dispose();

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
