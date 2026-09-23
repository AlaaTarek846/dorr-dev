<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header employee-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ modalTitle }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close employee-modal-close"
                            aria-label="Close"
                            @click="close"
                        ></button>
                    </div>
                </div>

                <form @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <div class="row gy-3">
                            <div class="col-md-6">
                                <label for="user-name" class="form-label">
                                    {{ t('employees.name') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-user-line"></i>
                                    </span>
                                    <input
                                        id="employee-name"
                                        v-model="form.name"
                                        type="text"
                                        class="form-control"
                                        :class="nameInputClass"
                                        :placeholder="t('employees.name_placeholder')"
                                        @input="onFieldInput('name')"
                                    >
                                    <FormFieldFeedback v-bind="nameFeedback" />
                                </div>
                                <div v-if="nameMessage" class="invalid-feedback d-block">
                                    {{ nameMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="user-email" class="form-label">
                                    {{ t('email') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-mail-line"></i>
                                    </span>
                                    <input
                                        id="employee-email"
                                        v-model="form.email"
                                        type="email"
                                        class="form-control"
                                        :class="emailInputClass"
                                        :placeholder="t('employees.email_placeholder')"
                                        @input="onFieldInput('email')"
                                    >
                                    <FormFieldFeedback v-bind="emailFeedback" />
                                </div>
                                <div v-if="emailMessage" class="invalid-feedback d-block">
                                    {{ emailMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="user-gender" class="form-label">
                                    {{ t('employees.gender') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <Select
                                    id="employee-gender"
                                    v-model="form.gender"
                                    :options="genderOptions"
                                    option-label="label"
                                    option-value="value"
                                    :placeholder="t('profile.select_gender')"
                                    :invalid="genderFeedback.show && genderFeedback.invalid"
                                    append-to="self"
                                    class="w-100"
                                    @change="onFieldInput('gender')"
                                />
                                <div v-if="genderMessage" class="invalid-feedback d-block">
                                    {{ genderMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <PhoneCountryInput
                                    v-model:country-id="form.country_id"
                                    v-model:phone="form.phone"
                                    input-id="employee-phone"
                                    :label="t('employees.phone')"
                                    :placeholder="phonePlaceholder"
                                    :invalid="phoneFeedback.show && phoneFeedback.invalid"
                                    :valid="phoneFeedback.show && phoneFeedback.valid"
                                    :error="phoneMessage || serverErrors.country_id?.[0] || ''"
                                    :show="show"
                                    :load-on-show="true"
                                    @country-change="onPhoneCountryChange"
                                    @update:phone="onFieldInput('phone')"
                                    @countries-loaded="onCountriesLoaded"
                                />
                            </div>

                            <div class="col-md-6">
                                <label for="employee-status" class="form-label">{{ t('employees.status') }}</label>
                                <Select
                                    id="employee-status"
                                    v-model="form.status"
                                    :options="statusOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="Boolean(serverErrors.status?.[0])"
                                    append-to="self"
                                    class="w-100"
                                    @change="clearServerError('status')"
                                />
                                <div v-if="serverErrors.status?.[0]" class="invalid-feedback d-block">
                                    {{ serverErrors.status[0] }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="employee-role" class="form-label">
                                    {{ t('employees.role') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <Select
                                    id="employee-role"
                                    v-model="form.role_id"
                                    :options="roleOptions"
                                    option-label="label"
                                    option-value="value"
                                    :placeholder="t('employees.role_placeholder')"
                                    :invalid="roleFeedback.show && roleFeedback.invalid"
                                    :loading="rolesLoading"
                                    append-to="self"
                                    class="w-100"
                                    @change="onFieldInput('role_id')"
                                />
                                <div v-if="roleMessage" class="invalid-feedback d-block">
                                    {{ roleMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="employee-services" class="form-label">
                                    {{ t('employees.services') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <TreeSelect
                                    id="employee-services"
                                    v-model="selectedServiceKeys"
                                    :options="treeOptions"
                                    selection-mode="multiple"
                                    :invalid="servicesFeedback.show && servicesFeedback.invalid"
                                    :placeholder="t('employees.services_placeholder')"
                                    :filter="true"
                                    filter-placeholder="Search..."
                                    showClear
                                    append-to="self"
                                    display="chip"
                                    class="w-100"
                                    @change="onServicesChange"
                                />
                                <div v-if="servicesMessage" class="invalid-feedback d-block">
                                    {{ servicesMessage }}
                                </div>
                            </div>

                            <div class="col-md-6 d-flex align-items-end">
                                <button
                                    type="button"
                                    class="btn btn-primary-light w-100"
                                    @click="generatePassword"
                                >
                                    <i class="ri-refresh-line me-1"></i>{{ t('profile.generate_password') }}
                                </button>
                            </div>

                            <div class="col-md-6">
                                <label for="user-password" class="form-label">
                                    {{ t('password') }}
                                    <span v-if="passwordRequired" class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input
                                        id="employee-password"
                                        v-model="form.password"
                                        :type="showPassword ? 'text' : 'password'"
                                        class="form-control"
                                        :class="passwordInputClass"
                                        :placeholder="t('employees.password_placeholder')"
                                        autocomplete="new-password"
                                        @input="onPasswordInput('password')"
                                    >
                                    <FormFieldFeedback v-bind="passwordFeedback" />
                                    <button
                                        type="button"
                                        class="btn btn-light"
                                        @click="showPassword = !showPassword"
                                    >
                                        <i :class="showPassword ? 'ri-eye-line' : 'ri-eye-off-line'"></i>
                                    </button>
                                </div>
                                <div v-if="passwordMessage" class="invalid-feedback d-block">
                                    {{ passwordMessage }}
                                </div>
                                <div v-if="form.password" class="mt-3">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="fs-12 text-muted">{{ t('profile.password_strength') }}</span>
                                        <span :class="`fs-12 fw-semibold text-${passwordStrength.color}`">
                                            {{ t(`profile.strength.${passwordStrength.key}`) }}
                                        </span>
                                    </div>
                                    <div class="progress progress-xs">
                                        <div
                                            class="progress-bar"
                                            :class="`bg-${passwordStrength.color}`"
                                            role="progressbar"
                                            :style="{ width: `${passwordStrength.percent}%` }"
                                        ></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="user-password-confirmation" class="form-label">
                                    {{ t('employees.password_confirmation') }}
                                    <span v-if="passwordRequired" class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input
                                        id="employee-password-confirmation"
                                        v-model="form.password_confirmation"
                                        :type="showPasswordConfirmation ? 'text' : 'password'"
                                        class="form-control"
                                        :class="passwordConfirmationInputClass"
                                        :placeholder="t('employees.password_confirmation_placeholder')"
                                        autocomplete="new-password"
                                        @input="onPasswordInput('password_confirmation')"
                                    >
                                    <FormFieldFeedback v-bind="passwordConfirmationFeedback" />
                                    <button
                                        type="button"
                                        class="btn btn-light"
                                        @click="showPasswordConfirmation = !showPasswordConfirmation"
                                    >
                                        <i :class="showPasswordConfirmation ? 'ri-eye-line' : 'ri-eye-off-line'"></i>
                                    </button>
                                </div>
                                <div v-if="passwordConfirmationMessage" class="invalid-feedback d-block">
                                    {{ passwordConfirmationMessage }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer employee-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('cancel') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('saving') : t('save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { email, helpers } from '@vuelidate/validators';
import Select from 'primevue/select';
import TreeSelect from 'primevue/treeselect';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import PhoneCountryInput from '../../../../../../components/catalog/PhoneCountryInput.vue';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { combinePhoneNumber, setupCatalogModalWatcher, splitPhoneNumber } from '../../../../../../utils/catalog';
import { calculatePasswordStrength, generateSecurePassword } from '../../../../../../utils/passwordStrength';

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
const {
    requiredField,
    maxString,
    stringFieldRules,
    applyApiErrors,
    fieldFeedback,
} = useValidation();

const modalElement = ref(null);
const treeOptions = ref([]);
const roleOptions = ref([]);
const rolesLoading = ref(false);
const submitting = ref(false);
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);
const serverErrors = reactive({});
const dialCode = ref('');
const countries = ref([]);
const selectedCountry = ref(null);
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    name: '',
    email: '',
    phone: '',
    gender: '',
    country_id: null,
    status: true,
    role_id: null,
    service_category_ids: [],
    password: '',
    password_confirmation: '',
});

const passwordRequired = computed(() => (
    ! isEdit.value || Boolean(form.password) || Boolean(form.password_confirmation)
));

const passwordStrength = computed(() => calculatePasswordStrength(form.password));

const statusOptions = computed(() => ([
    { value: true, label: t('employees.filter_active') },
    { value: false, label: t('employees.filter_inactive') },
]));

const selectedServiceKeys = computed({
    get: () => {
        if (! form.service_category_ids.length) {
            return null;
        }

        return Object.fromEntries(
            form.service_category_ids.map((id) => [String(id), true]),
        );
    },
    set: (keys) => {
        form.service_category_ids = Object.keys(keys ?? {}).map(Number);
        onFieldInput('service_category_ids');
    },
});

const genderOptions = computed(() => ([
    { value: 'male', label: t('profile.gender_male') },
    { value: 'female', label: t('profile.gender_female') },
]));

const selectedCountryPhoneLength = computed(() => {
    const length = Number(selectedCountry.value?.phone_length);

    return Number.isInteger(length) && length > 0 ? length : null;
});

const phonePlaceholder = computed(() => {
    const prefix = String(selectedCountry.value?.phone_starts_with ?? '').trim();
    const length = selectedCountryPhoneLength.value;

    if (! prefix || length === null) {
        return t('employees.phone_placeholder');
    }

    const stars = '*'.repeat(Math.max(length - prefix.length, 0));

    return `${prefix}${stars}`;
});

const rules = computed(() => ({
    name: stringFieldRules('employees.name', 50, 2),
    email: {
        ...stringFieldRules('email', 50, 2),
        email: helpers.withMessage(
            () => t('validation.email', { field: t('email') }),
            email,
        ),
    },
    gender: {
        required: requiredField('employees.gender'),
    },
    role_id: {
        required: requiredField('employees.role'),
    },
    service_category_ids: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('employees.services') }),
            (value) => Array.isArray(value) && value.length > 0,
        ),
    },
    phone: {
        maxLength: maxString('employees.phone', 50),
        phoneStartsWith: helpers.withMessage(
            () => t('employees.phone_starts_with_invalid', {
                prefix: String(selectedCountry.value?.phone_starts_with ?? ''),
            }),
            (value) => {
                const prefix = String(selectedCountry.value?.phone_starts_with ?? '').trim();

                if (! prefix) {
                    return true;
                }

                const local = String(value ?? '').replace(/\D/g, '');

                return local.startsWith(prefix);
            },
        ),
        phoneLength: helpers.withMessage(
            () => t('employees.phone_length_invalid', { length: selectedCountryPhoneLength.value ?? 0 }),
            (value) => {
                const length = selectedCountryPhoneLength.value;

                if (length === null) {
                    return true;
                }

                const local = String(value ?? '').replace(/\D/g, '');

                return local.length === length;
            },
        ),
    },
    password: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('password') }),
            (value) => ! passwordRequired.value || Boolean(String(value ?? '').trim()),
        ),
        minLength: helpers.withMessage(
            () => t('profile.validation.password_min'),
            (value) => {
                const password = String(value ?? '');

                if (! password && ! passwordRequired.value) {
                    return true;
                }

                return password.length >= 8;
            },
        ),
    },
    password_confirmation: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('employees.password_confirmation') }),
            (value) => ! passwordRequired.value || Boolean(String(value ?? '').trim()),
        ),
        sameAsPassword: helpers.withMessage(
            () => t('profile.validation.password_confirmed'),
            (value) => {
                if (! passwordRequired.value) {
                    return true;
                }

                return value === form.password;
            },
        ),
    },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('employees.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('employees.edit_title');
    }

    return record.name
        ? `${t('employees.edit_title')} #${record.id} ${record.name}`
        : `${t('employees.edit_title')} #${record.id}`;
});

function buildFieldState(fieldKey) {
    const feedback = computed(() => fieldFeedback(
        v$.value[fieldKey],
        serverErrors[fieldKey]?.[0],
        form[fieldKey],
    ));

    const inputClass = computed(() => ({
        'is-invalid': feedback.value.show && feedback.value.invalid,
        'is-valid': feedback.value.show && feedback.value.valid,
    }));

    const message = computed(() => {
        if (! feedback.value.invalid) {
            return null;
        }

        return v$.value[fieldKey]?.$errors[0]?.$message || serverErrors[fieldKey]?.[0] || null;
    });

    return { feedback, inputClass, message };
}

const nameState = buildFieldState('name');
const emailState = buildFieldState('email');
const genderState = buildFieldState('gender');
const roleState = buildFieldState('role_id');
const servicesState = buildFieldState('service_category_ids');
const phoneState = buildFieldState('phone');
const passwordState = buildFieldState('password');
const passwordConfirmationState = buildFieldState('password_confirmation');

const nameFeedback = nameState.feedback;
const emailFeedback = emailState.feedback;
const genderFeedback = genderState.feedback;
const roleFeedback = roleState.feedback;
const servicesFeedback = servicesState.feedback;
const phoneFeedback = phoneState.feedback;
const passwordFeedback = passwordState.feedback;
const passwordConfirmationFeedback = passwordConfirmationState.feedback;

const nameInputClass = nameState.inputClass;
const emailInputClass = emailState.inputClass;
const passwordInputClass = passwordState.inputClass;
const passwordConfirmationInputClass = passwordConfirmationState.inputClass;

const nameMessage = nameState.message;
const emailMessage = emailState.message;
const genderMessage = genderState.message;
const roleMessage = roleState.message;
const servicesMessage = servicesState.message;
const phoneMessage = phoneState.message;
const passwordMessage = passwordState.message;
const passwordConfirmationMessage = passwordConfirmationState.message;

function clearServerError(field) {
    delete serverErrors[field];
}

function onFieldInput(field) {
    clearServerError(field);
    v$.value[field]?.$touch();
}

function onServicesChange() {
    clearServerError('service_category_ids');
    v$.value.service_category_ids.$touch();
}

function onPasswordInput(field) {
    clearServerError('password');
    clearServerError('password_confirmation');
    clearServerError(field);
    v$.value.password.$touch();
    v$.value.password_confirmation.$touch();
}

function onPhoneCountryChange(country) {
    dialCode.value = country?.dial_code ?? '';
    selectedCountry.value = country ?? null;
    clearServerError('country_id');
    onFieldInput('phone');
}

function onCountriesLoaded(list) {
    countries.value = list ?? [];

    const existing = selectedCountry.value;
    selectedCountry.value = countries.value.find(
        (country) => Number(country.id) === Number(form.country_id),
    ) ?? existing ?? null;

    if (form.country_id) {
        return;
    }

    const defaultCountry = countries.value.find((country) => Boolean(country.is_default))
        ?? countries.value[0]
        ?? null;

    if (! defaultCountry) {
        return;
    }

    form.country_id = Number(defaultCountry.id);
    dialCode.value = defaultCountry.dial_code ?? '';
    selectedCountry.value = defaultCountry;
}

function generatePassword() {
    const generated = generateSecurePassword(12);

    form.password = generated;
    form.password_confirmation = generated;
    showPassword.value = true;
    showPasswordConfirmation.value = true;
    onPasswordInput('password');
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

async function loadTreeOptions() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/service-categories/tree-options');
        treeOptions.value = data.data ?? [];
    } catch {
        treeOptions.value = [];
    }
}

async function loadRoleOptions() {
    rolesLoading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/roles', { params: { paginate: 0 } });
        const items = data.data ?? [];

        roleOptions.value = items.map((role) => ({
            value: Number(role.id),
            label: role.name,
        }));
    } catch {
        roleOptions.value = [];
    } finally {
        rolesLoading.value = false;
    }
}

async function loadModalOptions() {
    await Promise.all([
        loadTreeOptions(),
        loadRoleOptions(),
    ]);
}

function fillForm(record) {
    form.name = record?.name ?? '';
    form.email = record?.email ?? '';
    form.gender = record?.gender ?? '';
    form.country_id = record?.country_id ?? record?.country?.id ?? null;
    dialCode.value = record?.country?.dial_code ?? '';
    selectedCountry.value = record?.country ?? null;
    form.phone = splitPhoneNumber(record?.phone, dialCode.value);
    form.status = record?.status ?? true;
    form.role_id = record?.role_id != null ? Number(record.role_id) : null;
    form.service_category_ids = [...(record?.service_category_ids ?? [])];
    form.password = '';
    form.password_confirmation = '';
    showPassword.value = false;
    showPasswordConfirmation.value = false;
    resetValidation();
}

function resetForm() {
    fillForm(null);
}

function buildPayload() {
    const payload = {
        name: form.name.trim(),
        email: form.email.trim(),
        phone: combinePhoneNumber(dialCode.value, form.phone) || null,
        gender: form.gender,
        country_id: form.country_id || null,
        status: form.status,
        role_id: form.role_id,
        service_category_ids: form.service_category_ids,
    };

    if (form.password) {
        payload.password = form.password;
        payload.password_confirmation = form.password_confirmation;
    }

    return payload;
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
        const payload = buildPayload();
        const response = isEdit.value && props.record?.id
            ? await adminAxios.put(`/api/admin/v1/admins/${props.record.id}`, payload)
            : await adminAxios.post('/api/admin/v1/admins', payload);

        showSuccess(extractApiMessage(response, isEdit.value ? t('toast.updated') : t('toast.created')));
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
    resourceUri: '/api/admin/v1/admins',
    onOpen: loadModalOptions,
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
.employee-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.employee-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.employee-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
    opacity: 0.65;
    background-size: 0.65rem;
}

.employee-modal-close:hover {
    opacity: 1;
}

.employee-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}

.progress-xs {
    height: 0.35rem;
}
</style>
