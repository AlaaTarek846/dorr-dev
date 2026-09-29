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
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('wallet.online.search')">
                </div>
                <select v-model="filters.status" class="form-select form-select-sm w-auto">
                    <option value="">{{ t('wallet.common.all_statuses') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`wallet.status.${s}`) }}</option>
                </select>
                <select v-model="filters.owner_type" class="form-select form-select-sm w-auto">
                    <option value="">{{ t('wallet.common.all_owners') }}</option>
                    <option value="user">{{ t('wallet.owner.user') }}</option>
                    <option value="provider">{{ t('wallet.owner.provider') }}</option>
                </select>
                <input v-model="filters.from" type="date" class="form-control form-control-sm w-auto">
                <input v-model="filters.to" type="date" class="form-control form-control-sm w-auto">
                <button type="button" class="btn btn-sm btn-light ms-auto" @click="reload">
                    <i class="ri-refresh-line"></i>
                </button>
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
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="8" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td>
                            </tr>
                            <tr v-else-if="!rows.length">
                                <td colspan="8" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" role="button" @click="openDetail(row.id)">
                                    <td class="ps-4">#{{ row.id }}</td>
                                    <td>{{ formatDateTime(row.created_at, locale) }}</td>
                                    <td>
                                        <span class="badge bg-light text-default">{{ t(`wallet.owner.${row.owner_type}`) }}</span>
                                        #{{ row.owner_id }}
                                    </td>
                                    <td>{{ row.payment_method?.name || row.payment_method?.code }}</td>
                                    <td class="fw-semibold">{{ fmtMinor(row.requested_amount_minor, row.currency_code) }}</td>
                                    <td><span class="badge" :class="statusClass(row.status)">{{ t(`wallet.status.${row.status}`) }}</span></td>
                                    <td class="text-muted fs-12">{{ row.gateway_reference || '-' }}</td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-info-light btn-icon" @click.stop="openDetail(row.id)">
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

        <WalletModal :show="showDetail" :title="detail ? `#${detail.id} · ${t('wallet.online.detail')}` : ''" size="xl" @close="closeDetail">
            <div v-if="detailLoading" class="text-center py-5"><span class="spinner-border"></span></div>
            <div v-else-if="detail">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="text-muted fs-12">{{ t('wallet.common.status') }}</div>
                        <span class="badge" :class="statusClass(detail.status)">{{ t(`wallet.status.${detail.status}`) }}</span>
                        <div v-if="detail.failure_reason" class="text-danger fs-12 mt-1">{{ detail.failure_reason }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted fs-12">{{ t('wallet.common.owner') }}</div>
                        <div class="fw-semibold">{{ detail.owner?.name || `#${detail.owner_id}` }}</div>
                        <div class="fs-12 text-muted" dir="ltr">{{ detail.owner?.phone || '' }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted fs-12">{{ t('wallet.online.method') }}</div>
                        <div class="fw-semibold">{{ detail.payment_method?.name }} <span class="text-muted fs-12">({{ detail.payment_method?.gateway }})</span></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted fs-12">{{ t('wallet.online.paid_amount') }}</div>
                        <div class="fw-semibold">{{ fmtMinor(detail.requested_amount_minor, detail.currency_code) }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted fs-12">{{ t('wallet.online.fee') }}</div>
                        <div class="fw-semibold">{{ fmtMinor(detail.fee_minor, detail.currency_code) }} <span v-if="detail.fee_percent" class="text-muted fs-12">({{ detail.fee_percent }}%)</span></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted fs-12">{{ t('wallet.online.bonus') }}</div>
                        <div class="fw-semibold">{{ fmtMinor(detail.bonus_minor, detail.currency_code) }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted fs-12">{{ t('wallet.online.attempts') }}</div>
                        <div class="fw-semibold">{{ detail.reconciliation_attempts }}</div>
                    </div>
                </div>

                <h6 class="fw-semibold">{{ t('wallet.online.gateway_log') }}</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered text-nowrap mb-0">
                        <thead>
                            <tr>
                                <th>{{ t('wallet.common.date') }}</th>
                                <th>{{ t('wallet.online.event') }}</th>
                                <th>{{ t('wallet.online.direction') }}</th>
                                <th>{{ t('wallet.online.reported') }}</th>
                                <th>{{ t('wallet.online.payload') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="log in detail.logs" :key="log.id">
                                <td>{{ formatDateTime(log.created_at, locale) }}</td>
                                <td><span class="badge bg-primary-transparent">{{ log.event }}</span></td>
                                <td>{{ log.direction }}</td>
                                <td>{{ log.gateway_status_reported || '-' }}</td>
                                <td class="text-wrap" style="min-width: 260px;">
                                    <details>
                                        <summary class="text-primary" role="button">JSON</summary>
                                        <pre class="fs-11 mb-0" dir="ltr">{{ pretty({ request: log.request_payload, response: log.response_payload }) }}</pre>
                                    </details>
                                </td>
                            </tr>
                            <tr v-if="!detail.logs?.length"><td colspan="5" class="text-muted text-center">{{ t('wallet.common.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <h6 class="fw-semibold">{{ t('wallet.online.ledger_rows') }}</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered text-nowrap mb-0">
                        <thead>
                            <tr>
                                <th>{{ t('wallet.common.type') }}</th>
                                <th>{{ t('wallet.common.direction') }}</th>
                                <th>{{ t('wallet.common.bucket') }}</th>
                                <th>{{ t('wallet.common.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tx in detail.wallet_transactions" :key="tx.uuid">
                                <td>{{ t(`wallet.tx.${tx.type}`) }}</td>
                                <td>{{ t(`wallet.direction.${tx.direction}`) }}</td>
                                <td>{{ t(`wallet.bucket.${tx.bucket}`) }}</td>
                                <td>{{ fmtMinor(tx.amount_minor, detail.currency_code) }}</td>
                            </tr>
                            <tr v-if="!detail.wallet_transactions?.length"><td colspan="4" class="text-muted text-center">{{ t('wallet.online.no_ledger') }}</td></tr>
                        </tbody>
                    </table>
                </div>
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
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
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
const { rows, loading, pagination, filters, fetch } = useWalletList('online-transactions', {
    defaults: { search: '', status: '', owner_type: '', from: '', to: '' },
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
