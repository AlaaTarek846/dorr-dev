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
                            <label for="sms-provider-name" class="form-label">
                                {{ t('sms.providers.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-text"></i>
                                </span>
                                <input
                                    id="sms-provider-name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="nameInputClass"
                                    :placeholder="t('sms.providers.name_placeholder')"
                                    @input="onFieldInput('name')"
                                >
                                <FormFieldFeedback v-bind="nameFeedback" />
                            </div>
                            <div v-if="nameMessage" class="invalid-feedback d-block">
                                {{ nameMessage }}
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="sms-provider-key" class="form-label">
                                {{ t('sms.providers.key') }}
                                <span class="text-danger">*</span>
                            </label>
                            <Select
                                id="sms-provider-key"
                                v-model="form.key"
                                :options="types"
                                option-label="label"
                                option-value="value"
                                filter
                                filter-fields="['label']"
                                :placeholder="t('sms.providers.key_placeholder')"
                                :invalid="keyFeedback.show && keyFeedback.invalid"
                                :loading="loadingTypes"
                                :disabled="loadingTypes"
                                append-to="self"
                                class="w-100"
                                @change="onKeyChange"
                            />
                            <div v-if="keyMessage" class="invalid-feedback d-block">
                                {{ keyMessage }}
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="sms-provider-priority" class="form-label">
                                    {{ t('sms.providers.priority') }}
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-sort-asc"></i>
                                    </span>
                                    <input
                                        id="sms-provider-priority"
                                        v-model.number="form.priority"
                                        type="number"
                                        min="1"
                                        class="form-control"
                                        :placeholder="t('sms.providers.priority_placeholder')"
                                    >
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="sms-provider-countries" class="form-label">
                                    {{ t('sms.providers.countries') }}
                                </label>
                                <MultiSelect
                                    id="sms-provider-countries"
                                    v-model="form.countries"
                                    :options="countries"
                                    option-label="name"
                                    option-value="id"
                                    filter
                                    :filter-fields="['name', 'code', 'dial_code']"
                                    display="chip"
                                    :placeholder="t('sms.providers.countries_placeholder')"
                                    :loading="loadingCountries"
                                    :disabled="loadingCountries"
                                    append-to="self"
                                    class="w-100 countries-select"
                                >
                                    <template #option="{ option }">
                                        <div class="d-flex align-items-center gap-2">
                                            <FlagImage
                                                :code="resolveCountryFlagCode(option)"
                                                :size="40"
                                                :width="24"
                                                :height="18"
                                            />
                                            <span class="flex-1 text-truncate">{{ option.name || option.code }}</span>
                                            <span class="text-muted fs-12">{{ option.dial_code }}</span>
                                        </div>
                                    </template>
                                </MultiSelect>
                            </div>
                        </div>

                        <div v-if="configFields.length" class="mb-3">
                            <hr class="my-3">
                            <div class="row g-3">
                                <div
                                    v-for="field in configFields"
                                    :key="field.key"
                                    class="col-md-6"
                                >
                                    <label :for="`sms-provider-field-${field.key}`" class="form-label">
                                        {{ field.label }}
                                        <span v-if="field.required" class="text-danger">*</span>
                                        <span v-else class="text-muted fs-11">({{ t('sms.fields.optional') }})</span>
                                    </label>

                                    <template v-if="field.options?.length">
                                        <Select
                                            :id="`sms-provider-field-${field.key}`"
                                            v-model="form.configuration[field.key]"
                                            :options="field.options"
                                            :placeholder="t('sms.providers.select_option')"
                                            :invalid="configFieldInvalid(field.key)"
                                            append-to="self"
                                            class="w-100"
                                            @change="onConfigChange(field.key)"
                                        />
                                        <div v-if="configFieldInvalid(field.key)" class="invalid-feedback d-block">
                                            {{ configFieldMessage(field.key) }}
                                        </div>
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
                                                :id="`sms-provider-field-${field.key}`"
                                                v-model="form.configuration[field.key]"
                                                :type="(field.secret || field.type === 'password') ? 'password' : 'text'"
                                                class="form-control"
                                                :class="configInputClass(field.key)"
                                                autocomplete="off"
                                                @input="onConfigChange(field.key)"
                                            >
                                        </div>
                                        <div v-if="configFieldInvalid(field.key)" class="invalid-feedback d-block">
                                            {{ configFieldMessage(field.key) }}
                                        </div>
                                        <div v-if="field.secret && field.is_set && !form.configuration[field.key]" class="text-success fs-11 mt-1">
                                            <i class="ri-lock-2-line me-1 align-middle"></i>
                                            {{ t('sms.fields.configured') }}
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('sms.providers.status') }}</label>
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

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('sms.providers.available') }}</label>
                                <div
                                    class="toggle toggle-primary mb-0 catalog-modal-toggle"
                                    :class="{ on: form.is_available }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_available = !form.is_available"
                                    @keydown.enter.space.prevent="form.is_available = !form.is_available"
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
                        <button
                            type="button"
                            class="btn btn-outline-primary"
                            :disabled="testing || submitting || !selectedType"
                            @click="runTest"
                        >
                            <span v-if="testing" class="spinner-border spinner-border-sm me-1"></span>
                            {{ testing ? t('sms.providers.testing') : t('sms.providers.test') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting || testing">
                            {{ submitting ? t('sms.providers.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import MultiSelect from 'primevue/multiselect';
import Select from 'primevue/select';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import FlagImage from '../../../../../../components/ui/FlagImage.vue';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { resolveCountryFlagCode, setupCatalogModalWatcher } from '../../../../../../utils/catalog';

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
        default: '/api/admin/v1/sms-providers',
    },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { stringFieldRules, requiredField, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const testing = ref(false);
const serverErrors = reactive({});
const types = ref([]);
const loadingTypes = ref(false);
const countries = ref([]);
const loadingCountries = ref(false);
const configFields = ref([]);
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    name: '',
    key: '',
    priority: 1,
    countries: [],
    configuration: {},
    is_active: true,
    is_available: true,
});

const rules = computed(() => {
    const configurationRules = {};

    for (const meta of configFields.value) {
        const required = meta.required && ! (isEdit.value && meta.secret && meta.is_set);

        configurationRules[meta.key] = required
            ? { required: requiredField(meta.label) }
            : {};
    }

    return {
        name: stringFieldRules('sms.providers.name', 150),
        key: {
            required: requiredField('sms.providers.key'),
        },
        configuration: configurationRules,
    };
});

v$ = useVuelidate(rules, form, { $autoDirty: true });

async function loadTypes() {
    if (types.value.length) {
        return;
    }

    loadingTypes.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/sms-providers/types');
        types.value = data?.data ?? [];
    } catch {
        types.value = [];
    } finally {
        loadingTypes.value = false;
    }
}

async function loadCountries() {
    if (countries.value.length) {
        return;
    }

    loadingCountries.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/countries/dropdown');
        countries.value = data?.data ?? [];
    } catch {
        countries.value = [];
    } finally {
        loadingCountries.value = false;
    }
}

const selectedType = computed(() => types.value.find((type) => type.value === form.key) ?? null);

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

function applySchema(key) {
    const fields = selectedType.value?.fields ?? [];

    clearConfigurationObject();
    configFields.value = fields.map((field) => buildFieldMeta(field));

    for (const meta of configFields.value) {
        form.configuration[meta.key] = meta.type === 'boolean' ? false : '';
    }
}

function clearConfigurationObject() {
    // Keep the same reactive object reference so Vuelidate's bound child
    // validators keep reading the live state (replacing it breaks them).
    for (const key of Object.keys(form.configuration)) {
        delete form.configuration[key];
    }
}

function onKeyChange() {
    clearServerError('key');
    applySchema(form.key);
    resetConfigValidation();
}

function onConfigChange(key) {
    v$.value.configuration?.[key]?.$touch();

    if (! isEdit.value) {
        return;
    }

    const meta = configFields.value.find((field) => field.key === key);

    if (meta) {
        meta.is_set = Boolean(form.configuration[key]);
    }
}

function resetConfigValidation() {
    // Re-bound the nested group after a provider switch / form fill so no stale
    // touched/error state lingers on the new credential fields.
    if (v$?.value?.configuration) {
        v$.value.configuration.$reset();
        v$.value.configuration.$clearExternalResults?.();
    }
}

function configFieldInvalid(key) {
    return Boolean(v$.value.configuration?.[key]?.$error);
}

function configFieldMessage(key) {
    return v$.value.configuration?.[key]?.$errors?.[0]?.$message ?? null;
}

function configInputClass(key) {
    return { 'is-invalid': configFieldInvalid(key) };
}

function configurationEnabled(key) {
    return Boolean(form.configuration[key]);
}

function toggleConfigurationBool(key) {
    form.configuration[key] = ! Boolean(form.configuration[key]);
}

async function runTest() {
    if (! form.key) {
        showWarning(t('sms.providers.key_required'));
        return;
    }

    v$.value.configuration?.$touch();

    if (v$.value.configuration?.$invalid) {
        showWarning(t('toast.validation_error'));
        return;
    }

    testing.value = true;
    applyApiErrors(serverErrors, {});

    try {
        const configuration = buildConfiguration();

        const response = await adminAxios.post('/api/admin/v1/sms-providers/test-draft', {
            key: form.key,
            configuration,
            provider_id: isEdit.value && props.record?.id ? props.record.id : null,
        });

        showSuccess(extractApiMessage(response, t('sms.providers.test_success')));
    } catch (error) {
        if (error.response?.status === 422) {
            applyApiErrors(serverErrors, error.response.data.errors ?? {});
        }

        showError(extractApiErrorMessage(error, t('sms.providers.test_failed')));
    } finally {
        testing.value = false;
    }
}

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('sms.providers.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('sms.providers.edit_title');
    }

    return record.name
        ? `${t('sms.providers.edit_title')} #${record.id} ${record.name}`
        : `${t('sms.providers.edit_title')} #${record.id}`;
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

const keyFeedback = buildFieldFeedback('key');
const keyMessage = buildFieldMessage('key', keyFeedback);

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
    form.key = '';
    form.priority = 1;
    form.countries = [];
    clearConfigurationObject();
    form.is_active = true;
    form.is_available = true;
    configFields.value = [];
    resetValidation();
}

