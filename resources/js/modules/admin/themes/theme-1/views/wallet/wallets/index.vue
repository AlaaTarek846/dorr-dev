<template>
    <div>
        <WalletPageHeader :title="t('wallet.wallets.title')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                <div class="input-group input-group-sm catalog-toolbar-search">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('wallet.wallets.search')">
                    <button
                        v-if="filters.search"
                        type="button"
                        class="btn btn-light border"
                        :title="t('wallet.common.clear_search')"
                        @click="filters.search = ''"
                    >
                        <i class="ri-close-line"></i>
                    </button>
                </div>
                <Select
                    v-model="filters.owner_type"
                    :options="ownerFilterOptions"
                    option-label="label"
                    option-value="value"
                    class="wallet-filter-select"
                />
                </div>
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
                                <th class="text-end pe-4">{{ t('wallet.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :columns="8" />
                            <tr v-else-if="!rows.length">
                                <td colspan="8" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                            <i class="ri-wallet-3-line fs-2 text-primary"></i>
                                        </span>
                                        <p class="fw-semibold mb-1">{{ t('wallet.wallets.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ t('wallet.common.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" class="crm-contact" @click="open(row)">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent">
                                                <i :class="row.owner_type === 'provider' ? 'ri-store-2-line' : 'ri-user-line'" class="text-primary"></i>
                                            </span>
                                            <div>
                                                <button type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click.stop="open(row)">
                                                    {{ row.owner?.name || `#${row.owner_id}` }}
                                                </button>
                                                <span class="d-block text-muted fs-11">
                                                    #{{ row.id }}
                                                    <span class="badge bg-secondary-transparent ms-1">{{ t(`wallet.owner.${row.owner_type}`) }}</span>
                                                    <span v-if="row.owner?.phone || row.owner?.email" class="ms-1" dir="ltr">{{ row.owner?.phone || row.owner?.email }}</span>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-secondary-transparent font-monospace" dir="ltr">{{ formatWalletNumber(row.wallet_number) }}</span></td>
                                    <td><span class="badge bg-primary-transparent">{{ row.country_code }}</span></td>
                                    <td class="fw-semibold">{{ fmtMinor(row.total_minor, row.currency_code) }}</td>
                                    <td>{{ fmtMinor(row.withdrawable_minor) }}</td>
                                    <td>{{ fmtMinor(row.spend_only_minor) }}</td>
                                    <td>{{ fmtMinor(row.held_withdrawable_minor + row.held_spend_only_minor) }}</td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <button type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('wallet.wallets.statement')" @click.stop="open(row)">
                                                <i class="ri-eye-line"></i>
                                            </button>
                                        </div>
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
                        <Select
                            v-model="txFilters.bucket"
                            :options="bucketFilterOptions"
                            option-label="label"
                            option-value="value"
                            append-to="self"
                            class="wallet-filter-select"
                            @change="loadTx(1)"
                        />
                        <Select
                            v-model="txFilters.direction"
                            :options="directionFilterOptions"
                            option-label="label"
                            option-value="value"
                            append-to="self"
                            class="wallet-filter-select"
                            @change="loadTx(1)"
                        />
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
                            <Select
                                v-model="adjust.direction"
                                :options="directionOptions"
                                option-label="label"
                                option-value="value"
                                append-to="self"
                                class="w-100"
                            />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('wallet.common.bucket') }} <span class="text-danger">*</span></label>
                            <Select
                                v-model="adjust.bucket"
                                :options="bucketOptions"
                                option-label="label"
                                option-value="value"
                                :placeholder="t('wallet.wallets.choose_bucket')"
                                :invalid="Boolean(adjustErrors.bucket)"
                                append-to="self"
                                class="w-100"
                                @change="adjustErrors.bucket = ''"
                            />
                            <div v-if="adjustErrors.bucket" class="invalid-feedback d-block">{{ adjustErrors.bucket }}</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('wallet.common.amount') }} ({{ wallet.currency_code }}) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="ri-money-dollar-circle-line"></i></span>
                                <input v-model="adjust.amount" type="text" inputmode="decimal" class="form-control" :class="{ 'is-invalid': adjustErrors.amount }" dir="ltr" placeholder="0.00">
                            </div>
                            <div v-if="adjustErrors.amount" class="invalid-feedback d-block">{{ adjustErrors.amount }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">{{ t('wallet.wallets.reason') }} <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="ri-edit-2-line"></i></span>
                                <input v-model="adjust.reason" type="text" maxlength="200" class="form-control" :class="{ 'is-invalid': adjustErrors.reason }">
                            </div>
                            <div v-if="adjustErrors.reason" class="invalid-feedback d-block">{{ adjustErrors.reason }}</div>
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
import Select from 'primevue/select';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
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
const adjustErrors = reactive({ amount: '', reason: '', bucket: '', general: '' });

const ownerOptions = computed(() => [
    { value: 'user', label: t('wallet.owner.user') },
    { value: 'provider', label: t('wallet.owner.provider') },
]);
const bucketOptions = computed(() => [
    { value: 'withdrawable', label: t('wallet.bucket.withdrawable') },
    { value: 'spend_only', label: t('wallet.bucket.spend_only') },
]);
const directionOptions = computed(() => [
    { value: 'credit', label: t('wallet.direction.credit') },
    { value: 'debit', label: t('wallet.direction.debit') },
]);
const ownerFilterOptions = computed(() => [{ value: '', label: t('wallet.common.all_owners') }, ...ownerOptions.value]);
const bucketFilterOptions = computed(() => [{ value: '', label: t('wallet.common.all_buckets') }, ...bucketOptions.value]);
const directionFilterOptions = computed(() => [{ value: '', label: t('wallet.common.all_directions') }, ...directionOptions.value]);
const adjusting = ref(false);

function open(row) {
    wallet.value = row;
    tab.value = 'statement';
    showModal.value = true;
    Object.assign(adjust, { direction: 'credit', bucket: '', amount: '', reason: '' });
    Object.assign(adjustErrors, { amount: '', reason: '', bucket: '', general: '' });
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
    Object.assign(adjustErrors, { amount: '', reason: '', bucket: '', general: '' });

    const minor = parseMajor(adjust.amount);

    if (minor === null || Number.isNaN(minor) || minor <= 0) {
        adjustErrors.amount = t('wallet.common.invalid_amount');

        return;
    }

    if (! adjust.bucket) {
        adjustErrors.bucket = t('wallet.common.bucket_required');

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
        adjustErrors.bucket = errors.bucket?.[0] ?? '';
        adjustErrors.general = errors.bucket?.[0] ? '' : (error?.response?.data?.message ?? '');
    } finally {
        adjusting.value = false;
    }
}
</script>

<style scoped>
.wallet-filter-select {
    min-width: 180px;
}

.wallet-filter-select :deep(.p-select-label) {
    padding-block: 0.3rem;
    font-size: 0.8125rem;
}
</style>
