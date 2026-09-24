<template>
    <div>
        <WalletPageHeader :title="t('wallet.wallets.title')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 300px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('wallet.wallets.search')">
                </div>
                <select v-model="filters.owner_type" class="form-select form-select-sm w-auto">
                    <option value="">{{ t('wallet.common.all_owners') }}</option>
                    <option value="user">{{ t('wallet.owner.user') }}</option>
                    <option value="provider">{{ t('wallet.owner.provider') }}</option>
                </select>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('wallet.common.owner') }}</th>
                                <th>{{ t('wallet.wallets.number') }}</th>
                                <th>{{ t('wallet.wallets.country') }}</th>
                                <th>{{ t('wallet.wallets.total') }}</th>
                                <th>{{ t('wallet.bucket.withdrawable') }}</th>
                                <th>{{ t('wallet.bucket.spend_only') }}</th>
                                <th>{{ t('wallet.wallets.held') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="8" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="8" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" role="button" @click="open(row)">
                                    <td class="ps-4">
                                        <div class="fw-semibold">{{ row.owner?.name || `#${row.owner_id}` }}</div>
                                        <div class="fs-12 text-muted">
                                            <span class="badge bg-light text-default">{{ t(`wallet.owner.${row.owner_type}`) }}</span>
                                            <span dir="ltr">{{ row.owner?.phone || row.owner?.email || '' }}</span>
                                        </div>
                                    </td>
                                    <td class="font-monospace" dir="ltr">{{ formatWalletNumber(row.wallet_number) }}</td>
                                    <td>{{ row.country_code }}</td>
                                    <td class="fw-semibold">{{ fmtMinor(row.total_minor, row.currency_code) }}</td>
                                    <td>{{ fmtMinor(row.withdrawable_minor) }}</td>
                                    <td>{{ fmtMinor(row.spend_only_minor) }}</td>
                                    <td>{{ fmtMinor(row.held_withdrawable_minor + row.held_spend_only_minor) }}</td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-info-light btn-icon" @click.stop="open(row)">
                                            <i class="ri-eye-line"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <WalletModal :show="showModal" :title="wallet ? `${wallet.owner?.name || '#' + wallet.owner_id} · ${wallet.country_code}` : ''" size="xl" @close="showModal = false">
            <div v-if="wallet">
                <div class="row g-3 mb-3">
                    <div class="col-md-3"><div class="text-muted fs-12">{{ t('wallet.wallets.total') }}</div><div class="fw-semibold fs-5">{{ fmtMinor(wallet.total_minor, wallet.currency_code) }}</div></div>
                    <div class="col-md-3"><div class="text-muted fs-12">{{ t('wallet.bucket.withdrawable') }}</div><div class="fw-semibold">{{ fmtMinor(wallet.withdrawable_minor) }}</div></div>
                    <div class="col-md-3"><div class="text-muted fs-12">{{ t('wallet.bucket.spend_only') }}</div><div class="fw-semibold">{{ fmtMinor(wallet.spend_only_minor) }}</div></div>
                    <div class="col-md-3"><div class="text-muted fs-12">{{ t('wallet.wallets.held') }}</div><div class="fw-semibold">{{ fmtMinor(wallet.held_withdrawable_minor + wallet.held_spend_only_minor) }}</div></div>
                </div>

                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'statement' }" @click="tab = 'statement'">{{ t('wallet.wallets.statement') }}</button></li>
                    <li v-if="canAdjust" class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'adjust' }" @click="tab = 'adjust'">{{ t('wallet.wallets.adjust') }}</button></li>
                </ul>

                <div v-if="tab === 'statement'">
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <select v-model="txFilters.bucket" class="form-select form-select-sm w-auto" @change="loadTx(1)">
                            <option value="">{{ t('wallet.common.all_buckets') }}</option>
                            <option value="withdrawable">{{ t('wallet.bucket.withdrawable') }}</option>
                            <option value="spend_only">{{ t('wallet.bucket.spend_only') }}</option>
                        </select>
                        <select v-model="txFilters.direction" class="form-select form-select-sm w-auto" @change="loadTx(1)">
                            <option value="">{{ t('wallet.common.all_directions') }}</option>
                            <option value="credit">{{ t('wallet.direction.credit') }}</option>
                            <option value="debit">{{ t('wallet.direction.debit') }}</option>
                        </select>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered text-nowrap mb-0">
                            <thead>
                                <tr>
                                    <th>{{ t('wallet.common.date') }}</th>
                                    <th>{{ t('wallet.common.type') }}</th>
                                    <th>{{ t('wallet.common.bucket') }}</th>
                                    <th>{{ t('wallet.common.amount') }}</th>
                                    <th>{{ t('wallet.wallets.balance_after') }}</th>
                                    <th>{{ t('wallet.common.note') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="txLoading"><td colspan="6" class="text-center"><span class="spinner-border spinner-border-sm"></span></td></tr>
                                <tr v-else-if="!txRows.length"><td colspan="6" class="text-center text-muted">{{ t('wallet.common.empty') }}</td></tr>
                                <template v-else>
                                    <tr v-for="tx in txRows" :key="tx.uuid">
                                        <td>{{ formatDateTime(tx.created_at, locale) }}</td>
                                        <td>{{ tx.type_label }}</td>
                                        <td><span class="badge" :class="tx.bucket === 'spend_only' ? 'bg-pink-transparent' : 'bg-primary-transparent'">{{ t(`wallet.bucket.${tx.bucket}`) }}</span></td>
                                        <td :class="tx.direction === 'credit' ? 'text-success' : 'text-danger'" class="fw-semibold" dir="ltr">
                                            {{ tx.direction === 'credit' ? '+' : '−' }} {{ fmtMinor(tx.amount_minor) }}
                                        </td>
                                        <td dir="ltr">{{ fmtMinor(tx.balance_after_minor) }}</td>
                                        <td class="text-wrap">{{ tx.counterparty ? `${tx.counterparty.name || ''} ${tx.counterparty.phone || ''}` : (tx.note !== tx.type_label ? tx.note : '') }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="txPagination && txPagination.last_page > 1" class="d-flex justify-content-between align-items-center mt-2">
                        <button type="button" class="btn btn-sm btn-light" :disabled="txPagination.current_page <= 1" @click="loadTx(txPagination.current_page - 1)">{{ t('currencies.previous') }}</button>
                        <span class="text-muted fs-12">{{ txPagination.current_page }} / {{ txPagination.last_page }}</span>
                        <button type="button" class="btn btn-sm btn-light" :disabled="txPagination.current_page >= txPagination.last_page" @click="loadTx(txPagination.current_page + 1)">{{ t('currencies.next') }}</button>
                    </div>
                </div>

                <form v-else @submit.prevent="submitAdjustment">
                    <div class="alert alert-warning fs-13">{{ t('wallet.wallets.adjust_hint') }}</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">{{ t('wallet.common.direction') }}</label>
                            <select v-model="adjust.direction" class="form-select">
                                <option value="credit">{{ t('wallet.direction.credit') }}</option>
                                <option value="debit">{{ t('wallet.direction.debit') }}</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('wallet.common.bucket') }} <span class="text-danger">*</span></label>
                            <select v-model="adjust.bucket" class="form-select" required>
                                <option value="" disabled>{{ t('wallet.wallets.choose_bucket') }}</option>
                                <option value="withdrawable">{{ t('wallet.bucket.withdrawable') }}</option>
                                <option value="spend_only">{{ t('wallet.bucket.spend_only') }}</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('wallet.common.amount') }} ({{ wallet.currency_code }}) <span class="text-danger">*</span></label>
                            <input v-model="adjust.amount" type="text" inputmode="decimal" class="form-control" :class="{ 'is-invalid': adjustErrors.amount }" dir="ltr" placeholder="0.00">
                            <div v-if="adjustErrors.amount" class="invalid-feedback">{{ adjustErrors.amount }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">{{ t('wallet.wallets.reason') }} <span class="text-danger">*</span></label>
                            <input v-model="adjust.reason" type="text" maxlength="200" class="form-control" :class="{ 'is-invalid': adjustErrors.reason }">
                            <div v-if="adjustErrors.reason" class="invalid-feedback">{{ adjustErrors.reason }}</div>
                        </div>
                    </div>
                    <div v-if="adjustErrors.general" class="text-danger mt-2 fs-13">{{ adjustErrors.general }}</div>
                    <button type="submit" class="btn btn-primary mt-3" :disabled="adjusting">
                        <span v-if="adjusting" class="spinner-border spinner-border-sm me-1"></span>{{ t('wallet.wallets.apply_adjustment') }}
                    </button>
                </form>
            </div>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import { fmtMinor, formatDateTime, parseMajor } from '../../../../../../../utils/walletMoney';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess } = useToast();

