<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-between my-4 page-header-breadcrumb">
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

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <h6 class="card-title fw-semibold mb-0">{{ t('sms.otp.settings') }}</h6>
                        <button
                            type="button"
                            class="btn btn-primary btn-sm btn-wave"
                            :disabled="saving"
                            @click="save"
                        >
                            {{ t('save_changes') }}
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('sms.otp.enabled') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.enabled }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.enabled = !form.enabled"
                                    @keydown.enter.space.prevent="form.enabled = !form.enabled"
                                >
                                    <span></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="otp-preferred-channel" class="form-label">{{ t('sms.otp.preferred_channel') }}</label>
                                <Select
                                    id="otp-preferred-channel"
                                    v-model="form.preferred_channel"
                                    :options="channelOptions"
                                    option-label="label"
                                    option-value="value"
                                    append-to="self"
                                    class="w-100"
                                />
                            </div>
                            <div class="col-md-6">
                                <label for="otp-fallback-channel" class="form-label">{{ t('sms.otp.fallback_channel') }}</label>
                                <Select
                                    id="otp-fallback-channel"
                                    v-model="form.fallback_channel"
                                    :options="channelOptions"
                                    option-label="label"
                                    option-value="value"
                                    append-to="self"
                                    class="w-100"
                                />
                            </div>
                            <div class="col-md-6">
                                <label for="otp-length" class="form-label">{{ t('sms.otp.otp_length') }}</label>
                                <input
                                    id="otp-length"
                                    v-model.number="form.otp_length"
                                    type="number"
                                    min="4"
                                    max="10"
                                    class="form-control"
                                >
                            </div>
                            <div class="col-md-6">
                                <label for="otp-expiration" class="form-label">{{ t('sms.otp.expiration_minutes') }}</label>
                                <input
                                    id="otp-expiration"
                                    v-model.number="form.expiration_minutes"
                                    type="number"
                                    min="1"
                                    class="form-control"
                                >
                            </div>
                            <div class="col-md-6">
                                <label for="otp-resend-cooldown" class="form-label">{{ t('sms.otp.resend_cooldown_seconds') }}</label>
                                <input
                                    id="otp-resend-cooldown"
                                    v-model.number="form.resend_cooldown_seconds"
                                    type="number"
                                    min="10"
                                    class="form-control"
                                >
                            </div>
                            <div class="col-md-6">
                                <label for="otp-max-attempts" class="form-label">{{ t('sms.otp.max_attempts') }}</label>
                                <input
                                    id="otp-max-attempts"
                                    v-model.number="form.max_attempts"
                                    type="number"
                                    min="1"
                                    class="form-control"
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from 'primevue/select';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const saving = ref(false);

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

async function load() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/otp');
        const settings = data?.data ?? {};

        form.enabled = Boolean(settings.enabled ?? true);
        form.preferred_channel = settings.preferred_channel ?? 'whatsapp';
        form.fallback_channel = settings.fallback_channel ?? 'sms';
        form.otp_length = settings.otp_length ?? 6;
        form.expiration_minutes = settings.expiration_minutes ?? 5;
        form.resend_cooldown_seconds = settings.resend_cooldown_seconds ?? 30;
        form.max_attempts = settings.max_attempts ?? 3;
    } catch {
        // keep defaults
    }
}

async function save() {
    saving.value = true;
    try {
        await adminAxios.patch('/api/admin/v1/otp', {
            enabled: form.enabled,
            preferred_channel: form.preferred_channel,
            fallback_channel: form.fallback_channel,
            otp_length: form.otp_length,
            expiration_minutes: form.expiration_minutes,
            resend_cooldown_seconds: form.resend_cooldown_seconds,
            max_attempts: form.max_attempts,
        });
        showSuccess(t('sms.otp.saved'));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>