function fillForm(record) {
    form.name = record?.name ?? '';
    form.key = record?.key ?? '';
    form.priority = record?.priority ?? 1;
    form.countries = Array.isArray(record?.countries) ? record.countries.map(Number) : [];
    form.is_active = Boolean(record?.is_active ?? true);
    form.is_available = Boolean(record?.is_available ?? true);

    const metaFields = record?.configuration_meta?.fields ?? [];

    clearConfigurationObject();
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

    resetValidation();
    resetConfigValidation();
}

function buildConfiguration() {
    const configuration = {};

    for (const meta of configFields.value) {
        configuration[meta.key] = meta.type === 'boolean'
            ? Boolean(form.configuration[meta.key])
            : (form.configuration[meta.key] ?? '');
    }

    return configuration;
}

function buildPayload() {
    return {
        name: form.name.trim(),
        key: form.key,
        priority: form.priority,
        countries: form.countries,
        configuration: buildConfiguration(),
        is_active: form.is_active,
        is_available: form.is_available,
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
            response = await adminAxios.put(`/api/admin/v1/sms-providers/${props.record.id}`, buildPayload());
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/sms-providers', buildPayload());
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
    onOpen: async () => {
        await Promise.all([loadTypes(), loadCountries()]);
    },
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
.countries-select :deep(.p-select-label) {
    display: flex;
    align-items: center;
    overflow: visible;
}

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

.catalog-modal-toggle.toggle-primary.on {
    background-color: rgb(var(--primary-rgb, 132, 90, 223)) !important;
}
</style>
