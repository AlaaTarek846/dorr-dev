<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header provider-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ modalTitle }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close provider-modal-close"
                            aria-label="Close"
                            @click="close"
                        ></button>
                    </div>
                </div>

                <form @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <div class="mb-4">
                            <label class="form-label d-block">{{ t('providers.avatar') }}</label>
                            <div class="provider-avatar-row">
                                <span class="provider-avatar-box">
                                    <img :src="avatarPreview" alt="" class="provider-avatar-box__img">
                                    <label class="provider-avatar-box__badge">
                                        <input
                                            ref="avatarInput"
                                            type="file"
                                            accept="image/jpeg,image/jpg,image/png,image/webp"
                                            class="position-absolute w-100 h-100 op-0"
                                            @change="onAvatarChange"
                                        >
                                        <i class="fe fe-camera"></i>
                                    </label>
                                </span>
                                <div class="provider-avatar-actions">
                                    <button
                                        type="button"
                                        class="btn btn-primary btn-sm btn-wave provider-avatar-actions__btn"
                                        @click="avatarInput?.click()"
                                    >
                                        <i class="ri-image-edit-line"></i>
                                        <span>{{ t('providers.change_avatar') }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-wave provider-avatar-actions__btn"
                                        :class="hasCustomAvatar ? 'btn-outline-danger' : 'btn-light'"
                                        :disabled="! hasCustomAvatar"
                                        @click="removeAvatar"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                        <span>{{ t('providers.remove_avatar') }}</span>
                                    </button>
                                </div>
                            </div>
                            <div v-if="serverErrors.avatar?.[0]" class="invalid-feedback d-block">
                                {{ serverErrors.avatar[0] }}
                            </div>
                        </div>

                        <div class="row gy-3">
                            <div class="col-md-6">
                                <label for="provider-name" class="form-label">
                                    {{ t('providers.name') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-user-line"></i>
                                    </span>
                                    <input
                                        id="provider-name"
                                        v-model="form.name"
                                        type="text"
                                        class="form-control"
                                        :class="nameInputClass"
                                        :placeholder="t('providers.name_placeholder')"
                                        @input="onFieldInput('name')"
                                    >
                                    <FormFieldFeedback v-bind="nameFeedback" />
                                </div>
                                <div v-if="nameMessage" class="invalid-feedback d-block">
                                    {{ nameMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="provider-email" class="form-label">
                                    {{ t('email') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-mail-line"></i>
                                    </span>
                                    <input
                                        id="provider-email"
                                        v-model="form.email"
                                        type="email"
                                        class="form-control"
                                        :class="emailInputClass"
                                        :placeholder="t('providers.email_placeholder')"
                                        @input="onFieldInput('email')"
                                    >
                                    <FormFieldFeedback v-bind="emailFeedback" />
                                </div>
                                <div v-if="emailMessage" class="invalid-feedback d-block">
                                    {{ emailMessage }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="provider-gender" class="form-label">
                                    {{ t('providers.gender') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <Select
                                    id="provider-gender"
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
                                    input-id="provider-phone"
                                    :label="t('providers.phone')"
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
                                <label for="provider-status" class="form-label">{{ t('providers.status') }}</label>
                                <Select
                                    id="provider-status"
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
                                <label for="provider-services" class="form-label">{{ t('providers.services') }}</label>
                                <TreeSelect
                                    id="provider-services"
                                    v-model="selectedServiceKeys"
                                    :options="treeOptions"
                                    selection-mode="multiple"
                                    :invalid="Boolean(serverErrors.service_category_ids?.[0])"
                                    :placeholder="t('providers.services_placeholder')"
                                    :filter="true"
                                    filter-placeholder="Search..."
                                    showClear
                                    append-to="self"
                                    display="chip"
                                    class="w-100"
                                    @change="clearServerError('service_category_ids')"
                                />
                                <div v-if="serverErrors.service_category_ids?.[0]" class="invalid-feedback d-block">
                                    {{ serverErrors.service_category_ids[0] }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="provider-password" class="form-label">
                                    {{ t('password') }}
                                    <span v-if="passwordRequired" class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input
                                        id="provider-password"
                                        v-model="form.password"
                                        :type="showPassword ? 'text' : 'password'"
                                        class="form-control"
                                        :class="passwordInputClass"
                                        :placeholder="t('providers.password_placeholder')"
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
                                <label for="provider-password-confirmation" class="form-label">
                                    {{ t('providers.password_confirmation') }}
                                    <span v-if="passwordRequired" class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input
                                        id="provider-password-confirmation"
                                        v-model="form.password_confirmation"
                                        :type="showPasswordConfirmation ? 'text' : 'password'"
                                        class="form-control"
                                        :class="passwordConfirmationInputClass"
                                        :placeholder="t('providers.password_confirmation_placeholder')"
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

                            <div class="col-md-12 d-flex align-items-end">
                                <button
                                    type="button"
                                    class="btn btn-primary-light w-100"
                                    @click="generatePassword"
                                >
                                    <i class="ri-refresh-line me-1"></i>{{ t('profile.generate_password') }}
                                </button>
                            </div>

                        </div>
                    </div>

                    <div class="modal-footer provider-modal-footer">
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
import { combinePhoneNumber, formatDialCodeForPayload, setupCatalogModalWatcher, splitPhoneNumber } from '../../../../../../utils/catalog';
import { calculatePasswordStrength, generateSecurePassword } from '../../../../../../utils/passwordStrength';

const DEFAULT_AVATAR = '/dashboard/themes/theme-1/assets/images/faces/9.jpg';
const resourceUri = '/api/admin/v1/providers';

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
const avatarInput = ref(null);
const avatarPreview = ref(DEFAULT_AVATAR);
const avatarFile = ref(null);
const removeAvatarFlag = ref(false);
const treeOptions = ref([]);
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
    status: 'active',
    service_category_ids: [],
    password: '',
    password_confirmation: '',
});

const hasCustomAvatar = computed(() => avatarPreview.value !== DEFAULT_AVATAR);

const passwordRequired = computed(() => (
    ! isEdit.value || Boolean(form.password) || Boolean(form.password_confirmation)
));

const passwordStrength = computed(() => calculatePasswordStrength(form.password));

const statusOptions = computed(() => ([
    { value: 'active', label: t('providers.filter_active') },
    { value: 'inactive', label: t('providers.filter_inactive') },
    { value: 'blocked', label: t('providers.filter_blocked') },
]));

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
        return t('providers.phone_placeholder');
    }

    const stars = '*'.repeat(Math.max(length - prefix.length, 0));

    return `${prefix}${stars}`;
});

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
    },
});

const rules = computed(() => ({
    name: stringFieldRules('providers.name', 50, 2),
    email: {
        ...stringFieldRules('email', 50, 2),
        email: helpers.withMessage(
            () => t('validation.email', { field: t('email') }),
            email,
        ),
    },
    gender: {
        required: requiredField('providers.gender'),
    },
    phone: {
        maxLength: maxString('providers.phone', 50),
        phoneStartsWith: helpers.withMessage(
            () => t('providers.phone_starts_with_invalid', {
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
            () => t('providers.phone_length_invalid', { length: selectedCountryPhoneLength.value ?? 0 }),
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
            () => t('validation.required', { field: t('providers.password_confirmation') }),
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
        return t('providers.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('providers.edit_title');
    }

    return record.name
        ? `${t('providers.edit_title')} #${record.id} ${record.name}`
        : `${t('providers.edit_title')} #${record.id}`;
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
const phoneState = buildFieldState('phone');
const passwordState = buildFieldState('password');
const passwordConfirmationState = buildFieldState('password_confirmation');

const nameFeedback = nameState.feedback;
const emailFeedback = emailState.feedback;
const genderFeedback = genderState.feedback;
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

function onAvatarChange(event) {
    const file = event.target.files?.[0];

    if (! file) {
        return;
    }

    avatarFile.value = file;
    removeAvatarFlag.value = false;
    avatarPreview.value = URL.createObjectURL(file);
    clearServerError('avatar');
}

function removeAvatar() {
    avatarFile.value = null;
    removeAvatarFlag.value = true;
    avatarPreview.value = DEFAULT_AVATAR;

    if (avatarInput.value) {
        avatarInput.value.value = '';
    }
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

function fillForm(record) {
    form.name = record?.name ?? '';
    form.email = record?.email ?? '';
    form.gender = record?.gender ?? '';
    form.country_id = record?.country_id ?? record?.country?.id ?? null;
    dialCode.value = record?.phone_code ?? record?.country?.dial_code ?? '';
    selectedCountry.value = record?.country ?? null;
    form.phone = splitPhoneNumber(record?.phone, dialCode.value);
    form.status = record?.status ?? 'active';
    form.service_category_ids = Array.isArray(record?.service_category_ids)
        ? [...record.service_category_ids]
        : (record?.services ?? []).map((service) => service.service_category_id).filter(Boolean);
    form.password = '';
    form.password_confirmation = '';
    avatarPreview.value = record?.avatar_thumb ?? record?.avatar ?? DEFAULT_AVATAR;
    avatarFile.value = null;
    removeAvatarFlag.value = false;
    showPassword.value = false;
    showPasswordConfirmation.value = false;
    resetValidation();
}

function resetForm() {
    fillForm(null);
}

function buildFormData() {
    const formData = new FormData();

    formData.append('name', form.name.trim());
    formData.append('email', form.email.trim());
    formData.append('phone', combinePhoneNumber(dialCode.value, form.phone) || '');
    formData.append('phone_code', formatDialCodeForPayload(dialCode.value) || '');
    formData.append('gender', form.gender || '');
    formData.append('country_id', form.country_id ? String(form.country_id) : '');
    formData.append('status', form.status);

    form.service_category_ids.forEach((id, index) => {
        formData.append(`service_category_ids[${index}]`, String(id));
    });

    if (form.password) {
        formData.append('password', form.password);
        formData.append('password_confirmation', form.password_confirmation);
    }

    if (avatarFile.value) {
        formData.append('avatar', avatarFile.value);
    }

    if (removeAvatarFlag.value) {
        formData.append('remove_avatar', '1');
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
            response = await adminAxios.post(`${resourceUri}/${props.record.id}`, formData);
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post(resourceUri, formData);
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
    resourceUri,
    onOpen: loadTreeOptions,
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
.provider-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.provider-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.provider-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
    opacity: 0.65;
    background-size: 0.65rem;
}

.provider-modal-close:hover {
    opacity: 1;
}

.provider-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}

.provider-avatar-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.provider-avatar-box {
    position: relative;
    width: 96px;
    height: 96px;
    flex-shrink: 0;
}

.provider-avatar-box__img {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--default-border, #dee2e6);
}

.provider-avatar-box__badge {
    position: absolute;
    right: 0;
    bottom: 0;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--primary-color, #845adf);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    margin: 0;
}

.provider-avatar-actions {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.provider-avatar-actions__btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.progress-xs {
    height: 0.35rem;
}
</style>
