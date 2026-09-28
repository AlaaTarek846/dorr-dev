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
                        <div class="mb-3">
                            <label for="sms-account-name" class="form-label">
                                {{ t('sms.accounts.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-text"></i>
                                </span>
                                <input
                                    id="sms-account-name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="nameInputClass"
                                    :placeholder="t('sms.accounts.name_placeholder')"
                                    @input="onFieldInput('name')"
                                >
                                <FormFieldFeedback v-bind="nameFeedback" />
                            </div>
                            <div v-if="nameMessage" class="invalid-feedback d-block">
                                {{ nameMessage }}
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="sms-account-provider" class="form-label">
                                {{ t('sms.accounts.provider') }}
                                <span class="text-danger">*</span>
                            </label>
                            <Select
                                    id="sms-account-provider"
                                    v-model="form.provider_id"
                                    :options="providers"
                                    option-label="label"
                                    option-value="id"
                                    :placeholder="t('sms.accounts.provider_placeholder')"
                                    :invalid="providerFeedback.show && providerFeedback.invalid"
                                    :loading="loadingProviders"
                                    :disabled="loadingProviders"
                                    append-to="self"
                                    class="w-100"
                                    @change="onProviderChange"
                                />
                            <div v-if="providerMessage" class="invalid-feedback d-block">
                                {{ providerMessage }}
                            </div>
                        </div>

                        <div v-if="configFields.length" class="mb-3">
                            <hr class="my-3">
                            <h6 class="fw-semibold mb-2">{{ t('sms.fields.title') }}</h6>
                            <div class="row g-3">
                                <div
                                    v-for="field in configFields"
                                    :key="field.key"
                                    class="col-md-6"
                                >
                                    <label :for="`sms-account-field-${field.key}`" class="form-label">
                                        {{ field.label }}
                                        <span v-if="field.required" class="text-danger">*</span>
                                        <span v-else class="text-muted fs-11">({{ t('sms.fields.optional') }})</span>
                                    </label>

                                    <template v-if="field.options?.length">
                                        <Select
                                            :id="`sms-account-field-${field.key}`"
                                            v-model="form.configuration[field.key]"
                                            :options="field.options"
                                            :placeholder="t('sms.accounts.select_option')"
                                            append-to="self"
                                            class="w-100"
                                            @change="onConfigChange(field.key)"
                                        />
                                    </template>

                                    <template v-else-if="field.type === 'boolean'">
                                        <div
                                            class="toggle toggle-primary mb-0 catalog-modal-toggle"
                                            :class="{ on: configurationEnabled(field.key) }"
                                            role="button"
                                            tabindex="0"
                                            @click="toggleConfigurationBool(field.key)"
                                            @keydown.enter.space.prevent="toggleConfigurationBool(field.key)"
                                        >
                                            <span></span>
                                        </div>
                                    </template>

                                    <template v-else>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">
                                                <i :class="field.icon"></i>
                                            </span>
                                            <input
                                                :id="`sms-account-field-${field.key}`"
                                                v-model="form.configuration[field.key]"
                                                :type="field.type === 'password' ? 'password' : 'text'"
                                                class="form-control"
                                                autocomplete="off"
                                                @input="onConfigChange(field.key)"
                                            >
                                        </div>
                                        <div v-if="field.secret && field.is_set && !form.configuration[field.key]" class="text-success fs-11 mt-1">
                                            <i class="ri-lock-2-line me-1 align-middle"></i>
                                            {{ t('sms.fields.configured') }}
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="form.provider_id && !loadingProviders" class="alert alert-light mb-3">
                            {{ t('sms.accounts.no_schema') }}
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="sms-account-sender" class="form-label">
                                    {{ t('sms.accounts.sender') }}
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-hashtag"></i>
                                    </span>
                                    <input
                                        id="sms-account-sender"
                                        v-model="form.sender"
                                        type="text"
                                        maxlength="190"
                                        class="form-control"
                                        :placeholder="t('sms.accounts.sender_placeholder')"
                                    >
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="sms-account-sender-type" class="form-label">
                                    {{ t('sms.accounts.sender_type') }}
                                </label>
                                <Select
                                    id="sms-account-sender-type"
                                    v-model="form.sender_type"
                                    :options="senderTypeOptions"
                                    option-label="label"
                                    option-value="value"
                                    :placeholder="t('sms.accounts.sender_type_placeholder')"
                                    append-to="self"
                                    class="w-100"
                                />
                            </div>

                            <div class="col-md-6">
                                <label for="sms-account-purpose" class="form-label">
                                    {{ t('sms.accounts.purpose') }}
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-pencil-line"></i>
                                    </span>
                                    <input
                                        id="sms-account-purpose"
                                        v-model="form.purpose"
                                        type="text"
                                        maxlength="190"
                                        class="form-control"
                                        :placeholder="t('sms.accounts.purpose_placeholder')"
                                    >
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('sms.accounts.default_field') }}</label>
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

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('sms.accounts.status') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.is_active }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_active = !form.is_active"
                                    @keydown.enter.space.prevent="form.is_active = !form.is_active"
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
                            {{ submitting ? t('sms.accounts.saving') : t('save_changes') }}
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
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
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
    resourceUri: {
        type: String,
        default: '/api/admin/v1/sms-accounts',
    },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { stringFieldRules, requiredField, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const providers = ref([]);
const providerTypes = ref([]);
const loadingProviders = ref(false);
let modalInstance = null;
let v$;
let loadedProviderId = null;

const isEdit = computed(() => props.type === 'edit');

const configFields = ref([]);

const form = reactive({
    name: '',
    provider_id: '',
    configuration: {},
    sender: '',
    sender_type: '',
    purpose: '',
    is_default: false,
    is_active: true,
});

const rules = computed(() => ({
    name: stringFieldRules('sms.accounts.name', 150),
    provider_id: {
        required: requiredField('sms.accounts.provider'),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

async function loadProviders() {
    if (providers.value.length && isEdit.value) {
        return;
    }

    loadingProviders.value = true;

    try {
        const [dropdownResponse, typesResponse] = await Promise.all([
            adminAxios.get('/api/admin/v1/sms-accounts/providers-dropdown'),
            adminAxios.get('/api/admin/v1/sms-providers/types'),
        ]);

        providers.value = dropdownResponse.data?.data ?? [];
        providerTypes.value = typesResponse.data?.data ?? [];
    } catch {
        providers.value = [];
        providerTypes.value = [];
    } finally {
        loadingProviders.value = false;
    }
}

function providerById(providerId) {
    return providers.value.find((provider) => Number(provider.id) === Number(providerId)) ?? null;
}

function ensureRecordProviderOption(record) {
    if (! record?.provider_id) {
        return;
    }

    const hasOption = providers.value.some(
        (provider) => Number(provider.id) === Number(record.provider_id),
    );

    if (hasOption) {
        return;
    }

    providers.value.push({
        id: record.provider_id,
        name: record.provider?.name ?? record.provider_label ?? record.provider_key ?? '',
        key: record.provider_key,
        label: record.provider_label || record.provider_key || '',
    });
}

function schemaFieldsForProvider(providerId) {
    const provider = providerById(providerId);

    if (! provider) {
        return [];
    }

    return providerTypes.value.find((type) => type.value === provider.key)?.fields ?? [];
}

function buildFieldMeta(schemaField, isSet = false, value = null) {
    let icon = 'ri-key-line';

    if (schemaField.type === 'password') {
        icon = 'ri-shield-keyhole-line';
    } else if (schemaField.type === 'boolean') {
        icon = 'ri-toggle-line';
    } else if (schemaField.key.includes('url')) {
        icon = 'ri-link';
    } else if (schemaField.key.includes('sender')) {
        icon = 'ri-hashtag';
    }

    return {
        key: schemaField.key,
        label: schemaField.label,
        type: schemaField.type,
        required: Boolean(schemaField.required ?? false),
        secret: Boolean(schemaField.secret ?? false),
        options: schemaField.options ?? [],
        icon,
        is_set: isSet,
        value,
    };
}

function applySchema(providerId, { keepExistingValues = false } = {}) {
    const previous = { ...form.configuration };
    const fields = schemaFieldsForProvider(providerId);

    form.configuration = {};
    configFields.value = fields.map((field) => buildFieldMeta(field));

    for (const meta of configFields.value) {
        const preserved = keepExistingValues && previous[meta.key] != null
            ? previous[meta.key]
            : '';
        const defaultValue = meta.type === 'boolean' ? false : preserved;

        form.configuration[meta.key] = defaultValue;
    }
}

function onProviderChange() {
    clearServerError('provider_id');
    applySchema(form.provider_id);

    if (isEdit.value) {
        loadedProviderId = form.provider_id;
    }
}

function onConfigChange(key) {
    if (! isEdit.value) {
        return;
    }

    const meta = configFields.value.find((field) => field.key === key);

    if (meta) {
        meta.is_set = Boolean(form.configuration[key]);
    }
}

function configurationEnabled(key) {
    return Boolean(form.configuration[key]);
}

function toggleConfigurationBool(key) {
    form.configuration[key] = ! Boolean(form.configuration[key]);
}

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('sms.accounts.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('sms.accounts.edit_title');
    }

    return record.name
        ? `${t('sms.accounts.edit_title')} #${record.id} ${record.name}`
        : `${t('sms.accounts.edit_title')} #${record.id}`;
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

const nameFeedback = buildFieldFeedback('name');
const nameInputClass = buildFieldInputClass(nameFeedback);
const nameMessage = buildFieldMessage('name', nameFeedback);

const providerFeedback = buildFieldFeedback('provider_id');
const providerMessage = buildFieldMessage('provider_id', providerFeedback);

const senderTypeOptions = computed(() => [
    { value: 'number', label: t('sms.accounts.sender_type_number') },
    { value: 'alphanumeric', label: t('sms.accounts.sender_type_alphanumeric') },
]);

function onFieldInput(field) {
    clearServerError(field);
    v$.value.$touch();
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
    form.provider_id = '';
    form.configuration = {};
    form.sender = '';
    form.sender_type = '';
    form.purpose = '';
    form.is_default = false;
    form.is_active = true;
    configFields.value = [];
    loadedProviderId = null;
    resetValidation();
}

function fillForm(record) {
    form.name = record?.name ?? '';
    form.provider_id = record?.provider_id ?? '';
    form.sender = record?.sender ?? '';
    form.sender_type = record?.sender_type ?? '';
    form.purpose = record?.purpose ?? '';
    form.is_default = Boolean(record?.is_default ?? false);
    form.is_active = Boolean(record?.is_active ?? true);

    ensureRecordProviderOption(record);

    const metaFields = record?.configuration_meta?.fields ?? [];

    form.configuration = {};
    configFields.value = metaFields.map((field) => buildFieldMeta(
        field,
        Boolean(field.is_set),
        field.secret ? '' : (field.value ?? ''),
    ));

    for (const meta of configFields.value) {
        form.configuration[meta.key] = meta.type === 'boolean'
            ? Boolean(meta.value)
            : (meta.value ?? '');
    }

    loadedProviderId = form.provider_id;
    resetValidation();
}

function buildPayload() {
    const configuration = {};

    for (const meta of configFields.value) {
        configuration[meta.key] = meta.type === 'boolean'
            ? Boolean(form.configuration[meta.key])
            : (form.configuration[meta.key] ?? '');
    }

    return {
        name: form.name.trim(),
        provider_id: form.provider_id,
        configuration,
        sender: form.sender ? form.sender.trim() : null,
        sender_type: form.sender_type || null,
        purpose: form.purpose ? form.purpose.trim() : null,
        is_default: form.is_default,
        is_active: form.is_active,
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
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;
    applyApiErrors(serverErrors, {});

    try {
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/sms-accounts/${props.record.id}`, buildPayload());
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/sms-accounts', buildPayload());
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
    onOpen: loadProviders,
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