const { rows, loading, pagination, filters, fetch } = useWalletList('wallets', {
    defaults: { search: '', owner_type: '' },
});
fetch(1);

// 11 digits shown as "123 4567 8901" (same grouping as the app).
const formatWalletNumber = (n) => (n ? String(n).replace(/^(\d{3})(\d{4})(\d+)$/, '$1 $2 $3') : '—');

const canAdjust = computed(() => can('wallets.manual-adjustment'));

const showModal = ref(false);
const wallet = ref(null);
const tab = ref('statement');

const txRows = ref([]);
const txLoading = ref(false);
const txPagination = ref(null);
const txFilters = reactive({ bucket: '', direction: '' });

const adjust = reactive({ direction: 'credit', bucket: '', amount: '', reason: '' });
const adjustErrors = reactive({ amount: '', reason: '', general: '' });
const adjusting = ref(false);

function open(row) {
    wallet.value = row;
    tab.value = 'statement';
    showModal.value = true;
    Object.assign(adjust, { direction: 'credit', bucket: '', amount: '', reason: '' });
    Object.assign(adjustErrors, { amount: '', reason: '', general: '' });
    loadTx(1);
}

async function loadTx(page) {
    txLoading.value = true;

    try {
        const params = { per_page: 10, page, ...Object.fromEntries(Object.entries(txFilters).filter(([, v]) => v !== '')) };
        const { data } = await adminAxios.get(`/api/admin/v1/wallets/${wallet.value.id}/transactions`, { params });

        txRows.value = data.data ?? [];
        txPagination.value = data.pagination;
    } finally {
        txLoading.value = false;
    }
}

