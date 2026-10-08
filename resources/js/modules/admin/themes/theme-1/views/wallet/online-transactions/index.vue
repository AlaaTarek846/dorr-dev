<template>
    <div>
        <WalletPageHeader :title="t('wallet.online.title')" :total="pagination?.total ?? null" />

        <div v-if="summary.length" class="row">
            <div v-for="entry in summary" :key="entry.currency_code" class="col-xl-4 col-md-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="fw-semibold">{{ entry.currency_code }}</span>
                            <span class="badge bg-success-transparent">{{ t('wallet.status.paid') }}</span>
                        </div>
                        <h4 class="fw-semibold mb-1">{{ fmtMinor(entry.by_status.paid?.total_minor ?? 0, entry.currency_code) }}</h4>
                        <div class="d-flex flex-wrap gap-2 fs-12 text-muted">
                            <span>{{ t('wallet.status.paid') }}: {{ entry.by_status.paid?.count ?? 0 }}</span>
                            <span>{{ t('wallet.status.pending') }}: {{ entry.by_status.pending?.count ?? 0 }}</span>
                            <span>{{ t('wallet.status.failed') }}: {{ entry.by_status.failed?.count ?? 0 }}</span>
                            <span>{{ t('wallet.status.refunded') }}: {{ entry.by_status.refunded?.count ?? 0 }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="filters.search" type="search" class="form-control" :placeholder="t('wallet.online.search')">
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
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        v-model="filters.status"
                        :placeholder="t('wallet.common.status')"
                        show-clear
                        :options="statusFilterOptions"
                        option-label="label"
                        option-value="value"
                        class="wallet-filter-select"
                    />
                    <Select
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        v-model="filters.owner_type"
                        :placeholder="t('wallet.common.owner')"
                        show-clear
                        :options="ownerFilterOptions"
                        option-label="label"
                        option-value="value"
                        class="wallet-filter-select"
                    />
                    <AdminDatePicker v-model="filters.from" :placeholder="t('wallet.common.date_from')" />
                    <AdminDatePicker v-model="filters.to" :placeholder="t('wallet.common.date_to')" />
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-light" :title="t('wallet.common.refresh')" @click="reload">
                        <i class="ri-refresh-line"></i>
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">#</th>
                                <th>{{ t('wallet.common.date') }}</th>
                                <th>{{ t('wallet.common.owner') }}</th>
                                <th>{{ t('wallet.online.method') }}</th>
                                <th>{{ t('wallet.common.amount') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th>{{ t('wallet.online.reference') }}</th>
                                <th class="text-end pe-4">{{ t('wallet.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :columns="8" />
                            <tr v-else-if="!rows.length">
                                <td colspan="8" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                            <i class="ri-bank-card-line fs-2 text-primary"></i>
                                        </span>
                                        <p class="fw-semibold mb-1">{{ t('wallet.common.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ t('wallet.common.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" class="crm-contact" @click="openDetail(row.id)">
                                    <td class="ps-4">
                                        <button type="button" class="btn btn-link p-0 fw-semibold text-default" @click.stop="openDetail(row.id)">#{{ row.id }}</button>
                                    </td>
                                    <td>{{ formatDateTime(row.created_at, locale) }}</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent">
                                                <i :class="row.owner_type === 'provider' ? 'ri-store-2-line' : 'ri-user-line'" class="text-primary"></i>
                                            </span>
                                            <div>
                                                <span class="fw-semibold d-block">#{{ row.owner_id }}</span>
                                                <span class="d-block text-muted fs-11">{{ t(`wallet.owner.${row.owner_type}`) }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-secondary-transparent">{{ row.payment_method?.name || row.payment_method?.code }}</span></td>
                                    <td class="fw-semibold">{{ fmtMinor(row.requested_amount_minor, row.currency_code) }}</td>
                                    <td><span class="badge" :class="statusClass(row.status)">{{ t(`wallet.status.${row.status}`) }}</span></td>
                                    <td class="text-muted fs-12">{{ row.gateway_reference || '-' }}</td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <button type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('wallet.online.detail')" @click.stop="openDetail(row.id)">
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
            <WalletPagination v-model:per-page="perPage" :pagination="pagination" @change="fetch" />
        </div>

        <WalletModal :show="showDetail" :title="detail ? `#${detail.id} · ${t('wallet.online.detail')}` : ''" size="xl" @close="closeDetail">
            <div v-if="detailLoading" class="text-center py-5"><span class="spinner-border"></span></div>
            <div v-else-if="detail" class="d-flex flex-column gap-3">
                <WalletDetailHero
                    icon="ri-bank-card-line"
                    :label="t('wallet.online.paid_amount')"
                    :value="fmtMinor(detail.requested_amount_minor, detail.currency_code)"
                    :subtitle="detail.created_at ? formatDateTime(detail.created_at, locale) : ''"
                >
                    <span class="badge fs-12" :class="statusClass(detail.status)">{{ t(`wallet.status.${detail.status}`) }}</span>
                    <span v-if="detail.gateway_reference" class="text-muted fs-11" dir="ltr">{{ detail.gateway_reference }}</span>
                </WalletDetailHero>

                <div v-if="detail.failure_reason" class="alert alert-danger py-2 mb-0">
                    <i class="ri-error-warning-line me-1 align-middle"></i>{{ detail.failure_reason }}
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <WalletInfoTile icon="ri-user-line" :label="t('wallet.common.owner')">
                            {{ detail.owner?.name || `#${detail.owner_id}` }}
                            <div v-if="detail.owner?.phone" class="fs-12 fw-normal text-muted" dir="ltr">{{ detail.owner?.phone }}</div>
                        </WalletInfoTile>
                    </div>
                    <div class="col-md-4">
                        <WalletInfoTile icon="ri-secure-payment-line" :label="t('wallet.online.method')">
                            {{ detail.payment_method?.name }}
                            <div class="fs-12 fw-normal text-muted">{{ detail.payment_method?.gateway }}</div>
                        </WalletInfoTile>
                    </div>
                    <div class="col-md-4">
                        <WalletInfoTile icon="ri-repeat-line" :label="t('wallet.online.attempts')">
                            {{ detail.reconciliation_attempts }}
                        </WalletInfoTile>
                    </div>
                    <div class="col-md-6">
                        <WalletInfoTile icon="ri-percent-line" :label="t('wallet.online.fee')">
                            <span dir="ltr">{{ fmtMinor(detail.fee_minor, detail.currency_code) }}</span>
                            <span v-if="detail.fee_percent" class="text-muted fs-12 fw-normal"> ({{ detail.fee_percent }}%)</span>
                        </WalletInfoTile>
                    </div>
                    <div class="col-md-6">
                        <WalletInfoTile icon="ri-gift-line" :label="t('wallet.online.bonus')">
                            <span dir="ltr">{{ fmtMinor(detail.bonus_minor, detail.currency_code) }}</span>
                        </WalletInfoTile>
                    </div>
                </div>

                <WalletSection :title="t('wallet.online.gateway_log')" icon="ri-file-list-3-line" flush>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover text-nowrap mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-3">{{ t('wallet.common.date') }}</th>
                                    <th>{{ t('wallet.online.event') }}</th>
                                    <th>{{ t('wallet.online.direction') }}</th>
                                    <th>{{ t('wallet.online.reported') }}</th>
                                    <th class="pe-3">{{ t('wallet.online.payload') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="log in detail.logs" :key="log.id">
                                    <td class="ps-3">{{ formatDateTime(log.created_at, locale) }}</td>
                                    <td><span class="badge bg-primary-transparent">{{ log.event }}</span></td>
                                    <td>{{ log.direction }}</td>
                                    <td>{{ log.gateway_status_reported || '-' }}</td>
                                    <td class="text-wrap pe-3" style="min-width: 260px;">
                                        <details>
                                            <summary class="text-primary" role="button">JSON</summary>
                                            <pre class="fs-11 mb-0" dir="ltr">{{ pretty({ request: log.request_payload, response: log.response_payload }) }}</pre>
                                        </details>
                                    </td>
                                </tr>
                                <tr v-if="!detail.logs?.length"><td colspan="5" class="text-muted text-center py-3">{{ t('wallet.common.empty') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </WalletSection>

                <WalletSection :title="t('wallet.online.ledger_rows')" icon="ri-book-open-line" flush>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover text-nowrap mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-3">{{ t('wallet.common.type') }}</th>
                                    <th>{{ t('wallet.common.direction') }}</th>
                                    <th>{{ t('wallet.common.bucket') }}</th>
                                    <th class="pe-3">{{ t('wallet.common.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tx in detail.wallet_transactions" :key="tx.uuid">
                                    <td class="ps-3">{{ t(`wallet.tx.${tx.type}`) }}</td>
                                    <td>
                                        <span class="badge" :class="tx.direction === 'credit' ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ t(`wallet.direction.${tx.direction}`) }}</span>
                                    </td>
                                    <td>{{ t(`wallet.bucket.${tx.bucket}`) }}</td>
                                    <td class="pe-3 fw-semibold" dir="ltr">{{ fmtMinor(tx.amount_minor, detail.currency_code) }}</td>
                                </tr>
                                <tr v-if="!detail.wallet_transactions?.length"><td colspan="4" class="text-muted text-center py-3">{{ t('wallet.online.no_ledger') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </WalletSection>
            </div>

            <template #footer>
                <template v-if="detail">
                    <span v-if="actionError" class="text-danger me-auto fs-13">{{ actionError }}</span>

                    <template v-if="confirming">
                        <span class="me-auto fw-semibold fs-13">{{ confirming === 'refund' ? t('wallet.online.confirm_refund') : t('wallet.online.confirm_reconcile') }}</span>
                        <button type="button" class="btn btn-light btn-sm" :disabled="acting" @click="confirming = ''">{{ t('cancel') }}</button>
                        <button type="button" class="btn btn-sm" :class="confirming === 'refund' ? 'btn-danger' : 'btn-primary'" :disabled="acting" @click="runAction(confirming)">
                            <span v-if="acting" class="spinner-border spinner-border-sm me-1"></span>{{ t('wallet.common.confirm') }}
                        </button>
                    </template>
                    <template v-else>
                        <button
                            v-if="canReconcile && ['pending', 'expired'].includes(detail.status)"
                            type="button"
                            class="btn btn-primary btn-sm"
                            @click="confirming = 'reconcile'"
                        >
                            <i class="ri-refresh-line me-1"></i>{{ t('wallet.online.reconcile') }}
                        </button>
                        <button v-if="canRefund && detail.status === 'paid'" type="button" class="btn btn-danger-light btn-sm" @click="confirming = 'refund'">
                            <i class="ri-arrow-go-back-line me-1"></i>{{ t('wallet.online.refund') }}
                        </button>
                    </template>
                </template>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import AdminDatePicker from '../../../../../../../components/ui/AdminDatePicker.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletDetailHero from '../../../../../../../components/wallet/WalletDetailHero.vue';
import WalletInfoTile from '../../../../../../../components/wallet/WalletInfoTile.vue';
import WalletSection from '../../../../../../../components/wallet/WalletSection.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import { fmtMinor, formatDateTime } from '../../../../../../../utils/walletMoney';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess } = useToast();

const statuses = ['pending', 'paid', 'failed', 'expired', 'refunded'];

const statusFilterOptions = computed(() => [
    ...statuses.map((status) => ({ value: status, label: t(`wallet.status.${status}`) })),
]);
const ownerFilterOptions = computed(() => [
    { value: 'user', label: t('wallet.owner.user') },
    { value: 'provider', label: t('wallet.owner.provider') },
]);
const { rows, loading, pagination, filters, fetch, perPage } = useWalletList('online-transactions', {
    defaults: { search: '', status: null, owner_type: null, from: '', to: '' },
});

const canReconcile = computed(() => can('online-transactions.reconcile'));
const canRefund = computed(() => can('online-transactions.refund'));

const summary = ref([]);
const showDetail = ref(false);
const detail = ref(null);
const detailLoading = ref(false);
const confirming = ref('');
const acting = ref(false);
const actionError = ref('');

function statusClass(status) {
    return {
        paid: 'bg-success-transparent',
        pending: 'bg-warning-transparent',
        failed: 'bg-danger-transparent',
        expired: 'bg-secondary-transparent',
        refunded: 'bg-info-transparent',
    }[status] || 'bg-light text-default';
}

function pretty(value) {
    return JSON.stringify(value, null, 2);
}

async function loadSummary() {
    try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
        const { data } = await adminAxios.get('/api/admin/v1/online-transactions/summary', { params });

        summary.value = data.data ?? [];
    } catch {
        summary.value = [];
    }
}

async function reload() {
    await Promise.all([fetch(1), loadSummary()]);
}

async function openDetail(id) {
    showDetail.value = true;
    detail.value = null;
    detailLoading.value = true;
    confirming.value = '';
    actionError.value = '';

    try {
        const { data } = await adminAxios.get(`/api/admin/v1/online-transactions/${id}`);

        detail.value = data.data;
    } catch (error) {
        actionError.value = extractApiErrorMessage(error);
    } finally {
        detailLoading.value = false;
    }
}

function closeDetail() {
    showDetail.value = false;
}

async function runAction(kind) {
    acting.value = true;
    actionError.value = '';

    try {
        const { data } = await adminAxios.post(`/api/admin/v1/online-transactions/${detail.value.id}/${kind}`);

        detail.value = data.data;
        confirming.value = '';
        showSuccess(t(`wallet.online.${kind}_done`));
        reload();
    } catch (error) {
        actionError.value = extractApiErrorMessage(error);
        confirming.value = '';
    } finally {
        acting.value = false;
    }
}

watch(() => ({ ...filters }), loadSummary, { deep: true });

onMounted(reload);
</script>
