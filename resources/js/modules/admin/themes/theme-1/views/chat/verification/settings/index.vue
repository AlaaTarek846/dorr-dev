<template>
    <div>
        <WalletPageHeader :title="t('chat.verification.settings.title')" :section="t('sidebar.chat')" />

        <div class="alert alert-info fs-13">{{ t('chat.verification.settings.intro') }}</div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

        <form v-else id="chat-verification-settings-form" @submit.prevent="save">
            <div class="row g-3">
                <div v-for="section in sections" :key="section.key" class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent"><i :class="section.icon"></i></span>
                            <div class="card-title mb-0">{{ t(`chat.verification.settings.section_${section.key}`) }}</div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted fs-12">{{ t(`chat.verification.settings.note_${section.key}`) }}</p>
                            <div class="row g-3">
                                <div v-for="f in section.fields" :key="f.key" :class="f.type === 'bool' ? 'col-12' : 'col-sm-6'">
                                    <div v-if="f.type === 'bool'" class="form-check form-switch mb-0">
                                        <input :id="`cvs-${f.key}`" v-model="form[f.key]" class="form-check-input" type="checkbox" :disabled="!canUpdate">
                                        <label class="form-check-label fs-13" :for="`cvs-${f.key}`">{{ t(`chat.verification.settings.${f.key}`) }}</label>
                                    </div>
                                    <template v-else>
                                        <label class="form-label fs-13">{{ t(`chat.verification.settings.${f.key}`) }}</label>
                                        <select v-if="f.type === 'select'" v-model="form[f.key]" class="form-select" :class="{ 'is-invalid': errors[f.key] }" :disabled="!canUpdate">
                                            <option v-for="o in f.options" :key="o" :value="o">{{ t(`chat.verification.${f.labels}.${o}`) }}</option>
                                        </select>
                                        <input v-else-if="f.type === 'days'" v-model="daysText[f.key]" type="text" dir="ltr" class="form-control" :class="{ 'is-invalid': listError(f.key) }" :disabled="!canUpdate" placeholder="7, 1" @input="form[f.key] = parseDays(daysText[f.key])">
                                        <div v-else class="input-group">
                                            <input v-model.number="form[f.key]" type="number" :min="f.min" :max="f.max" dir="ltr" class="form-control" :class="{ 'is-invalid': errors[f.key] }" :disabled="!canUpdate">
                                            <span class="input-group-text fs-12">{{ t(`chat.verification.units.${f.unit}`) }}</span>
                                        </div>
                                        <div v-if="listError(f.key)" class="text-danger fs-12 mt-1">{{ listError(f.key) }}</div>
                                        <div v-else-if="f.hint" class="text-muted fs-11 mt-1">{{ t(`chat.verification.settings.hint_${f.key}`) }}</div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="errors.general" class="text-danger mt-3 fs-13">{{ errors.general }}</div>

            <div v-if="canUpdate" class="d-flex justify-content-end gap-2 mt-3 mb-4">
                <button type="button" class="btn btn-light" :disabled="saving || !dirty" @click="reset">{{ t('cancel') }}</button>
                <button type="submit" class="btn btn-primary" :disabled="saving || !dirty">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save_changes') }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../../composables/usePermission';

// Account verification's settings (docs/verification_tasks.md AV-003). Same bounds as VerificationSettingController::update.
const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const endpoint = '/api/admin/v1/chat-verification/settings';

const canUpdate = computed(() => can('chat-verification-settings.update'));

const timings = ['after_identity_check', 'after_approval', 'after_payment', 'after_approval_and_payment', 'after_proof_approved'];

