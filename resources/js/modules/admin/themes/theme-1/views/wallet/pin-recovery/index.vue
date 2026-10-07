<template>
    <div>
        <WalletPageHeader :title="t('wallet.pinrec.title')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-1 catalog-toolbar-filters">
                    <button
                        v-for="s in ['', 'pending', 'approved', 'rejected']"
                        :key="s || 'all'"
                        type="button"
                        class="btn btn-sm catalog-filter-btn"
                        :class="filters.status === s ? 'catalog-filter-btn--all' : 'catalog-filter-btn--all-idle'"
                        @click="filters.status = s"
                    >
                        {{ s ? t(`wallet.wstatus.${s}`) : t('wallet.common.all_statuses') }}
                    </button>
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-light" :title="t('wallet.common.refresh')" @click="fetch(1)"><i class="ri-refresh-line"></i></button>
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
                                <th>{{ t('wallet.pinrec.document') }}</th>
                                <th>{{ t('wallet.pinrec.reason_column') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('wallet.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :columns="7" />
                            <tr v-else-if="!rows.length">
                                <td colspan="7" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                            <i class="ri-lock-password-line fs-2 text-primary"></i>
                                        </span>
                                        <p class="fw-semibold mb-1">{{ t('wallet.common.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ t('wallet.common.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" class="crm-contact" @click="open(row.id)">
                                    <td class="ps-4"><button type="button" class="btn btn-link p-0 fw-semibold text-default" @click.stop="open(row.id)">#{{ row.id }}</button></td>
                                    <td>{{ formatDateTime(row.created_at, locale) }}</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent">
                                                <i class="ri-user-line text-primary"></i>
                                            </span>
                                            <div>
                                                <button type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click.stop="open(row.id)">
                                                    {{ row.owner?.name || `#${row.owner?.id}` }}
                                                </button>
                                                <span class="d-block text-muted fs-11" dir="ltr">{{ row.owner?.phone }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-secondary-transparent">{{ t(`wallet.pinrec.method.${row.method}`) }}</span></td>
                                    <td>
                                        <span class="badge" :class="row.reason === 'security_freeze' ? 'bg-danger-transparent' : 'bg-info-transparent'">
                                            {{ t(`wallet.pinrec.reason.${row.reason}`) }}
                                        </span>
                                    </td>
                                    <td><span class="badge" :class="statusClass(row.status)">{{ t(`wallet.wstatus.${row.status}`) }}</span></td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <button type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('wallet.common.view')" @click.stop="open(row.id)"><i class="ri-eye-line"></i></button>
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

        <WalletModal :show="showModal" :title="detail ? `#${detail.id} · ${t('wallet.pinrec.request')}` : ''" @close="close">
            <div v-if="detailLoading" class="text-center py-5"><span class="spinner-border"></span></div>
            <div v-else-if="detail" class="d-flex flex-column gap-3">
                <WalletDetailHero
                    icon="ri-lock-password-line"
                    :label="t('wallet.pinrec.request')"
                    :value="`#${detail.id}`"
                    :subtitle="detail.created_at ? formatDateTime(detail.created_at, locale) : ''"
                >
                    <span class="badge fs-12" :class="statusClass(detail.status)">{{ t(`wallet.wstatus.${detail.status}`) }}</span>
                </WalletDetailHero>

                <div class="row g-3">
                    <div class="col-md-6">
                        <WalletInfoTile icon="ri-user-line" :label="t('wallet.common.owner')">
                            {{ detail.owner?.name }}
                            <div v-if="detail.owner?.phone" class="fs-12 fw-normal text-muted" dir="ltr">{{ detail.owner?.phone }}</div>
                        </WalletInfoTile>
                    </div>
                    <div class="col-md-6">
                        <WalletInfoTile icon="ri-id-card-line" :label="t('wallet.pinrec.document')">
                            {{ t(`wallet.pinrec.method.${detail.method}`) }}
                        </WalletInfoTile>
                    </div>
                </div>

                <div class="alert py-2 mb-0" :class="detail.reason === 'security_freeze' ? 'alert-danger' : 'alert-info'">
                    <i class="ri-information-line me-1 align-middle"></i>{{ t(`wallet.pinrec.compare_hint.${detail.reason}`) }}
                </div>

                <WalletSection :title="t('wallet.pinrec.document')" icon="ri-image-line">
                    <div class="row g-3">
                        <div v-for="side in sides" :key="side.key" class="col-md-6">
                            <div class="fw-semibold fs-13 mb-2">{{ t(`wallet.pinrec.${side.key}.${detail.reason}`) }}</div>
                            <div class="border rounded-3 d-flex align-items-center justify-content-center bg-light overflow-hidden" style="min-height: 240px">
                                <span v-if="images[side.key] === undefined" class="spinner-border spinner-border-sm"></span>
                                <span v-else-if="images[side.key] === null" class="text-muted fs-13"><i class="ri-image-line me-1"></i>{{ t('wallet.pinrec.no_image') }}</span>
                                <a v-else :href="images[side.key]" target="_blank" rel="noopener">
                                    <img :src="images[side.key]" :alt="t(`wallet.pinrec.${side.key}`)" class="img-fluid" style="max-height: 360px">
                                </a>
                            </div>
                        </div>
                    </div>
                </WalletSection>

                <div v-if="detail.rejection_reason" class="alert alert-danger py-2 mb-0">
                    <i class="ri-close-circle-line me-1 align-middle"></i>{{ detail.rejection_reason }}
                </div>

                <WalletSection v-if="detail.status === 'pending'" :title="t('wallet.pinrec.review')" icon="ri-shield-check-line">
                    <ul class="nav nav-tabs mb-3">
                        <li v-if="canApprove" class="nav-item"><button type="button" class="nav-link" :class="{ active: mode === 'approve' }" @click="mode = 'approve'">{{ t('wallet.withdrawals.approve') }}</button></li>
                        <li v-if="canReject" class="nav-item"><button type="button" class="nav-link" :class="{ active: mode === 'reject' }" @click="mode = 'reject'">{{ t('wallet.withdrawals.reject') }}</button></li>
                    </ul>

                    <div v-if="mode === 'approve' && canApprove">
                        <div class="alert alert-warning fs-13">{{ t('wallet.pinrec.approve_hint') }}</div>
                        <button type="button" class="btn btn-success btn-wave" :disabled="busy" @click="approve">
                            <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('wallet.withdrawals.approve') }}
                        </button>
                    </div>

                    <form v-else-if="mode === 'reject' && canReject" @submit.prevent="reject">
                        <div class="mb-3">
                            <label class="form-label">{{ t('wallet.withdrawals.reason') }} <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light align-self-start"><i class="ri-file-text-line"></i></span>
                                <textarea
                                    v-model="review.reason"
                                    rows="3"
                                    maxlength="500"
                                    class="form-control"
                                    :class="classOf('reason')"
                                    @input="onInput('reason')"
                                ></textarea>
                            </div>
                            <div v-if="messageOf('reason')" class="invalid-feedback d-block">{{ messageOf('reason') }}</div>
                        </div>
                        <button type="submit" class="btn btn-danger btn-wave" :disabled="busy"><span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('wallet.withdrawals.reject') }}</button>
                    </form>
                    <div v-if="generalError" class="text-danger mt-2 fs-13">{{ generalError }}</div>
                </WalletSection>
                <div v-else-if="generalError" class="text-danger fs-13">{{ generalError }}</div>
            </div>
        </WalletModal>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import FormFieldFeedback from '../../../../../../../components/ui/FormFieldFeedback.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletDetailHero from '../../../../../../../components/wallet/WalletDetailHero.vue';
import WalletInfoTile from '../../../../../../../components/wallet/WalletInfoTile.vue';
import WalletSection from '../../../../../../../components/wallet/WalletSection.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast from '../../../../../../../composables/useToast';
import useFormFields from '../../../../../../../composables/useFormFields';
import useValidation from '../../../../../../../composables/useValidation';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatDateTime } from '../../../../../../../utils/walletMoney';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showWarning } = useToast();

// Pending first: that is what a reviewer opens this screen for.
const { rows, loading, pagination, filters, fetch, perPage } = useWalletList('pin-recovery-requests', { defaults: { status: 'pending' } });

const canApprove = computed(() => can('pin-recovery-requests.approve'));
const canReject = computed(() => can('pin-recovery-requests.reject'));

const sides = [{ key: 'original' }, { key: 'new' }];

const showModal = ref(false);
const detail = ref(null);
const detailLoading = ref(false);
const mode = ref('approve');
const review = reactive({ reason: '' });
const busy = ref(false);
const serverErrors = reactive({});
const generalError = ref('');
const { requiredField, minString, maxString, applyApiErrors } = useValidation();

// The reason is only needed (and only validated) while the reject tab is open.
const reviewRules = computed(() => ({
    reason: mode.value === 'reject'
        ? {
            required: requiredField('wallet.withdrawals.reason'),
            minLength: minString('wallet.withdrawals.reason', 3),
            maxLength: maxString('wallet.withdrawals.reason', 500),
        }
        : {},
}));

const v$ = useVuelidate(reviewRules, review, { $autoDirty: true });
const { feedbackOf, classOf, messageOf, onInput } = useFormFields({
    getV$: () => v$.value,
    form: review,
    serverErrors,
    serverKeys: { reason: 'rejection_reason' },
});

function resetReview() {
    review.reason = '';
    applyApiErrors(serverErrors, {});
    generalError.value = '';
    v$.value.$reset();
}

watch(mode, resetReview);
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
    mode.value = canApprove.value ? 'approve' : 'reject';
    resetReview();
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

    applyApiErrors(serverErrors, bag);
    generalError.value = Object.keys(bag).length ? '' : (error?.response?.data?.message ?? '');
}

async function approve() {
    generalError.value = '';
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
    generalError.value = '';

    if (! (await v$.value.$validate())) {
        showWarning(t('toast.validation_error'));

        return;
    }

    busy.value = true;

    try {
        const { data } = await adminAxios.post(`/api/admin/v1/pin-recovery-requests/${detail.value.id}/reject`, {
            rejection_reason: review.reason.trim(),
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
