<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header user-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ modalTitle }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close user-modal-close"
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
                                    {{ t('users.name') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-user-line"></i>
                                    </span>
                                    <input
                                        id="user-name"
                                        v-model="form.name"
                                        type="text"
                                        class="form-control"
                                        :class="nameInputClass"
                                        :placeholder="t('users.name_placeholder')"
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
                                        id="user-email"
                                        v-model="form.email"
                                        type="email"
                                        class="form-control"
                                        :class="emailInputClass"
                                        :placeholder="t('users.email_placeholder')"
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
                                    {{ t('users.gender') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <Select
                                    id="user-gender"
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
                                    input-id="user-phone"
                                    :label="t('users.phone')"
                                    :placeholder="t('users.phone_placeholder')"
                                    :invalid="phoneFeedback.show && phoneFeedback.invalid"
                                    :valid="phoneFeedback.show && phoneFeedback.valid"
                                    :error="phoneMessage || serverErrors.country_id?.[0] || ''"
                                    :show="show"
                                    :load-on-show="true"
                                    @country-change="onPhoneCountryChange"
                                    @update:phone="onFieldInput('phone')"
                                />
                            </div>

                            <div class="col-md-6">
                                <label for="user-status" class="form-label">{{ t('users.status') }}</label>
                                <Select
                                    id="user-status"
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
                                        id="user-password"
                                        v-model="form.password"
                                        :type="showPassword ? 'text' : 'password'"
                                        class="form-control"
                                        :class="passwordInputClass"
                                        :placeholder="t('users.password_placeholder')"
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
                                    {{ t('users.password_confirmation') }}
                                    <span v-if="passwordRequired" class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input
                                        id="user-password-confirmation"
                                        v-model="form.password_confirmation"
                                        :type="showPasswordConfirmation ? 'text' : 'password'"
                                        class="form-control"
                                        :class="passwordConfirmationInputClass"
                                        :placeholder="t('users.password_confirmation_placeholder')"
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

                    <div class="modal-footer user-modal-footer">
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
const submitting = ref(false);
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);
const serverErrors = reactive({});
const dialCode = ref('');
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
    password: '',
    password_confirmation: '',
});

const passwordRequired = computed(() => (
    ! isEdit.value || Boolean(form.password) || Boolean(form.password_confirmation)
));

const passwordStrength = computed(() => calculatePasswordStrength(form.password));

const statusOptions = computed(() => ([
    { value: 'active', label: t('users.filter_active') },
    { value: 'inactive', label: t('users.filter_inactive') },
    { value: 'blocked', label: t('users.filter_blocked') },
]));

const genderOptions = computed(() => ([
    { value: 'male', label: t('profile.gender_male') },
    { value: 'female', label: t('profile.gender_female') },
]));

const rules = computed(() => ({
    name: stringFieldRules('users.name', 50, 2),
    email: {
        ...stringFieldRules('email', 50, 2),
        email: helpers.withMessage(
            () => t('validation.email', { field: t('email') }),
            email,
        ),
    },
    gender: {
        required: requiredField('users.gender'),
    },
    phone: {
        maxLength: maxString('users.phone', 50),
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
            () => t('validation.required', { field: t('users.password_confirmation') }),
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
        return t('users.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('users.edit_title');
    }

    return record.name
        ? `${t('users.edit_title')} #${record.id} ${record.name}`
        : `${t('users.edit_title')} #${record.id}`;
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
    clearServerError('country_id');
    onFieldInput('phone');
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

function fillForm(record) {
    form.name = record?.name ?? '';
    form.email = record?.email ?? '';
    form.gender = record?.gender ?? '';
    form.country_id = record?.country_id ?? record?.country?.id ?? null;
    dialCode.value = record?.country?.dial_code ?? '';
    form.phone = splitPhoneNumber(record?.phone, dialCode.value);
    form.status = record?.status ?? 'active';
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
            ? await adminAxios.put(`/api/admin/v1/users/${props.record.id}`, payload)
            : await adminAxios.post('/api/admin/v1/users', payload);

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
    resourceUri: '/api/admin/v1/users',
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
.user-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.user-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.user-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
    opacity: 0.65;
    background-size: 0.65rem;
}

.user-modal-close:hover {
    opacity: 1;
}

.user-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}

.progress-xs {
    height: 0.35rem;
}
</style>