const sections = [
    { key: 'tracks', icon: 'ri-shield-check-line', fields: [{ key: 'paid_enabled', type: 'bool' }, { key: 'celebrity_enabled', type: 'bool' }] },
    {
        key: 'deadlines',
        icon: 'ri-timer-line',
        fields: [
            { key: 'review_deadline_days', min: 1, max: 365, unit: 'days' },
            { key: 'completion_deadline_days', min: 1, max: 365, unit: 'days' },
            { key: 'deadline_warning_hours', min: 1, max: 720, unit: 'hours', hint: true },
            { key: 'escalation_after_hours', min: 0, max: 720, unit: 'hours', hint: true },
        ],
    },
    {
        key: 'activation',
        icon: 'ri-flashlight-line',
        fields: [
            { key: 'paid_activation', type: 'select', options: timings, labels: 'timings' },
            { key: 'celebrity_activation', type: 'select', options: timings, labels: 'timings' },
        ],
    },
    {
        key: 'renewal',
        icon: 'ri-refresh-line',
        fields: [
            { key: 'renewal_reminder_days', type: 'days', hint: true },
            { key: 'subscription_grace_days', min: 0, max: 60, unit: 'days', hint: true },
        ],
    },
    {
        key: 'exemptions',
        icon: 'ri-gift-line',
        fields: [
            { key: 'exemption_default_type', type: 'select', options: ['full', 'partial', 'fixed'], labels: 'exemption_types' },
            { key: 'exemption_default_months', min: 1, max: 60, unit: 'months' },
            { key: 'exemption_warning_days', min: 1, max: 365, unit: 'days' },
            { key: 'exemption_grace_days', min: 0, max: 90, unit: 'days' },
        ],
    },
    {
        key: 'ads',
        icon: 'ri-megaphone-line',
        fields: [
            { key: 'ad_default_duration_days', min: 1, max: 365, unit: 'days', hint: true },
            { key: 'proof_deadline_days', min: 1, max: 365, unit: 'days', hint: true },
            { key: 'referral_requires_phone_verification', type: 'bool' },
        ],
    },
    {
        key: 'refunds',
        icon: 'ri-refund-2-line',
        fields: [
            { key: 'refund_window_hours', min: 0, max: 720, unit: 'hours', hint: true },
            { key: 'refund_on_rejection', type: 'bool' },
            { key: 'refund_on_cancel_before_decision', type: 'bool' },
        ],
    },
    {
        key: 'appeals',
        icon: 'ri-scales-3-line',
        fields: [
            { key: 'appeal_window_days', min: 1, max: 365, unit: 'days' },
            { key: 'appeal_response_days', min: 1, max: 365, unit: 'days' },
        ],
    },
];

const loading = ref(true);
const saving = ref(false);
const form = reactive({});
const errors = reactive({});
const original = ref({});
const daysText = reactive({});

const same = (a, b) => (Array.isArray(a) || Array.isArray(b) ? JSON.stringify(a ?? []) === JSON.stringify(b ?? []) : a === b);
const dirty = computed(() => Object.keys(original.value).some((key) => !same(original.value[key], form[key])));

/** "7, 1" → [7, 1] (largest first, no repeats). */
const parseDays = (text) => [...new Set(String(text ?? '').split(/[,،\s]+/).map((d) => parseInt(d, 10)).filter((d) => d > 0))].sort((a, b) => b - a);

const listError = (key) => errors[key] ?? Object.entries(errors).find(([k]) => k.startsWith(`${key}.`))?.[1];

function fill(data) {
    original.value = { ...data, renewal_reminder_days: [...(data.renewal_reminder_days ?? [])] };
    Object.assign(form, { ...original.value, renewal_reminder_days: [...original.value.renewal_reminder_days] });
    daysText.renewal_reminder_days = original.value.renewal_reminder_days.join(', ');
}

function reset() {
    fill(original.value);
    Object.keys(errors).forEach((key) => delete errors[key]);
}

async function save() {
    Object.keys(errors).forEach((key) => delete errors[key]);
    saving.value = true;

    const body = {};
    Object.keys(original.value).forEach((key) => {
        if (!same(original.value[key], form[key])) {
            body[key] = form[key];
        }
    });

    try {
        const { data } = await adminAxios.put(endpoint, body);

        fill(data.data);
        showSuccess(t('chat.verification.settings.saved'));
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        Object.entries(bag).forEach(([key, messages]) => { errors[key] = messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await adminAxios.get(endpoint);

        fill(data.data ?? {});
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
});
</script>
