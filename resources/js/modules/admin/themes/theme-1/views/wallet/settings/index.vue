<template>
    <div>
        <WalletPageHeader :title="t('wallet.settings.title')" :total="rows.length || null" />

        <div class="alert alert-info fs-13">{{ t('wallet.settings.intro') }}</div>

        <div class="card custom-card">
            <div class="card-header py-3">
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('wallet.settings.search')">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('wallet.wallets.country') }}</th>
                                <th>{{ t('wallet.settings.withdrawal_range') }}</th>
                                <th>{{ t('wallet.settings.transfers') }}</th>
                                <th>{{ t('wallet.settings.provider_debt') }}</th>
                                <th>{{ t('wallet.settings.user_debt') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!visibleRows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in visibleRows" :key="row.country_id">
                                    <td class="ps-4 fw-semibold">{{ row.country_code }}</td>
                                    <td>{{ range(row.min_withdrawal_minor, row.max_withdrawal_minor) }}</td>
                                    <td>
                                        <span class="badge" :class="row.transfers_enabled ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                            {{ row.transfers_enabled ? t('wallet.settings.enabled') : t('wallet.settings.disabled') }}
                                        </span>
                                        <span v-if="Number(row.transfer_fee_percent) > 0" class="fs-11 text-muted d-block mt-1">
                                            {{ t('wallet.settings.fee_summary', { percent: row.transfer_fee_percent, payer: t(`wallet.settings.fee_payer_${row.transfer_fee_payer}`) }) }}
                                        </span>
                                    </td>
                                    <td>{{ debt(row.min_allowed_balance_provider_minor) }}</td>
                                    <td>{{ debt(row.min_allowed_balance_user_minor) }}</td>
                                    <td class="text-end pe-4">
                                        <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" @click="edit(row)"><i class="ri-pencil-line"></i></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="`${t('wallet.settings.edit')} · ${form.country_code}`" @close="showModal = false">
            <form id="wallet-settings-form" @submit.prevent="save">
                <h6 class="fw-semibold">{{ t('wallet.settings.section_withdrawal') }}</h6>
                <div class="row g-3 mb-3">
                    <div v-for="f in withdrawalFields" :key="f" class="col-md-6">
                        <label class="form-label">{{ t(`wallet.settings.${f}`) }}</label>
                        <input v-model="form[f]" type="text" inputmode="decimal" dir="ltr" class="form-control" :class="{ 'is-invalid': errors[f] }" :placeholder="t('wallet.settings.no_limit')">
                        <div v-if="errors[f]" class="invalid-feedback">{{ errors[f] }}</div>
                    </div>
                </div>

                <h6 class="fw-semibold">{{ t('wallet.settings.section_transfers') }}</h6>
                <div class="form-check form-switch mb-2">
                    <input id="tr-enabled" v-model="form.transfers_enabled" class="form-check-input" type="checkbox">
                    <label class="form-check-label" for="tr-enabled">{{ t('wallet.settings.transfers_enabled') }}</label>
                </div>
                <div class="row g-3 mb-3">
                    <div v-for="f in transferFields" :key="f" class="col-md-4">
                        <label class="form-label">{{ t(`wallet.settings.${f}`) }}</label>
                        <input v-model="form[f]" type="text" inputmode="decimal" dir="ltr" class="form-control" :class="{ 'is-invalid': errors[f] }" :placeholder="t('wallet.settings.no_limit')">
                        <div v-if="errors[f]" class="invalid-feedback">{{ errors[f] }}</div>
                    </div>
                </div>

                <p class="text-muted fs-12">{{ t('wallet.settings.fee_hint') }}</p>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">{{ t('wallet.settings.transfer_fee_percent') }}</label>
                        <div class="input-group">
                            <input v-model="form.transfer_fee_percent" type="text" inputmode="decimal" dir="ltr" class="form-control" :class="{ 'is-invalid': errors.transfer_fee_percent }" placeholder="0">
                            <span class="input-group-text">%</span>
                        </div>
                        <div v-if="errors.transfer_fee_percent" class="invalid-feedback d-block">{{ errors.transfer_fee_percent }}</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">{{ t('wallet.settings.transfer_fee_payer') }}</label>
                        <select v-model="form.transfer_fee_payer" class="form-select" :class="{ 'is-invalid': errors.transfer_fee_payer }">
                            <option value="recipient">{{ t('wallet.settings.fee_payer_recipient') }}</option>
                            <option value="sender">{{ t('wallet.settings.fee_payer_sender') }}</option>
                        </select>
                        <div v-if="errors.transfer_fee_payer" class="invalid-feedback">{{ errors.transfer_fee_payer }}</div>
                    </div>
                </div>

                <h6 class="fw-semibold">{{ t('wallet.settings.section_debt') }}</h6>
                <p class="text-muted fs-12">{{ t('wallet.settings.debt_hint') }}</p>
                <div class="row g-3">
                    <div v-for="f in debtFields" :key="f" class="col-md-6">
                        <label class="form-label">{{ t(`wallet.settings.${f}`) }}</label>
                        <input v-model="form[f]" type="text" inputmode="decimal" dir="ltr" class="form-control" :class="{ 'is-invalid': errors[f] }" placeholder="0.00">
                        <div v-if="errors[f]" class="invalid-feedback">{{ errors[f] }}</div>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="wallet-settings-form" class="btn btn-primary" :disabled="saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { fmtMinor, majorFromMinor, parseMajor } from '../../../../../../../utils/walletMoney';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess } = useToast();

const canUpdate = computed(() => can('wallet-settings.update'));

const withdrawalFields = ['min_withdrawal_minor', 'max_withdrawal_minor'];
const transferFields = ['transfer_max_per_transaction_minor', 'transfer_max_per_day_minor', 'transfer_max_per_month_minor'];
const debtFields = ['min_allowed_balance_provider_minor', 'min_allowed_balance_user_minor'];

const rows = ref([]);
const search = ref('');

/** 200+ countries: filter by code so the admin isn't scrolling for one row. */
const visibleRows = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return needle ? rows.value.filter((row) => (row.country_code || '').toLowerCase().includes(needle)) : rows.value;
});
const loading = ref(false);
const showModal = ref(false);
const saving = ref(false);
const form = reactive({ country_id: null, country_code: '', transfers_enabled: false });
const errors = reactive({});

