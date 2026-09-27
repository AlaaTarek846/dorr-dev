<template>
    <div>
        <WalletPageHeader :title="t('wallet.pinrec.title')" :total="pagination?.total ?? null" />

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
                                <th>{{ t('wallet.pinrec.document') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" role="button" @click="open(row.id)">
                                    <td class="ps-4">#{{ row.id }}</td>
                                    <td>{{ formatDateTime(row.created_at, locale) }}</td>
                                    <td>
                                        <div class="fw-semibold">{{ row.owner?.name || `#${row.owner?.id}` }}</div>
                                        <div class="fs-12 text-muted" dir="ltr">{{ row.owner?.phone }}</div>
                                    </td>
                                    <td>{{ t(`wallet.pinrec.method.${row.method}`) }}</td>
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

        <WalletModal :show="showModal" :title="detail ? `#${detail.id} · ${t('wallet.pinrec.request')}` : ''" @close="close">
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
                        <div class="text-muted fs-12">{{ t('wallet.pinrec.document') }}</div>
                        <div class="fw-semibold">{{ t(`wallet.pinrec.method.${detail.method}`) }}</div>
                    </div>
                </div>

                <div class="alert alert-info fs-13 py-2">{{ t('wallet.pinrec.compare_hint') }}</div>

                <div class="row g-3 mb-3">
                    <div v-for="side in sides" :key="side.key" class="col-md-6">
                        <div class="fw-semibold mb-2">{{ t(`wallet.pinrec.${side.key}`) }}</div>
                        <div class="border rounded d-flex align-items-center justify-content-center bg-light" style="min-height: 240px">
                            <span v-if="images[side.key] === undefined" class="spinner-border spinner-border-sm"></span>
                            <span v-else-if="images[side.key] === null" class="text-muted fs-13">{{ t('wallet.pinrec.no_image') }}</span>
                            <a v-else :href="images[side.key]" target="_blank" rel="noopener">
                                <img :src="images[side.key]" :alt="t(`wallet.pinrec.${side.key}`)" class="img-fluid rounded" style="max-height: 360px">
                            </a>
                        </div>
                    </div>
                </div>

                <div v-if="detail.rejection_reason" class="alert alert-danger py-2">{{ detail.rejection_reason }}</div>

                <template v-if="detail.status === 'pending'">
                    <hr>
                    <ul class="nav nav-tabs mb-3">
                        <li v-if="canApprove" class="nav-item"><button type="button" class="nav-link" :class="{ active: mode === 'approve' }" @click="mode = 'approve'">{{ t('wallet.withdrawals.approve') }}</button></li>
                        <li v-if="canReject" class="nav-item"><button type="button" class="nav-link" :class="{ active: mode === 'reject' }" @click="mode = 'reject'">{{ t('wallet.withdrawals.reject') }}</button></li>
                    </ul>

                    <div v-if="mode === 'approve' && canApprove">
                        <div class="alert alert-warning fs-13">{{ t('wallet.pinrec.approve_hint') }}</div>
                        <button type="button" class="btn btn-success" :disabled="busy" @click="approve">
                            <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('wallet.withdrawals.approve') }}
                        </button>
                    </div>

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
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatDateTime } from '../../../../../../../utils/walletMoney';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess } = useToast();

// Pending first: that is what a reviewer opens this screen for.
const { rows, loading, pagination, filters, fetch } = useWalletList('pin-recovery-requests', { defaults: { status: 'pending' } });

const canApprove = computed(() => can('pin-recovery-requests.approve'));
const canReject = computed(() => can('pin-recovery-requests.reject'));

const sides = [{ key: 'original' }, { key: 'new' }];

const showModal = ref(false);
const detail = ref(null);
const detailLoading = ref(false);
const mode = ref('approve');
const reason = ref('');
const busy = ref(false);
const errors = reactive({ rejection_reason: '', general: '' });
// key -> object URL | null (no image) | undefined (still loading)
const images = reactive({ original: undefined, new: undefined });

function statusClass(status) {
    return { pending: 'bg-warning-transparent', approved: 'bg-success-transparent', rejected: 'bg-danger-transparent' }[status] || 'bg-light text-default';
}

function releaseImages() {
    Object.keys(images).forEach((key) => {
        if (images[key]) {
            URL.revokeObjectURL(images[key]);
        }

        images[key] = undefined;
    });
}

// The photos are personal documents on a private disk: fetch them with the admin token as blobs.
async function loadImages(row) {
    await Promise.all(sides.map(async ({ key }) => {
        const url = row[`${key}_image`];

        if (! url) {
            images[key] = null;

            return;
        }

        try {
            const response = await adminAxios.get(url, { responseType: 'blob' });

            images[key] = URL.createObjectURL(response.data);
        } catch {
            images[key] = null;
        }
    }));
}

async function open(id) {
    showModal.value = true;
    detail.value = null;
    detailLoading.value = true;
    reason.value = '';
    Object.assign(errors, { rejection_reason: '', general: '' });
    mode.value = canApprove.value ? 'approve' : 'reject';
    releaseImages();

    try {
        const { data } = await adminAxios.get(`/api/admin/v1/pin-recovery-requests/${id}`);

        detail.value = data.data;
        loadImages(data.data);
    } finally {
        detailLoading.value = false;
    }
}

function close() {
    showModal.value = false;
    releaseImages();
}

function applyErrors(error) {
    const bag = error?.response?.data?.errors ?? {};

    errors.rejection_reason = bag.rejection_reason?.[0] ?? '';
    errors.general = Object.keys(bag).length ? '' : (error?.response?.data?.message ?? '');
}

async function approve() {
    Object.assign(errors, { rejection_reason: '', general: '' });
    busy.value = true;

    try {
        const { data } = await adminAxios.post(`/api/admin/v1/pin-recovery-requests/${detail.value.id}/approve`);

        detail.value = data.data;
        showSuccess(t('wallet.pinrec.approved'));
        fetch();
    } catch (error) {
        applyErrors(error);
    } finally {
        busy.value = false;
    }
}

async function reject() {
    Object.assign(errors, { rejection_reason: '', general: '' });

    if (reason.value.trim().length < 3) {
        errors.rejection_reason = t('wallet.withdrawals.reason_required');

        return;
    }

    busy.value = true;

    try {
        const { data } = await adminAxios.post(`/api/admin/v1/pin-recovery-requests/${detail.value.id}/reject`, {
            rejection_reason: reason.value.trim(),
        });

        detail.value = data.data;
        showSuccess(t('wallet.pinrec.rejected'));
        fetch();
    } catch (error) {
        applyErrors(error);
    } finally {
        busy.value = false;
    }
}

onMounted(() => fetch(1));
onBeforeUnmount(releaseImages);
</script>
