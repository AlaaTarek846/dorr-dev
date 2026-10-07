<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
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
                        <h6 class="fw-semibold mb-3">{{ t('wallet.settings.section_withdrawal') }}</h6>
                        <div class="row g-3 mb-4">
                            <div v-for="f in withdrawalFields" :key="f" class="col-md-6">
                                <label :for="`ws-${f}`" class="form-label">{{ t(`wallet.settings.${f}`) }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-money-dollar-circle-line"></i></span>
                                    <input
                                        :id="`ws-${f}`"
                                        v-model="form[f]"
                                        type="text"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="form-control"
                                        :class="classOf(f)"
                                        :placeholder="t('wallet.settings.no_limit')"
                                        @input="onInput(f)"
                                    >
                                    <FormFieldFeedback v-bind="feedbackOf(f)" />
                                </div>
                                <div v-if="messageOf(f)" class="invalid-feedback d-block">{{ messageOf(f) }}</div>
                            </div>
                        </div>

                        <h6 class="fw-semibold mb-3">{{ t('wallet.settings.section_transfers') }}</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label d-block mb-2">{{ t('wallet.settings.transfers_enabled') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.transfers_enabled }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.transfers_enabled = !form.transfers_enabled"
                                    @keydown.enter.space.prevent="form.transfers_enabled = !form.transfers_enabled"
                                >
                                    <span></span>
                                </div>
                            </div>
                            <div v-for="f in transferFields" :key="f" class="col-md-4">
                                <label :for="`ws-${f}`" class="form-label">{{ t(`wallet.settings.${f}`) }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-money-dollar-circle-line"></i></span>
                                    <input
                                        :id="`ws-${f}`"
                                        v-model="form[f]"
                                        type="text"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="form-control"
                                        :class="classOf(f)"
                                        :placeholder="t('wallet.settings.no_limit')"
                                        @input="onInput(f)"
                                    >
                                    <FormFieldFeedback v-bind="feedbackOf(f)" />
                                </div>
                                <div v-if="messageOf(f)" class="invalid-feedback d-block">{{ messageOf(f) }}</div>
                            </div>
                        </div>

                        <p class="text-muted fs-12">{{ t('wallet.settings.fee_hint') }}</p>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="ws-fee-percent" class="form-label">{{ t('wallet.settings.transfer_fee_percent') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-percent-line"></i></span>
                                    <input
                                        id="ws-fee-percent"
                                        v-model="form.transfer_fee_percent"
                                        type="text"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="form-control"
                                        :class="classOf('transfer_fee_percent')"
                                        placeholder="0"
                                        @input="onInput('transfer_fee_percent')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackOf('transfer_fee_percent')" />
                                </div>
                                <div v-if="messageOf('transfer_fee_percent')" class="invalid-feedback d-block">{{ messageOf('transfer_fee_percent') }}</div>
                            </div>
                            <div class="col-md-8">
                                <label for="ws-fee-payer" class="form-label">{{ t('wallet.settings.transfer_fee_payer') }}</label>
                                <Select
                                    filter
                                    :filter-placeholder="t('search_placeholder')"
                                    id="ws-fee-payer"
                                    v-model="form.transfer_fee_payer"
                                    :options="feePayerOptions"
                                    option-label="label"
                                    option-value="value"
                                    :invalid="invalidOf('transfer_fee_payer')"
                                    append-to="self"
                                    class="w-100"
                                    @change="onInput('transfer_fee_payer')"
                                />
                                <div v-if="messageOf('transfer_fee_payer')" class="invalid-feedback d-block">{{ messageOf('transfer_fee_payer') }}</div>
                            </div>
                        </div>

                        <h6 class="fw-semibold mb-1">{{ t('wallet.settings.section_debt') }}</h6>
                        <p class="text-muted fs-12">{{ t('wallet.settings.debt_hint') }}</p>
                        <div class="row g-3">
                            <div v-for="f in debtFields" :key="f" class="col-md-6">
                                <label :for="`ws-${f}`" class="form-label">{{ t(`wallet.settings.${f}`) }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-bank-card-line"></i></span>
                                    <input
                                        :id="`ws-${f}`"
                                        v-model="form[f]"
                                        type="text"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="form-control"
                                        :class="classOf(f)"
                                        placeholder="0.00"
                                        @input="onInput(f)"
                                    >
                                    <FormFieldFeedback v-bind="feedbackOf(f)" />
                                </div>
                                <div v-if="messageOf(f)" class="invalid-feedback d-block">{{ messageOf(f) }}</div>
                            </div>
                        </div>

                        <div v-if="generalError" class="text-danger mt-3 fs-13">{{ generalError }}</div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">
                            {{ t('close') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('wallet.settings.saving') : t('save_changes') }}
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
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import FormFieldFeedback from '../../../../../../../components/ui/FormFieldFeedback.vue';
import useFormFields from '../../../../../../../composables/useFormFields';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import useValidation from '../../../../../../../composables/useValidation';
import { majorFromMinor, parseMajor } from '../../../../../../../utils/walletMoney';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    record: {
        type: Object,
        default: null,
    },
    resourceUri: {
        type: String,
        default: '/api/admin/v1/wallet-settings',
    },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const {
    requiredField,
    moneyFormat,
    numberRules,
    applyApiErrors,
} = useValidation();

const withdrawalFields = ['min_withdrawal_minor', 'max_withdrawal_minor'];
const transferFields = ['transfer_max_per_transaction_minor', 'transfer_max_per_day_minor', 'transfer_max_per_month_minor'];
const debtFields = ['min_allowed_balance_provider_minor', 'min_allowed_balance_user_minor'];
const moneyFields = [...withdrawalFields, ...transferFields, ...debtFields];

const feePayerOptions = computed(() => [
    { value: 'recipient', label: t('wallet.settings.fee_payer_recipient') },
    { value: 'sender', label: t('wallet.settings.fee_payer_sender') },
]);

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const generalError = ref('');
const form = reactive({
    country_id: null,
    country_code: '',
    transfers_enabled: false,
    transfer_fee_percent: '0',
    transfer_fee_payer: 'recipient',
    ...Object.fromEntries(moneyFields.map((field) => [field, ''])),
});
let modalInstance = null;

const rules = computed(() => ({
    ...Object.fromEntries(moneyFields.map((field) => [
        field,
        { money: moneyFormat(`wallet.settings.${field}`) },
    ])),
    transfer_fee_percent: {
        required: requiredField('wallet.settings.transfer_fee_percent'),
        ...numberRules('wallet.settings.transfer_fee_percent', { min: 0, max: 100 }),
    },
    transfer_fee_payer: { required: requiredField('wallet.settings.transfer_fee_payer') },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });
const { feedbackOf, invalidOf, classOf, messageOf, onInput } = useFormFields({
    getV$: () => v$.value,
    form,
    serverErrors,
});

const modalTitle = computed(() => (
    form.country_code
        ? `${t('wallet.settings.edit')} · ${form.country_code}`
        : t('wallet.settings.edit')
));

function resetValidation() {
    applyApiErrors(serverErrors, {});
    generalError.value = '';
    v$.value.$reset();
}

function fillForm(row) {
    Object.assign(form, {
        country_id: row.country_id,
        country_code: row.country_code,
        transfers_enabled: Boolean(row.transfers_enabled),
        transfer_fee_percent: row.transfer_fee_percent != null ? String(row.transfer_fee_percent) : '0',
        transfer_fee_payer: row.transfer_fee_payer || 'recipient',
    });

    [...withdrawalFields, ...transferFields].forEach((f) => { form[f] = majorFromMinor(row[f]); });
    // Debt limits are stored signed (≤ 0); the form shows them as a positive "debt allowed".
    debtFields.forEach((f) => { form[f] = row[f] ? majorFromMinor(Math.abs(row[f])) : ''; });
    resetValidation();
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
    generalError.value = '';

    if (! (await v$.value.$validate())) {
        showWarning(t('toast.validation_error'));

        return;
    }

    const payload = {
        transfers_enabled: form.transfers_enabled,
        transfer_fee_percent: Number(String(form.transfer_fee_percent).replace(',', '.')),
        transfer_fee_payer: form.transfer_fee_payer,
    };

    moneyFields.forEach((f) => {
        const minor = parseMajor(form[f]);

        // Debt limits are stored signed (<= 0); the form takes them as a positive "debt allowed".
        payload[f] = debtFields.includes(f) ? -(minor ?? 0) : minor;
    });

    submitting.value = true;

    try {
        const response = await adminAxios.put(`${props.resourceUri}/${form.country_id}`, payload);

        showSuccess(extractApiMessage(response, t('wallet.settings.saved')));
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

// The settings rows are keyed by country and already carry every value, so the modal fills from the row.
watch(
    () => [props.show, props.record?.country_id],
    async ([visible]) => {
        if (! visible) {
            closeModal();

            return;
        }

        if (props.record) {
            fillForm(props.record);
        }

        await nextTick();
        openModal();
    },
);

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
