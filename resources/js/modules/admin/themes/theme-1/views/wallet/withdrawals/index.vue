<template>
    <div>
        <WalletPageHeader :title="t('wallet.withdrawals.title')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <button
                    v-for="s in ['', 'pending', 'approved', 'rejected']"
                    :key="s || 'all'"
                    type="button"
                    class="btn btn-sm"
                    :class="filters.status === s ? 'btn-primary' : 'btn-light'"
                    @click="filters.status = s"
                >
                    {{ s ? t(`wallet.wstatus.${s}`) : t('wallet.common.all_statuses') }}
                </button>
                <button type="button" class="btn btn-sm btn-light ms-auto" @click="fetch(1)"><i class="ri-refresh-line"></i></button>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">#</th>
                                <th>{{ t('wallet.common.date') }}</th>
                                <th>{{ t('wallet.common.owner') }}</th>
                                <th>{{ t('wallet.withdrawals.method') }}</th>
                                <th>{{ t('wallet.common.amount') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="7" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="7" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" role="button" @click="open(row.id)">
                                    <td class="ps-4">#{{ row.id }}</td>
                                    <td>{{ formatDateTime(row.created_at, locale) }}</td>
                                    <td>
                                        <div class="fw-semibold">{{ row.owner?.name || `#${row.owner?.id}` }}</div>
                                        <div class="fs-12 text-muted">{{ row.country_code }}</div>
                                    </td>
                                    <td>{{ row.method?.display }}</td>
                                    <td class="fw-semibold">{{ fmtMinor(row.amount_minor, row.currency_code) }}</td>
                                    <td><span class="badge" :class="statusClass(row.status)">{{ t(`wallet.wstatus.${row.status}`) }}</span></td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-info-light btn-icon" @click.stop="open(row.id)"><i class="ri-eye-line"></i></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <WalletModal :show="showModal" :title="detail ? `#${detail.id} · ${t('wallet.withdrawals.request')}` : ''" @close="close">
            <div v-if="detailLoading" class="text-center py-5"><span class="spinner-border"></span></div>
            <div v-else-if="detail">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="text-muted fs-12">{{ t('wallet.common.status') }}</div>
                        <span class="badge" :class="statusClass(detail.status)">{{ t(`wallet.wstatus.${detail.status}`) }}</span>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted fs-12">{{ t('wallet.common.owner') }}</div>
                        <div class="fw-semibold">{{ detail.owner?.name }}</div>
                        <div class="fs-12 text-muted" dir="ltr">{{ detail.owner?.phone }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted fs-12">{{ t('wallet.common.amount') }}</div>
                        <div class="fw-semibold fs-5">{{ fmtMinor(detail.amount_minor, detail.currency_code) }}</div>
                    </div>
                </div>

                <div class="card bg-light border-0 mb-3">
                    <div class="card-body py-3">
                        <h6 class="fw-semibold mb-2"><i class="ri-bank-line me-1"></i>{{ t('wallet.withdrawals.payout_to') }}</h6>
                        <div v-if="detail.payout_details" class="row g-2 fs-13">
                            <div v-for="(value, key) in detail.payout_details" :key="key" class="col-md-6">
                                <span class="text-muted">{{ t(`wallet.payout.${key}`) }}:</span>
                                <span class="fw-semibold user-select-all ms-1" dir="ltr">{{ value }}</span>
                            </div>
                        </div>
                        <div v-else class="text-muted">{{ detail.method?.display }}</div>
                    </div>
                </div>

                <div v-if="detail.note" class="mb-2"><span class="text-muted">{{ t('wallet.common.note') }}:</span> {{ detail.note }}</div>
                <div v-if="detail.rejection_reason" class="alert alert-danger py-2">{{ detail.rejection_reason }}</div>
                <button v-if="detail.has_receipt" type="button" class="btn btn-sm btn-outline-primary mb-2" @click="downloadReceipt">
                    <i class="ri-download-line me-1"></i>{{ t('wallet.withdrawals.receipt') }}
                </button>

                <template v-if="detail.status === 'pending'">
                    <hr>
                    <ul class="nav nav-tabs mb-3">
                        <li v-if="canApprove" class="nav-item"><button type="button" class="nav-link" :class="{ active: mode === 'approve' }" @click="mode = 'approve'">{{ t('wallet.withdrawals.approve') }}</button></li>
                        <li v-if="canReject" class="nav-item"><button type="button" class="nav-link" :class="{ active: mode === 'reject' }" @click="mode = 'reject'">{{ t('wallet.withdrawals.reject') }}</button></li>
                    </ul>

                    <form v-if="mode === 'approve' && canApprove" @submit.prevent="approve">
                        <div class="alert alert-info fs-13">{{ t('wallet.withdrawals.approve_hint') }}</div>
                        <div class="mb-3">
                            <label class="form-label">{{ t('wallet.withdrawals.receipt') }} <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" :class="{ 'is-invalid': errors.receipt }" accept=".jpg,.jpeg,.png,.pdf" @change="receipt = $event.target.files[0] || null">
                            <div v-if="errors.receipt" class="invalid-feedback">{{ errors.receipt }}</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ t('wallet.common.note') }}</label>
                            <input v-model="note" type="text" maxlength="500" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-success" :disabled="busy"><span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('wallet.withdrawals.approve') }}</button>
                    </form>

                    <form v-else-if="mode === 'reject' && canReject" @submit.prevent="reject">
                        <div class="mb-3">
                            <label class="form-label">{{ t('wallet.withdrawals.reason') }} <span class="text-danger">*</span></label>
                            <textarea v-model="reason" rows="3" maxlength="500" class="form-control" :class="{ 'is-invalid': errors.rejection_reason }"></textarea>
                            <div v-if="errors.rejection_reason" class="invalid-feedback">{{ errors.rejection_reason }}</div>
                        </div>
                        <button type="submit" class="btn btn-danger" :disabled="busy"><span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('wallet.withdrawals.reject') }}</button>
                    </form>
                </template>
                <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
            </div>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import { fmtMinor, formatDateTime } from '../../../../../../../utils/walletMoney';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess } = useToast();

