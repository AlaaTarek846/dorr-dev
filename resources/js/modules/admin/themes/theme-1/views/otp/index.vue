<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('sms.otp.title') }}
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('sms.otp.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <form @submit.prevent="save">
            <fieldset :disabled="!canUpdate">
                <div class="row g-4">
                    <div class="col-xl-6">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title">{{ t('sms.otp.channels') }}</div>
                            </div>
                            <div class="card-body">
                                <p class="fs-12 text-muted mb-4">
                                    {{ t('sms.otp.channels_hint') }}
                                </p>

                                <div class="mb-3">
                                    <label class="form-label d-block mb-2">{{ t('sms.otp.enabled') }}</label>
                                    <div
                                        class="toggle toggle-success mb-0 catalog-modal-toggle"
                                        :class="{ on: form.enabled }"
                                        role="button"
                                        tabindex="0"
                                        :aria-pressed="form.enabled"
                                        @click="form.enabled = !form.enabled"
                                        @keydown.enter.space.prevent="form.enabled = !form.enabled"
                                    >
                                        <span></span>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="otp-preferred-channel" class="form-label">
                                        {{ t('sms.otp.preferred_channel') }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <Select
                                        id="otp-preferred-channel"
                                        v-model="form.preferred_channel"
                                        :options="channelOptions"
                                        option-label="label"
                                        option-value="value"
                                        :placeholder="t('sms.otp.preferred_channel_placeholder')"
                                        :invalid="preferredChannelFeedback.show && preferredChannelFeedback.invalid"
                                        append-to="self"
                                        class="w-100"
                                    />
                                    <div v-if="preferredChannelMessage" class="invalid-feedback d-block">
                                        {{ preferredChannelMessage }}
                                    </div>
                                </div>

                                <div>
                                    <label for="otp-fallback-channel" class="form-label">
                                        {{ t('sms.otp.fallback_channel') }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <Select
                                        id="otp-fallback-channel"
                                        v-model="form.fallback_channel"
                                        :options="channelOptions"
                                        option-label="label"
                                        option-value="value"
                                        :placeholder="t('sms.otp.fallback_channel_placeholder')"
                                        :invalid="fallbackChannelFeedback.show && fallbackChannelFeedback.invalid"
                                        append-to="self"
                                        class="w-100"
                                    />
                                    <div v-if="fallbackChannelMessage" class="invalid-feedback d-block">
                                        {{ fallbackChannelMessage }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title">{{ t('sms.otp.delivery') }}</div>
                            </div>
                            <div class="card-body">
                                <p class="fs-12 text-muted mb-4">
                                    {{ t('sms.otp.delivery_hint') }}
                                </p>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="otp-length" class="form-label">
                                            {{ t('sms.otp.otp_length') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">
                                                <i class="ri-number"></i>
                                            </span>
                                            <input
                                                id="otp-length"
                                                v-model.number="form.otp_length"
                                                type="number"
                                                min="4"
                                                max="10"
                                                class="form-control"
                                                :class="otpLengthInputClass"
                                                :placeholder="t('sms.otp.otp_length_placeholder')"
                                                @blur="v$.otp_length.$touch()"
                                            >
                                        </div>
                                        <div v-if="otpLengthMessage" class="invalid-feedback d-block">
                                            {{ otpLengthMessage }}
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="otp-max-attempts" class="form-label">
                                            {{ t('sms.otp.max_attempts') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">
                                                <i class="ri-shield-check-line"></i>
                                            </span>
                                            <input
                                                id="otp-max-attempts"
                                                v-model.number="form.max_attempts"
                                                type="number"
                                                min="1"
                                                class="form-control"
                                                :class="maxAttemptsInputClass"
                                                :placeholder="t('sms.otp.max_attempts_placeholder')"
                                                @blur="v$.max_attempts.$touch()"
                                            >
                                        </div>
                                        <div v-if="maxAttemptsMessage" class="invalid-feedback d-block">
                                            {{ maxAttemptsMessage }}
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="otp-expiration" class="form-label">
                                            {{ t('sms.otp.expiration_minutes') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">
                                                <i class="ri-timer-line"></i>
                                            </span>
                                            <input
                                                id="otp-expiration"
                                                v-model.number="form.expiration_minutes"
                                                type="number"
                                                min="1"
                                                class="form-control"
                                                :class="expirationInputClass"
                                                :placeholder="t('sms.otp.expiration_minutes_placeholder')"
                                                @blur="v$.expiration_minutes.$touch()"
                                            >
                                        </div>
                                        <div v-if="expirationMessage" class="invalid-feedback d-block">
                                            {{ expirationMessage }}
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="otp-resend-cooldown" class="form-label">
                                            {{ t('sms.otp.resend_cooldown_seconds') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">
                                                <i class="ri-refresh-line"></i>
                                            </span>
                                            <input
                                                id="otp-resend-cooldown"
                                                v-model.number="form.resend_cooldown_seconds"
                                                type="number"
                                                min="10"
                                                class="form-control"
                                                :class="cooldownInputClass"
                                                :placeholder="t('sms.otp.resend_cooldown_seconds_placeholder')"
                                                @blur="v$.resend_cooldown_seconds.$touch()"
                                            >
                                        </div>
                                        <div v-if="cooldownMessage" class="invalid-feedback d-block">
                                            {{ cooldownMessage }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>

            <div v-if="canUpdate" class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-primary" :disabled="saving">
                    {{ saving ? t('saving') : t('save_changes') }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { computed, onMounted, reactive, ref } from 'vue';
import { helpers } from '@vuelidate/validators';
import { useI18n } from 'vue-i18n';
import Select from 'primevue/select';
import adminAxios from '../../../../../../api/adminAxios';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';

const { t } = useI18n();
const { canUpdate } = useCatalogPermissions('otp-settings');
const { showSuccess, showError, showWarning } = useToast();
const { requiredField, applyApiErrors, fieldFeedback, firstError } = useValidation();

const saving = ref(false);
const serverErrors = reactive({});

const channelOptions = computed(() => [
    { value: 'whatsapp', label: t('sms.otp.channel_whatsapp') },
    { value: 'sms', label: t('sms.otp.channel_sms') },
]);

const form = reactive({
    enabled: true,
    preferred_channel: 'whatsapp',
    fallback_channel: 'sms',
    otp_length: 6,
    expiration_minutes: 5,
    resend_cooldown_seconds: 30,
    max_attempts: 3,
});

/* --------------------------------------------------------------------- *
 | Validation
 * --------------------------------------------------------------------- */

/**
 * A bounded integer field. The API rejects the same ranges, so the form
 * mirrors them instead of letting the request fail on every keystroke.
 */
function numberRules(fieldKey, { min = null, max = null } = {}) {
    return {
        required: requiredField(fieldKey),
        integer: helpers.withMessage(
            () => t('validation.integer', { field: t(fieldKey) }),
            (value) => value === '' || value === null || Number.isInteger(Number(value)),
        ),
        min: helpers.withMessage(
            () => t('validation.min.numeric', { field: t(fieldKey), min }),
            (value) => min === null || Number(value) >= min,
        ),
        max: helpers.withMessage(
            () => t('validation.max.numeric', { field: t(fieldKey), max }),
            (value) => max === null || Number(value) <= max,
        ),
    };
}

const rules = computed(() => ({
    preferred_channel: {
        required: requiredField('sms.otp.preferred_channel'),
    },
    fallback_channel: {
        required: requiredField('sms.otp.fallback_channel'),
    },
    otp_length: numberRules('sms.otp.otp_length', { min: 4, max: 10 }),
    max_attempts: numberRules('sms.otp.max_attempts', { min: 1 }),
    expiration_minutes: numberRules('sms.otp.expiration_minutes', { min: 1 }),
    resend_cooldown_seconds: numberRules('sms.otp.resend_cooldown_seconds', { min: 10 }),
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

/**
 * Bind a Vuelidate field to the shared feedback + message shape used by the
 * other admin forms.
 */
function fieldUi(fieldKey, value) {
    const serverError = firstError(serverErrors, [fieldKey]);
    const feedback = computed(() => fieldFeedback(v$.value[fieldKey], serverError, value));

    return {
        feedback,
        inputClass: computed(() => ({
            'is-invalid': feedback.value.show && feedback.value.invalid,
            'is-valid': feedback.value.show && feedback.value.valid,
        })),
        message: computed(() => {
            if (! feedback.value.invalid) {
                return null;
            }

            return v$.value[fieldKey]?.$errors[0]?.$message || serverError;
        }),
    };
}

const preferredChannelState = fieldUi('preferred_channel', form.preferred_channel);
const fallbackChannelState = fieldUi('fallback_channel', form.fallback_channel);
const otpLengthState = fieldUi('otp_length', form.otp_length);
const maxAttemptsState = fieldUi('max_attempts', form.max_attempts);
const expirationState = fieldUi('expiration_minutes', form.expiration_minutes);
const cooldownState = fieldUi('resend_cooldown_seconds', form.resend_cooldown_seconds);

const preferredChannelFeedback = preferredChannelState.feedback;
const fallbackChannelFeedback = fallbackChannelState.feedback;
const preferredChannelMessage = preferredChannelState.message;
const fallbackChannelMessage = fallbackChannelState.message;
const otpLengthInputClass = otpLengthState.inputClass;
const otpLengthMessage = otpLengthState.message;
const maxAttemptsInputClass = maxAttemptsState.inputClass;
const maxAttemptsMessage = maxAttemptsState.message;
const expirationInputClass = expirationState.inputClass;
const expirationMessage = expirationState.message;
const cooldownInputClass = cooldownState.inputClass;
const cooldownMessage = cooldownState.message;

/* --------------------------------------------------------------------- *
 | Data
 * --------------------------------------------------------------------- */

function fillForm(settings) {
    form.enabled = Boolean(settings.enabled ?? true);
    form.preferred_channel = settings.preferred_channel ?? 'whatsapp';
    form.fallback_channel = settings.fallback_channel ?? 'sms';
    form.otp_length = settings.otp_length ?? 6;
    form.expiration_minutes = settings.expiration_minutes ?? 5;
    form.resend_cooldown_seconds = settings.resend_cooldown_seconds ?? 30;
    form.max_attempts = settings.max_attempts ?? 3;
}

async function load() {
    const { data } = await adminAxios.get('/api/admin/v1/otp');

    fillForm(data?.data ?? {});

    // The values were filled from the server, so any previous client-side
    // errors are stale.
    applyApiErrors(serverErrors, {});
    v$.value.$reset();
}

async function save() {
    if (! canUpdate.value) {
        return;
    }

    v$.value.$touch();

    if (v$.value.$invalid) {
        showWarning(t('toast.validation_error'));
        return;
    }

    saving.value = true;
    applyApiErrors(serverErrors, {});

    try {
        const response = await adminAxios.patch('/api/admin/v1/otp', {
            enabled: form.enabled,
            preferred_channel: form.preferred_channel,
            fallback_channel: form.fallback_channel,
            otp_length: form.otp_length,
            expiration_minutes: form.expiration_minutes,
            resend_cooldown_seconds: form.resend_cooldown_seconds,
            max_attempts: form.max_attempts,
        });

        fillForm(response.data?.data ?? {});
        applyApiErrors(serverErrors, {});
        v$.value.$reset();
        showSuccess(extractApiMessage(response, t('sms.otp.saved')));
    } catch (error) {
        if (error.response?.status === 422) {
            applyApiErrors(serverErrors, error.response.data?.errors ?? {});
            showWarning(t('toast.validation_error'));
        } else {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        await load();
    } catch {
        // Keep the defaults when the settings cannot be read.
    }
});
</script>
