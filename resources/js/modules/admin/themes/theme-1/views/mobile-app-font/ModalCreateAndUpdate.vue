<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header font-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ modalTitle }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close font-modal-close"
                            aria-label="Close"
                            @click="close"
                        ></button>
                    </div>
                </div>

                <form @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <div class="mb-3">
                            <label for="font-name" class="form-label">
                                {{ t('mobile_app_fonts.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-text"></i>
                                </span>
                                <input
                                    id="font-name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="nameInputClass"
                                    :placeholder="t('mobile_app_fonts.name_placeholder')"
                                    @input="onNameInput"
                                >
                                <FormFieldFeedback v-bind="nameFeedback" />
                            </div>
                            <div v-if="nameMessage" class="invalid-feedback d-block">
                                {{ nameMessage }}
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="font-slug" class="form-label">
                                {{ t('mobile_app_fonts.slug') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-link"></i>
                                </span>
                                <input
                                    id="font-slug"
                                    v-model="form.slug"
                                    type="text"
                                    class="form-control"
                                    :class="slugInputClass"
                                    :placeholder="t('mobile_app_fonts.slug_placeholder')"
                                    @input="onSlugInput"
                                >
                                <FormFieldFeedback v-bind="slugFeedback" />
                            </div>
                            <div v-if="slugMessage" class="invalid-feedback d-block">
                                {{ slugMessage }}
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="font-files" class="form-label">{{ t('mobile_app_fonts.font_files') }}</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-file-upload-line"></i>
                                </span>
                                <input
                                    id="font-files"
                                    type="file"
                                    class="form-control"
                                    accept=".ttf,.otf,font/ttf,font/otf"
                                    multiple
                                    @change="onFilesChange"
                                >
                            </div>
                            <ul v-if="existingFiles.length" class="list-unstyled mt-2 mb-0 fs-13">
                                <li
                                    v-for="file in existingFiles"
                                    :key="file.id"
                                    class="d-flex align-items-center gap-2 mb-1"
                                >
                                    <i class="ri-file-text-line text-muted"></i>
                                    <span>{{ file.file_name }} ({{ file.weight }})</span>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-link text-danger p-0 ms-auto"
                                        @click="markRemove(file.id)"
                                    >
                                        {{ t('remove') }}
                                    </button>
                                </li>
                            </ul>
                            <div v-if="serverErrors['font_files.0']?.[0]" class="invalid-feedback d-block">
                                {{ serverErrors['font_files.0'][0] }}
                            </div>
                        </div>

                        <div class="row g-3 mb-0">
                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('mobile_app_fonts.status') }}</label>
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
                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('mobile_app_fonts.is_default') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.is_default }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_default = !form.is_default"
                                    @keydown.enter.space.prevent="form.is_default = !form.is_default"
                                >
                                    <span></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer font-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">
                            {{ t('close') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('mobile_app_fonts.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { minLength, maxLength } from '@vuelidate/validators';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';

const RESOURCE_URI = '/api/admin/v1/mobile-app-fonts';

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

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { requiredField, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
let modalInstance = null;
const submitting = ref(false);
const serverErrors = reactive({});
const newFiles = ref([]);
const removeIds = ref([]);
const existingFiles = ref([]);
const slugManuallyEdited = ref(false);

const form = reactive({
    name: '',
    slug: '',
    status: true,
    is_default: false,
    sort_order: 0,
});

const isEdit = computed(() => props.type === 'edit');

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('mobile_app_fonts.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('mobile_app_fonts.edit_title');
    }

    const name = record?.name?.trim();

    return name
        ? `${t('mobile_app_fonts.edit_title')} #${record.id} ${name}`
        : `${t('mobile_app_fonts.edit_title')} #${record.id}`;
});

const rules = computed(() => ({
    name: {
        required: requiredField('mobile_app_fonts.name'),
        minLength: minLength(2),
        maxLength: maxLength(100),
    },
    slug: {
        required: requiredField('mobile_app_fonts.slug'),
        maxLength: maxLength(64),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const normalizedSlug = computed(() => form.slug.trim().toLowerCase());

const nameFeedback = computed(() => fieldFeedback(
    v$.value.name,
    serverErrors.name?.[0],
    form.name,
));

const nameInputClass = computed(() => ({
    'is-invalid': nameFeedback.value.show && nameFeedback.value.invalid,
    'is-valid': nameFeedback.value.show && nameFeedback.value.valid,
}));

const nameMessage = computed(() => {
    if (! nameFeedback.value.invalid) {
        return null;
    }

    return v$.value.name.$errors[0]?.$message || serverErrors.name?.[0] || null;
});

const slugFeedback = computed(() => fieldFeedback(
    v$.value.slug,
    serverErrors.slug?.[0],
    form.slug,
));

const slugInputClass = computed(() => ({
    'is-invalid': slugFeedback.value.show && slugFeedback.value.invalid,
    'is-valid': slugFeedback.value.show && slugFeedback.value.valid,
}));

const slugMessage = computed(() => {
    if (! slugFeedback.value.invalid) {
        return null;
    }

    return v$.value.slug.$errors[0]?.$message || serverErrors.slug?.[0] || null;
});

function slugify(value) {
    return value
        .trim()
        .toLowerCase()
        .replace(/_/g, '-')
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
}

function clearServerError(field) {
    delete serverErrors[field];
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    form.name = '';
    form.slug = '';
    form.status = true;
    form.is_default = false;
    form.sort_order = 0;
    newFiles.value = [];
    removeIds.value = [];
    existingFiles.value = [];
    slugManuallyEdited.value = false;
    resetValidation();
}

function fillForm(record) {
    form.name = record?.name ?? '';
    form.slug = record?.slug ?? '';
    form.status = Boolean(record?.status ?? true);
    form.is_default = Boolean(record?.is_default);
    form.sort_order = record?.sort_order ?? 0;
    existingFiles.value = [...(record?.font_files ?? [])];
    newFiles.value = [];
    removeIds.value = [];
    slugManuallyEdited.value = true;
    resetValidation();
}

function onNameInput() {
    clearServerError('name');
    v$.value.name.$touch();

    if (! slugManuallyEdited.value) {
        form.slug = slugify(form.name);
    }
}

function onSlugInput() {
    slugManuallyEdited.value = true;
    clearServerError('slug');
    form.slug = slugify(form.slug);
    v$.value.slug.$touch();
}

function onFilesChange(event) {
    newFiles.value = [...(event.target.files ?? [])];
    delete serverErrors['font_files.0'];
}

function markRemove(id) {
    removeIds.value.push(id);
    existingFiles.value = existingFiles.value.filter((f) => f.id !== id);
}

function buildFormData() {
    const formData = new FormData();
    formData.append('name', form.name.trim());
    formData.append('slug', normalizedSlug.value);
    formData.append('status', form.status ? '1' : '0');
    formData.append('is_default', form.is_default ? '1' : '0');
    formData.append('sort_order', String(Number(form.sort_order) || 0));
    newFiles.value.forEach((file, index) => {
        formData.append(`font_files[${index}]`, file);
    });
    removeIds.value.forEach((id, index) => {
        formData.append(`remove_font_file_ids[${index}]`, String(id));
    });

    return formData;
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
    (visible) => {
        if (visible) {
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

onMounted(() => {
    modalElement.value?.addEventListener('hidden.bs.modal', onModalHidden);
});

onUnmounted(() => {
    modalElement.value?.removeEventListener('hidden.bs.modal', onModalHidden);
    modalInstance?.dispose();
});
</script>

<style scoped>
.font-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.font-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.font-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
    opacity: 0.65;
    background-size: 0.65rem;
}

.font-modal-close:hover {
    opacity: 1;
}

.font-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}
</style>