// Pending first: that is what a reviewer opens this screen for.
const { rows, loading, pagination, filters, fetch } = useWalletList('withdrawal-requests', { defaults: { status: 'pending' } });

const canApprove = computed(() => can('withdrawal-requests.approve'));
const canReject = computed(() => can('withdrawal-requests.reject'));

const showModal = ref(false);
const detail = ref(null);
const detailLoading = ref(false);
const mode = ref('approve');
const receipt = ref(null);
const note = ref('');
const reason = ref('');
const busy = ref(false);
const errors = reactive({ receipt: '', rejection_reason: '', general: '' });

function statusClass(status) {
    return { pending: 'bg-warning-transparent', approved: 'bg-success-transparent', rejected: 'bg-danger-transparent' }[status] || 'bg-light text-default';
}

function resetForm() {
    receipt.value = null;
    note.value = '';
    reason.value = '';
    Object.assign(errors, { receipt: '', rejection_reason: '', general: '' });
    mode.value = canApprove.value ? 'approve' : 'reject';
}

async function open(id) {
    showModal.value = true;
    detail.value = null;
    detailLoading.value = true;
    resetForm();

    try {
        const { data } = await adminAxios.get(`/api/admin/v1/withdrawal-requests/${id}`);

        detail.value = data.data;
    } finally {
        detailLoading.value = false;
    }
}

function close() {
    showModal.value = false;
}

function applyErrors(error) {
    const bag = error?.response?.data?.errors ?? {};

    errors.receipt = bag.receipt?.[0] ?? '';
    errors.rejection_reason = bag.rejection_reason?.[0] ?? '';
    errors.general = Object.keys(bag).length ? '' : (error?.response?.data?.message ?? '');
}

async function approve() {
    Object.assign(errors, { receipt: '', rejection_reason: '', general: '' });

    if (! receipt.value) {
        errors.receipt = t('wallet.withdrawals.receipt_required');

        return;
    }

    busy.value = true;

    try {
        const form = new FormData();

        form.append('receipt', receipt.value);

        if (note.value) {
            form.append('note', note.value);
        }

        const { data } = await adminAxios.post(`/api/admin/v1/withdrawal-requests/${detail.value.id}/approve`, form);

        detail.value = data.data;
        showSuccess(t('wallet.withdrawals.approved'));
        fetch();
    } catch (error) {
        applyErrors(error);
    } finally {
        busy.value = false;
    }
}

async function reject() {
    Object.assign(errors, { receipt: '', rejection_reason: '', general: '' });

    if (reason.value.trim().length < 3) {
        errors.rejection_reason = t('wallet.withdrawals.reason_required');

        return;
    }

    busy.value = true;

    try {
        const { data } = await adminAxios.post(`/api/admin/v1/withdrawal-requests/${detail.value.id}/reject`, {
            rejection_reason: reason.value.trim(),
        });

        detail.value = data.data;
        showSuccess(t('wallet.withdrawals.rejected'));
        fetch();
    } catch (error) {
        applyErrors(error);
    } finally {
        busy.value = false;
    }
}

async function downloadReceipt() {
    // The receipt is on a private disk: fetch it with the admin token, then open it locally.
    const response = await adminAxios.get(`/api/admin/v1/withdrawal-requests/${detail.value.id}/receipt`, { responseType: 'blob' });

    window.open(URL.createObjectURL(response.data), '_blank');
}

onMounted(() => fetch(1));
</script>