function range(min, max) {
    if (min == null && max == null) {
        return t('wallet.settings.no_limit');
    }

    return `${min == null ? '…' : fmtMinor(min)} – ${max == null ? '…' : fmtMinor(max)}`;
}

/** Stored as a negative minor amount; shown as a positive "debt allowed". */
function debt(minor) {
    return minor ? fmtMinor(Math.abs(minor)) : t('wallet.settings.no_debt');
}

async function load() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/wallet-settings');

        rows.value = data.data ?? [];
    } finally {
        loading.value = false;
    }
}

function edit(row) {
    Object.keys(errors).forEach((key) => delete errors[key]);
    Object.assign(form, {
        country_id: row.country_id,
        country_code: row.country_code,
        transfers_enabled: !!row.transfers_enabled,
        transfer_fee_percent: row.transfer_fee_percent != null ? String(row.transfer_fee_percent) : '0',
        transfer_fee_payer: row.transfer_fee_payer || 'recipient',
    });

    [...withdrawalFields, ...transferFields].forEach((f) => { form[f] = majorFromMinor(row[f]); });
    debtFields.forEach((f) => { form[f] = row[f] ? majorFromMinor(Math.abs(row[f])) : ''; });

    showModal.value = true;
}

async function save() {
    Object.keys(errors).forEach((key) => delete errors[key]);

    const feePercent = Number(String(form.transfer_fee_percent).replace(',', '.'));

    if (Number.isNaN(feePercent) || feePercent < 0 || feePercent > 100) {
        errors.transfer_fee_percent = t('wallet.common.invalid_amount');

        return;
    }

    const payload = {
        transfers_enabled: form.transfers_enabled,
        transfer_fee_percent: feePercent,
        transfer_fee_payer: form.transfer_fee_payer,
    };
    let invalid = false;

    [...withdrawalFields, ...transferFields, ...debtFields].forEach((f) => {
        const minor = parseMajor(form[f]);

        if (Number.isNaN(minor)) {
            errors[f] = t('wallet.common.invalid_amount');
            invalid = true;

            return;
        }

        // Debt limits are stored signed (≤ 0); the form takes them as a positive "debt allowed".
        payload[f] = debtFields.includes(f) ? -(minor ?? 0) : minor;
    });

    if (invalid) {
        return;
    }

    saving.value = true;

    try {
        await adminAxios.put(`/api/admin/v1/wallet-settings/${form.country_id}`, payload);

        showSuccess(t('wallet.settings.saved'));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        Object.entries(bag).forEach(([key, messages]) => { errors[key] = messages[0]; });
        errors.general = Object.keys(bag).length ? '' : (error?.response?.data?.message ?? '');
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>
