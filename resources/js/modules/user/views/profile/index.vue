<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('profile.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('profile.subtitle') }}</span>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-6">
                <div class="card custom-card">
                    <div class="card-header">
                        <div class="card-title">{{ t('profile.personal_info') }}</div>
                    </div>
                    <div class="card-body">
                        <form @submit.prevent="submitProfile">
                            <h6 class="fw-semibold mb-3">{{ t('profile.photo') }}</h6>
                            <div class="mb-4 d-sm-flex align-items-center gap-3">
                                <span class="avatar avatar-xxl avatar-rounded profile-avatar">
                                    <img :src="avatarPreview" alt="" id="user-profile-img">
                                    <label class="badge rounded-pill bg-primary avatar-badge profile-avatar-badge">
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
                                <div class="btn-group">
                                    <button
                                        type="button"
                                        class="btn btn-primary"
                                        @click="avatarInput?.click()"
                                    >
                                        {{ t('profile.change_photo') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-light"
                                        :disabled="! hasCustomAvatar"
                                        @click="removeAvatar"
                                    >
                                        {{ t('profile.remove_photo') }}
                                    </button>
                                </div>
                            </div>
                            <div v-if="profileErrors.avatar?.[0]" class="text-danger fs-12 mb-3">
                                {{ profileErrors.avatar[0] }}
                            </div>

                            <h6 class="fw-semibold mb-3">{{ t('profile.details') }}</h6>

                            <div class="mb-3">
                                <label for="user-profile-name" class="form-label">
                                    {{ t('profile.name') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="user-profile-name"
                                    v-model="profileForm.name"
                                    type="text"
                                    class="form-control"
                                    :class="{ 'is-invalid': profileErrors.name?.[0] }"
                                >
                                <div v-if="profileErrors.name?.[0]" class="invalid-feedback d-block">
                                    {{ profileErrors.name[0] }}
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="user-profile-email" class="form-label">
                                    {{ t('email') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="user-profile-email"
                                    v-model="profileForm.email"
                                    type="email"
                                    class="form-control"
                                    :class="{ 'is-invalid': profileErrors.email?.[0] }"
                                >
                                <div v-if="profileErrors.email?.[0]" class="invalid-feedback d-block">
                                    {{ profileErrors.email[0] }}
                                </div>
                            </div>

                            <div class="row gy-3 mb-4">
                                <div class="col-md-6">
                                    <label for="user-profile-gender" class="form-label">
                                        {{ t('profile.gender') }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <select
                                        id="user-profile-gender"
                                        v-model="profileForm.gender"
                                        class="form-select"
                                        :class="genderInputClass"
                                        @change="clearProfileError('gender')"
                                    >
                                        <option value="">{{ t('profile.select_gender') }}</option>
                                        <option value="male">{{ t('profile.gender_male') }}</option>
                                        <option value="female">{{ t('profile.gender_female') }}</option>
                                    </select>
                                    <div v-if="genderMessage" class="invalid-feedback d-block">
                                        {{ genderMessage }}
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <PhoneCountryInput
                                        v-model:country-id="profileForm.country_id"
                                        v-model:phone="profileForm.phone"
                                        input-id="user-profile-phone"
                                        :label="t('profile.phone')"
                                        :placeholder="t('profile.phone_placeholder')"
                                        required
                                        :axios-client="userAxios"
                                        dropdown-endpoint="/api/user/v1/countries/dropdown"
                                        :invalid="profilePhoneFeedback.show && profilePhoneFeedback.invalid"
                                        :valid="profilePhoneFeedback.show && profilePhoneFeedback.valid"
                                        :error="profilePhoneMessage || profileErrors.country_id?.[0] || ''"
                                        @country-change="onPhoneCountryChange"
                                        @update:phone="clearProfileError('phone')"
                                    />
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary" :disabled="profileSubmitting">
                                    {{ profileSubmitting ? t('profile.saving') : t('save_changes') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="card custom-card">
                    <div class="card-header d-sm-flex align-items-center justify-content-between gap-2">
                        <div class="card-title mb-0">{{ t('profile.change_password') }}</div>
                        <button
                            type="button"
                            class="btn btn-sm btn-primary-light"
                            @click="generatePassword"
                        >
                            <i class="ri-refresh-line me-1"></i>{{ t('profile.generate_password') }}
                        </button>
                    </div>
                    <div class="card-body">
                        <form @submit.prevent="submitPassword">
                            <p class="fs-12 text-muted mb-4">{{ t('profile.password_hint') }}</p>

                            <div class="mb-3">
                                <label for="user-new-password" class="form-label">
                                    {{ t('profile.new_password') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input
                                        id="user-new-password"
                                        v-model="passwordForm.password"
                                        :type="showNewPassword ? 'text' : 'password'"
                                        class="form-control"
                                        :class="newPasswordInputClass"
                                        autocomplete="new-password"
                                        @input="clearPasswordError('password')"
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-light"
                                        @click="showNewPassword = !showNewPassword"
                                    >
                                        <i :class="showNewPassword ? 'ri-eye-line' : 'ri-eye-off-line'"></i>
                                    </button>
                                </div>
                                <div v-if="newPasswordMessage" class="invalid-feedback d-block">
                                    {{ newPasswordMessage }}
                                </div>
                                <div v-if="passwordForm.password" class="mt-3">
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

                            <div class="mb-4">
                                <label for="user-confirm-password" class="form-label">
                                    {{ t('profile.confirm_password') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input
                                        id="user-confirm-password"
                                        v-model="passwordForm.password_confirmation"
                                        :type="showConfirmPassword ? 'text' : 'password'"
                                        class="form-control"
                                        :class="confirmPasswordInputClass"
                                        autocomplete="new-password"
                                        @input="clearPasswordError('password_confirmation')"
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-light"
                                        @click="showConfirmPassword = !showConfirmPassword"
                                    >
                                        <i :class="showConfirmPassword ? 'ri-eye-line' : 'ri-eye-off-line'"></i>
                                    </button>
                                </div>
                                <div v-if="confirmPasswordMessage" class="invalid-feedback d-block">
                                    {{ confirmPasswordMessage }}
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary" :disabled="passwordSubmitting">
                                    {{ passwordSubmitting ? t('profile.saving') : t('profile.update_password') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, sameAs } from '@vuelidate/validators';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import userAxios from '../../../../api/userAxios';
import PhoneCountryInput from '../../../../components/catalog/PhoneCountryInput.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../composables/useToast';
import useValidation from '../../../../composables/useValidation';
import { useUserAuthStore } from '../../../../stores/userAuth';
import { combinePhoneNumber, splitPhoneNumber } from '../../../../utils/catalog';
import { calculatePasswordStrength, generateSecurePassword } from '../../../../utils/passwordStrength';

const DEFAULT_AVATAR = '/dashboard/assets/images/faces/9.jpg';

const { t } = useI18n();
const authStore = useUserAuthStore();
const { showSuccess, showError, showWarning } = useToast();
const {
    requiredField,
    maxString,
    applyApiErrors,
    fieldFeedback,
} = useValidation();

const avatarInput = ref(null);
const avatarPreview = ref(DEFAULT_AVATAR);
const avatarFile = ref(null);
const removeAvatarFlag = ref(false);
const profileSubmitting = ref(false);
const passwordSubmitting = ref(false);
const showNewPassword = ref(false);
const showConfirmPassword = ref(false);
const profileErrors = reactive({});
const passwordErrors = reactive({});

const profileDialCode = ref('');

const profileForm = reactive({
    name: '',
    email: '',
    phone: '',
    gender: '',
    country_id: null,
});

const passwordForm = reactive({
    password: '',
    password_confirmation: '',
});

const hasCustomAvatar = computed(() => avatarPreview.value !== DEFAULT_AVATAR);
const passwordStrength = computed(() => calculatePasswordStrength(passwordForm.password));

const profileRules = computed(() => ({
    name: {
        required: requiredField('profile.name'),
        maxLength: maxString('profile.name', 255),
    },
    email: {
        required: requiredField('email'),
        maxLength: maxString('email', 255),
    },
    gender: {
        required: requiredField('profile.gender'),
    },
    phone: {
        required: requiredField('profile.phone'),
        maxLength: maxString('profile.phone', 50),
    },
}));

const profileV$ = useVuelidate(profileRules, profileForm, { $autoDirty: true });

const passwordRules = computed(() => ({
    password: {
        required: requiredField('profile.new_password'),
        minLength: helpers.withMessage(
            () => t('profile.validation.password_min'),
            (value) => String(value ?? '').length >= 8,
        ),
    },
    password_confirmation: {
        required: requiredField('profile.confirm_password'),
        sameAsPassword: helpers.withMessage(
            () => t('profile.validation.password_confirmed'),
            sameAs(computed(() => passwordForm.password)),
        ),
    },
}));

const passwordV$ = useVuelidate(passwordRules, passwordForm, { $autoDirty: true });

function buildFieldState(v$, fieldKey, errors, form, formKey = fieldKey) {
    const feedback = computed(() => fieldFeedback(
        v$.value[fieldKey],
        errors[fieldKey]?.[0],
        form[formKey],
    ));

    const inputClass = computed(() => ({
        'is-invalid': feedback.value.show && feedback.value.invalid,
        'is-valid': feedback.value.show && feedback.value.valid,
    }));

    const message = computed(() => {
        if (! feedback.value.invalid) {
            return null;
        }

        return v$.value[fieldKey]?.$errors[0]?.$message || errors[fieldKey]?.[0] || null;
    });

    return { feedback, inputClass, message };
}

const genderState = buildFieldState(profileV$, 'gender', profileErrors, profileForm);
const profilePhoneState = buildFieldState(profileV$, 'phone', profileErrors, profileForm);

const genderInputClass = genderState.inputClass;
const genderMessage = genderState.message;
const profilePhoneFeedback = profilePhoneState.feedback;
const profilePhoneMessage = profilePhoneState.message;

const newPasswordState = buildFieldState(passwordV$, 'password', passwordErrors, passwordForm);
const confirmPasswordState = buildFieldState(passwordV$, 'password_confirmation', passwordErrors, passwordForm);

const newPasswordInputClass = newPasswordState.inputClass;
const confirmPasswordInputClass = confirmPasswordState.inputClass;
const newPasswordMessage = newPasswordState.message;
const confirmPasswordMessage = confirmPasswordState.message;

function clearProfileError(field) {
    delete profileErrors[field];
}

function onPhoneCountryChange(country) {
    profileDialCode.value = country?.dial_code ?? '';
    clearProfileError('country_id');
    clearProfileError('phone');
}

function clearPasswordError(field) {
    delete passwordErrors[field];
}

function generatePassword() {
    const generated = generateSecurePassword(12);

    passwordForm.password = generated;
    passwordForm.password_confirmation = generated;
    showNewPassword.value = true;
    showConfirmPassword.value = true;
    passwordV$.value.password.$touch();
    passwordV$.value.password_confirmation.$touch();
}

function fillProfile(user) {
    profileForm.name = user?.name ?? '';
    profileForm.email = user?.email ?? '';
    profileForm.gender = user?.gender ?? '';
    profileForm.country_id = user?.country_id ?? null;
    profileDialCode.value = user?.country?.dial_code ?? '';
    profileForm.phone = splitPhoneNumber(user?.phone, profileDialCode.value);
    avatarPreview.value = user?.avatar_thumb ?? user?.avatar ?? DEFAULT_AVATAR;
    avatarFile.value = null;
    removeAvatarFlag.value = false;
}

function onAvatarChange(event) {
    const file = event.target.files?.[0];

    if (! file) {
        return;
    }

    avatarFile.value = file;
    removeAvatarFlag.value = false;
    avatarPreview.value = URL.createObjectURL(file);
    delete profileErrors.avatar;
}

function removeAvatar() {
    avatarFile.value = null;
    removeAvatarFlag.value = true;
    avatarPreview.value = DEFAULT_AVATAR;

    if (avatarInput.value) {
        avatarInput.value.value = '';
    }
}

function buildProfileFormData() {
    const formData = new FormData();

    formData.append('name', profileForm.name.trim());
    formData.append('email', profileForm.email.trim());
    formData.append('phone', combinePhoneNumber(profileDialCode.value, profileForm.phone));
    formData.append('gender', profileForm.gender || '');
    formData.append('country_id', profileForm.country_id ? String(profileForm.country_id) : '');

    if (avatarFile.value) {
        formData.append('avatar', avatarFile.value);
    }

    if (removeAvatarFlag.value) {
        formData.append('remove_avatar', '1');
    }

    return formData;
}

onMounted(async () => {
    try {
        const { data } = await userAxios.get('/api/user/v1/me');
        fillProfile(data.data);
        authStore.setSession({ user: data.data });
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});

async function submitProfile() {
    profileV$.value.$touch();

    if (profileV$.value.$invalid) {
        showWarning(t('toast.validation_error'));
        return;
    }

    profileSubmitting.value = true;
    applyApiErrors(profileErrors, {});

    try {
        const response = await userAxios.post('/api/user/v1/profile', buildProfileFormData());
        const user = response.data.data ?? null;

        fillProfile(user);
        authStore.setSession({ user });
        profileV$.value.$reset();
        showSuccess(extractApiMessage(response, t('save_changes')));
    } catch (error) {
        if (error.response?.status === 422) {
            applyApiErrors(profileErrors, error.response.data.errors ?? {});
            showWarning(t('toast.validation_error'));
        } else {
            showError(extractApiErrorMessage(error));
        }
    } finally {
        profileSubmitting.value = false;
    }
}

async function submitPassword() {
    passwordV$.value.$touch();

    if (passwordV$.value.$invalid) {
        showWarning(t('toast.validation_error'));
        return;
    }

    passwordSubmitting.value = true;
    applyApiErrors(passwordErrors, {});

    try {
        const response = await userAxios.put('/api/user/v1/profile/password', {
            password: passwordForm.password,
            password_confirmation: passwordForm.password_confirmation,
        });

        passwordForm.password = '';
        passwordForm.password_confirmation = '';
        showNewPassword.value = false;
        showConfirmPassword.value = false;
        passwordV$.value.$reset();
        showSuccess(extractApiMessage(response, t('profile.password_updated')));
    } catch (error) {
        if (error.response?.status === 422) {
            applyApiErrors(passwordErrors, error.response.data.errors ?? {});
            showWarning(t('toast.validation_error'));
        } else {
            showError(extractApiErrorMessage(error));
        }
    } finally {
        passwordSubmitting.value = false;
    }
}
</script>

<style scoped>
.profile-avatar {
    position: relative;
}

.profile-avatar-badge {
    cursor: pointer;
}

.progress-xs {
    height: 0.35rem;
}
</style>
