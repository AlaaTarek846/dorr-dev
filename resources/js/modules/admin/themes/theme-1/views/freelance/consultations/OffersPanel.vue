<template>
    <div>
        <div class="alert alert-light border fs-13">{{ t('freelance.consultations.offers.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <FlStatusFilter v-model="status" :options="statusOptions" />
                <div class="input-group input-group-sm catalog-toolbar-search ms-auto">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.consultations.offers.search')">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.consultations.offers.listing') }}</th>
                                <th>{{ t('freelance.consultations.freelancer') }}</th>
                                <th>{{ t('freelance.consultations.offers.lengths') }}</th>
                                <th>{{ t('freelance.consultations.offers.submitted_at') }}</th>
                                <th>{{ t('freelance.consultations.state') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :layout="['two-lines', 'two-lines', 'text', 'text', 'badge', 'actions']" />
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="o in rows" v-else :key="o.uuid" :class="{ 'table-warning': o.status === 'pending_review' }">
                                <td class="ps-4"><span class="d-block fw-semibold text-truncate cell-title">{{ o.title }}</span></td>
                                <td>
                                    <span class="d-block">{{ o.freelancer?.name || '—' }}</span>
                                    <span class="text-muted fs-11" dir="ltr">{{ o.freelancer?.phone }}</span>
                                </td>
                                <td>
                                    <span v-for="x in o.options" :key="x.minutes" class="d-block fs-12">{{ t('freelance.consultations.minutes_n', { n: x.minutes }) }} · {{ money(x.price_minor, o.decimals, o.currency) }}</span>
                                </td>
                                <td class="fs-13">{{ when(o.submitted_at) }}</td>
                                <td><span class="badge" :class="badge(o.status)">{{ t(`freelance.consultations.offers.status.${o.status}`) }}</span></td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-wave" :class="o.status === 'pending_review' && canUpdate ? 'btn-warning' : 'btn-light'" @click="open(o)">
                                        <i class="ri-eye-line me-1"></i>{{ t('freelance.consultations.open') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="!loading && rows.length" class="card-footer border-top-0 d-flex align-items-center flex-wrap gap-3">
                <div class="text-muted fs-13">{{ t('countries.showing_entries', { from: pagination.from ?? 0, to: pagination.to ?? 0, total: pagination.total ?? 0 }) }}</div>
                <nav v-if="(pagination.last_page ?? 1) > 1" class="pagination-style-4 ms-md-auto">
                    <ul class="pagination mb-0">
                        <li class="page-item" :class="{ disabled: pageNo <= 1 }"><button type="button" class="page-link" @click="pageNo -= 1">{{ t('countries.previous') }}</button></li>
                        <li class="page-item active"><span class="page-link">{{ pageNo }}</span></li>
                        <li class="page-item" :class="{ disabled: pageNo >= pagination.last_page }"><button type="button" class="page-link text-primary" @click="pageNo += 1">{{ t('countries.next') }}</button></li>
                    </ul>
                </nav>
            </div>
        </div>

        <!-- One listing: what the freelancer wrote, the lengths and hours, and the decision. -->
        <WalletModal :show="!!current" :title="current?.title || ''" size="lg" @close="close">
            <div v-if="current">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge" :class="badge(current.status)">{{ t(`freelance.consultations.offers.status.${current.status}`) }}</span>
                    <span class="text-muted fs-12">{{ current.freelancer?.name }} · <span dir="ltr">{{ current.freelancer?.phone }}</span></span>
                </div>

                <template v-if="current.about">
                    <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.consultations.offers.about') }}</h6>
                    <p class="fs-13 pre-line bg-light rounded-3 p-3">{{ current.about }}</p>
                </template>

                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.consultations.offers.lengths') }}</h6>
                <ul class="list-group mb-3 fs-13">
                    <li v-for="x in current.options" :key="x.minutes" class="list-group-item d-flex justify-content-between">
                        <span>{{ t('freelance.consultations.minutes_n', { n: x.minutes }) }}</span>
                        <span>{{ money(x.price_minor, current.decimals, current.currency) }} <span class="text-muted fs-11">({{ t('freelance.consultations.net') }} {{ money(x.net_minor, current.decimals, current.currency) }})</span></span>
                    </li>
                </ul>

                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.consultations.offers.weekly') }} <span class="text-muted fs-11" dir="ltr">({{ current.timezone }})</span></h6>
                <ul class="list-unstyled fs-13 mb-3">
                    <li v-for="(w, i) in current.weekly" :key="i" class="py-1"><i class="ri-time-line me-2 text-muted"></i>{{ days[w.day] }} <span dir="ltr">{{ w.from }} – {{ w.to }}</span></li>
                </ul>

                <div v-if="current.status_reason" class="alert alert-light border fs-13">
                    <div class="fw-semibold mb-1">{{ t('freelance.consultations.offers.reason') }}</div>
                    <div class="pre-line">{{ current.status_reason }}</div>
                </div>

                <template v-if="needsReason">
                    <textarea v-model="reason" class="form-control" rows="2" maxlength="500" :placeholder="t('freelance.consultations.offers.reason_hint')"></textarea>
                </template>
            </div>
            <template v-if="current && canUpdate" #footer>
                <template v-if="pending">
                    <button type="button" class="btn btn-light" @click="pending = null">{{ t('cancel') }}</button>
                    <button type="button" class="btn btn-primary" :disabled="deciding || reason.trim().length < 3" @click="decide(pending)">
                        <span v-if="deciding" class="spinner-border spinner-border-sm me-1"></span>{{ t(`freelance.consultations.offers.${pending}`) }}
                    </button>
                </template>
                <template v-else>
                    <button v-if="current.status === 'suspended'" type="button" class="btn btn-outline-primary" :disabled="deciding" @click="decide('unsuspend')">{{ t('freelance.consultations.offers.unsuspend') }}</button>
                    <button v-if="current.status !== 'suspended' && current.status !== 'rejected'" type="button" class="btn btn-outline-danger" :disabled="deciding" @click="pending = 'suspend'">{{ t('freelance.consultations.offers.suspend') }}</button>
                    <template v-if="current.status === 'pending_review'">
                        <button type="button" class="btn btn-outline-warning" :disabled="deciding" @click="pending = 'reject'">{{ t('freelance.consultations.offers.reject') }}</button>
                        <button type="button" class="btn btn-success" :disabled="deciding" @click="decide('approve')">{{ t('freelance.consultations.offers.approve') }}</button>
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
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// The consultations freelancers list (FL-L07), like the ready-made services: waiting ones first, and approve / reject / stop / lift.
const { t, tm, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const base = '/api/admin/v1/freelance-consultation-offers';
const canUpdate = computed(() => can('freelance-consultations.update'));
const days = computed(() => tm('freelance.consultations.offers.days'));

const STATUSES = ['pending_review', 'published', 'paused', 'rejected', 'suspended'];
const money = (minor, decimals = 2, currency = '') => (minor == null ? '—' : `${(Number(minor) / 10 ** decimals).toFixed(decimals)} ${currency}`.trim());
const when = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const badge = (s) => ({
    pending_review: 'bg-warning-transparent', published: 'bg-success-transparent', paused: 'bg-secondary-transparent',
    rejected: 'bg-danger-transparent', suspended: 'bg-dark-transparent',
}[s] ?? 'bg-light text-default');

const rows = ref([]);
const pagination = ref({});
const counts = ref({});
const pageNo = ref(1);
const loading = ref(false);
const status = ref('all');
const search = ref('');
const statusOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), count: Object.values(counts.value).reduce((a, b) => a + b, 0), tone: 'all' },
    ...STATUSES.map((s) => ({
        value: s, label: t(`freelance.consultations.offers.status.${s}`), count: counts.value[s] ?? 0,
        tone: { pending_review: 'pending', published: 'active', paused: 'inactive', rejected: 'deleted', suspended: 'deleted' }[s],
    })),
]);

let timer = null;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => (pageNo.value === 1 ? load() : (pageNo.value = 1)), 300); });
watch(status, () => (pageNo.value === 1 ? load() : (pageNo.value = 1)));
watch(pageNo, load);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(base, { params: { page: pageNo.value, search: search.value || undefined, status: status.value === 'all' ? undefined : status.value } });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
        counts.value = data.counts ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

// ---- one listing
const current = ref(null);
const pending = ref(null);
const reason = ref('');
const deciding = ref(false);
const needsReason = computed(() => pending.value === 'reject' || pending.value === 'suspend');

function open(o) {
    current.value = o;
    pending.value = null;
    reason.value = '';
}

function close() {
    current.value = null;
    pending.value = null;
}

async function decide(action) {
    deciding.value = true;
    try {
        const body = action === 'reject' || action === 'suspend' ? { reason: reason.value.trim() } : {};
        const response = await adminAxios.post(`${base}/${current.value.uuid}/${action}`, body);
        showSuccess(extractApiMessage(response, t('toast.success')));
        current.value = response.data.data;
        pending.value = null;
        reason.value = '';
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        deciding.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.cell-title {
    max-width: 260px;
}

.pre-line {
    white-space: pre-line;
}
</style>
