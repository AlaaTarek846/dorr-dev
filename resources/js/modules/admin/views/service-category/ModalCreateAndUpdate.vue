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
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="category-name-ar" class="form-label">
                                    {{ t('service_categories.name_ar') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="category-name-ar"
                                    v-model="form.name_ar"
                                    type="text"
                                    maxlength="255"
                                    class="form-control"
                                    :class="nameArInputClass"
                                    :placeholder="t('service_categories.name_ar_placeholder')"
                                    @input="onNameArInput"
                                >
                                <div v-if="nameArMessage" class="invalid-feedback d-block">{{ nameArMessage }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="category-name-en" class="form-label">{{ t('service_categories.name_en') }}</label>
                                <input
                                    id="category-name-en"
                                    v-model="form.name_en"
                                    type="text"
                                    maxlength="255"
                                    class="form-control"
                                    :placeholder="t('service_categories.name_en_placeholder')"
                                >
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="category-slug" class="form-label">
                                {{ t('service_categories.slug') }}
                                <span class="text-danger">*</span>
                            </label>
                            <input
                                id="category-slug"
                                v-model="form.slug"
                                type="text"
                                maxlength="255"
                                class="form-control"
                                :class="slugInputClass"
                                dir="ltr"
                                :placeholder="t('service_categories.slug_placeholder')"
                                @input="onSlugInput"
                            >
                            <div v-if="slugMessage" class="invalid-feedback d-block">{{ slugMessage }}</div>
                            <div v-else class="form-text">{{ t('service_categories.slug_hint') }}</div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="category-parent" class="form-label">{{ t('service_categories.parent') }}</label>
                                <select id="category-parent" v-model="form.parent_id" class="form-select">
                                    <option :value="null">{{ t('service_categories.parent_placeholder') }}</option>
                                    <option v-for="option in parentOptions" :key="option.id" :value="option.id">
                                        {{ locale === 'ar' ? (option.name || option.name_en) : (option.name_en || option.name) }}
                                    </option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="category-department" class="form-label">{{ t('service_categories.department') }}</label>
                                <input
                                    id="category-department"
                                    v-model="form.department"
                                    type="text"
                                    maxlength="255"
                                    class="form-control"
                                    list="category-department-options"
                                    :placeholder="t('service_categories.department_placeholder')"
                                >
                                <datalist id="category-department-options">
                                    <option v-for="dept in departmentOptions" :key="dept" :value="dept" />
                                </datalist>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="category-base-model" class="form-label">{{ t('service_categories.base_model') }}</label>
                            <select id="category-base-model" v-model="form.base_model" class="form-select">
                                <option :value="null">{{ t('service_categories.base_model_placeholder') }}</option>
                                <option v-for="model in BASE_MODELS" :key="model" :value="model">{{ model }}</option>
                            </select>
                        </div>

                        <div class="row g-3 align-items-start mb-3">
                            <div class="col-md-6">
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

                            <div v-if="form.requires_provider" class="col-md-6">
                                <label for="category-provider-type" class="form-label">{{ t('service_categories.provider_type_label') }}</label>
                                <input
                                    id="category-provider-type"
                                    v-model="form.provider_type_label"
                                    type="text"
                                    maxlength="255"
                                    class="form-control"
                                    :placeholder="t('service_categories.provider_type_label_placeholder')"
                                >
                            </div>
                        </div>

                        <div class="row g-3 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('service_categories.is_active') }}</label>
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
                                <label for="category-sort-order" class="form-label">{{ t('service_categories.sort_order') }}</label>
                                <input
                                    id="category-sort-order"
                                    v-model.number="form.sort_order"
                                    type="number"
                                    min="0"
                                    class="form-control"
                                >
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
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../composables/useToast';
import useValidation from '../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../utils/catalog';

const BASE_MODELS = [
    'chat', 'trip', 'booking', 'order', 'delivery', 'service_request',
    'on_demand', 'appointment', 'ticket', 'project',
];

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
    resourceUri: { type: String, default: '/api/admin/v1/service-categories' },
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { requiredField, maxString, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const parentOptions = ref([]);
const departmentOptions = ref([]);
const slugManuallyEdited = ref(false);
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    parent_id: null,
    name_ar: '',
    name_en: '',
    slug: '',
    department: '',
    base_model: null,
    requires_provider: true,
    provider_type_label: '',
    status: true,
    sort_order: 0,
});

const rules = computed(() => ({
    name_ar: {
        required: requiredField('service_categories.name_ar'),
        maxLength: maxString('service_categories.name_ar', 255),
    },
    slug: {
        required: requiredField('service_categories.slug'),
        maxLength: maxString('service_categories.slug', 255),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('service_categories.create_title');
    }

    const record = props.record;

    return record?.id
        ? `${t('service_categories.edit_title')} #${record.id}`
        : t('service_categories.edit_title');
});

const nameArFeedback = computed(() => fieldFeedback(v$.value.name_ar, serverErrors.name_ar?.[0], form.name_ar));
const nameArInputClass = computed(() => ({
    'is-invalid': nameArFeedback.value.show && nameArFeedback.value.invalid,
    'is-valid': nameArFeedback.value.show && nameArFeedback.value.valid,
}));
const nameArMessage = computed(() => (
    nameArFeedback.value.invalid
        ? (v$.value.name_ar.$errors[0]?.$message || serverErrors.name_ar?.[0] || null)
        : null
));

const slugFeedback = computed(() => fieldFeedback(v$.value.slug, serverErrors.slug?.[0], form.slug));
const slugInputClass = computed(() => ({
    'is-invalid': slugFeedback.value.show && slugFeedback.value.invalid,
    'is-valid': slugFeedback.value.show && slugFeedback.value.valid,
}));
const slugMessage = computed(() => (
    slugFeedback.value.invalid
        ? (v$.value.slug.$errors[0]?.$message || serverErrors.slug?.[0] || null)
        : null
));

function slugify(value) {
    return String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9؀-ۿ\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-');
}

function onNameArInput() {
    clearServerError('name_ar');
    v$.value.name_ar.$touch();

    if (! isEdit.value && ! slugManuallyEdited.value) {
        form.slug = slugify(form.name_ar);
    }
}

function onSlugInput() {
    clearServerError('slug');
    slugManuallyEdited.value = true;
    v$.value.slug.$touch();
}

function clearServerError(field) {
    delete serverErrors[field];
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    form.parent_id = null;
    form.name_ar = '';
    form.name_en = '';
    form.slug = '';
    form.department = '';
    form.base_model = null;
    form.requires_provider = true;
    form.provider_type_label = '';
    form.status = true;
    form.sort_order = 0;
    slugManuallyEdited.value = false;
    resetValidation();
}

function fillForm(record) {
    form.parent_id = record?.parent_id ?? null;
    form.name_ar = record?.name_ar ?? '';
    form.name_en = record?.name_en ?? '';
    form.slug = record?.slug ?? '';
    form.department = record?.department ?? '';
    form.base_model = record?.base_model ?? null;
    form.requires_provider = Boolean(record?.requires_provider ?? true);
    form.provider_type_label = record?.provider_type_label ?? '';
    form.status = Boolean(record?.status ?? true);
    form.sort_order = record?.sort_order ?? 0;
    slugManuallyEdited.value = true;
    resetValidation();
}

function buildPayload() {
    return {
        parent_id: form.parent_id || null,
        name_ar: form.name_ar.trim(),
        name_en: form.name_en.trim() || null,
        slug: form.slug.trim(),
        department: form.department.trim() || null,
        base_model: form.base_model || null,
        requires_provider: form.requires_provider,
        provider_type_label: form.requires_provider ? (form.provider_type_label.trim() || null) : null,
        status: form.status,
        sort_order: Number(form.sort_order) || 0,
    };
}

async function loadOptions() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/service-categories/dropdown');
        const options = data.data ?? [];

        parentOptions.value = options.filter((option) => option.id !== props.record?.id);
        departmentOptions.value = [...new Set(options.map((o) => o.department).filter(Boolean))];
    } catch {
        // Non-fatal: the selects just show fewer/no options.
    }
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
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/service-categories/${props.record.id}`, buildPayload());
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/service-categories', buildPayload());
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

setupCatalogModalWatcher({
    props,
    fillForm,
    resetForm,
    openModal,
    closeModal,
    resourceUri: props.resourceUri,
    onOpen: loadOptions,
});

onMounted(() => {
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
</style>