async function submitAdjustment() {
    Object.assign(adjustErrors, { amount: '', reason: '', general: '' });

    const minor = parseMajor(adjust.amount);

    if (minor === null || Number.isNaN(minor) || minor <= 0) {
        adjustErrors.amount = t('wallet.common.invalid_amount');

        return;
    }

    if (adjust.reason.trim().length < 3) {
        adjustErrors.reason = t('wallet.wallets.reason_required');

        return;
    }

    adjusting.value = true;

    try {
        await adminAxios.post(`/api/admin/v1/wallets/${wallet.value.id}/adjustments`, {
            direction: adjust.direction,
            bucket: adjust.bucket,
            amount_minor: minor,
            reason: adjust.reason.trim(),
        });

        showSuccess(t('wallet.wallets.adjusted'));

        const { data } = await adminAxios.get(`/api/admin/v1/wallets/${wallet.value.id}`);

        wallet.value = data.data;
        Object.assign(adjust, { direction: 'credit', bucket: '', amount: '', reason: '' });
        tab.value = 'statement';
        loadTx(1);
        fetch();
    } catch (error) {
        const errors = error?.response?.data?.errors ?? {};

        adjustErrors.amount = errors.amount_minor?.[0] ?? '';
        adjustErrors.reason = errors.reason?.[0] ?? '';
        adjustErrors.general = errors.bucket?.[0] ?? error?.response?.data?.message ?? '';
    } finally {
        adjusting.value = false;
    }
}
</script>
