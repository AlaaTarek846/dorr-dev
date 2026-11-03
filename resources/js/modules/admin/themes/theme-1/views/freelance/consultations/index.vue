<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.consultations.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.consultations.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <ul class="nav nav-pills mb-3 gap-1">
            <li v-for="x in ['offers', 'bookings']" :key="x" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: tab === x }" @click="tab = x">{{ t(`freelance.consultations.tabs.${x}`) }}</button>
            </li>
        </ul>

        <OffersPanel v-if="tab === 'offers'" />
        <template v-else>
        <div class="alert alert-light border fs-13">{{ t('freelance.consultations.hint') }}</div>

        <!-- The numbers at a glance: what needs a decision first. -->
        <div class="row g-3 mb-3">
            <div v-for="k in kpis" :key="k.key" class="col-6 col-lg">
                <button type="button" class="card custom-card mb-0 w-100 text-start kpi" :class="{ 'kpi-active': status === k.key }" @click="status = k.key">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <span class="avatar avatar-md rounded-3" :class="k.tone"><i :class="k.icon" class="fs-18"></i></span>
                        <div>
                            <div class="fs-20 fw-semibold lh-1">{{ counts[k.key] ?? 0 }}</div>
                            <div class="text-muted fs-12 mt-1">{{ t(`freelance.consultations.status.${k.key}`) }}</div>
                        </div>
                    </div>
                </button>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <FlStatusFilter v-model="status" :options="statusOptions" />
                <div class="input-group input-group-sm catalog-toolbar-search ms-auto">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.consultations.search')">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.consultations.session') }}</th>
                                <th>{{ t('freelance.consultations.client') }}</th>
                                <th>{{ t('freelance.consultations.freelancer') }}</th>
                                <th>{{ t('freelance.consultations.when') }}</th>
                                <th>{{ t('freelance.consultations.total') }}</th>
                                <th>{{ t('freelance.consultations.state') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :layout="['two-lines', 'two-lines', 'two-lines', 'two-lines', 'text', 'badge', 'actions']" />
                            <tr v-else-if="!rows.length"><td colspan="7" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="c in rows" v-else :key="c.uuid" :class="{ 'table-warning': c.status === 'disputed' }">
                                <td class="ps-4">
                                    <span class="d-block fw-semibold text-truncate cell-title">{{ c.title }}</span>
                                    <span class="text-muted fs-11 d-block text-truncate cell-title">{{ c.topic }}</span>
                                </td>
                                <td>
                                    <span class="d-block">{{ c.client?.name || '—' }}</span>
                                    <span class="text-muted fs-11" dir="ltr">{{ c.client?.phone }}</span>
                                </td>
                                <td>
                                    <span class="d-block">{{ c.freelancer?.name || '—' }}</span>
                                    <span class="text-muted fs-11" dir="ltr">{{ c.freelancer?.phone }}</span>
                                </td>
                                <td>
                                    <span class="d-block fs-13">{{ when(c.starts_at) }}</span>
                                    <span class="text-muted fs-11">{{ t('freelance.consultations.minutes_n', { n: c.minutes }) }}</span>
                                </td>
                                <td>{{ money(c.money?.total_minor, c.decimals, c.currency) }}</td>
                                <td>
                                    <span class="badge" :class="badge(c.status)">{{ t(`freelance.consultations.status.${c.status}`) }}</span>
                                    <span v-if="c.status === 'booked'" class="d-block text-muted fs-11 mt-1">{{ t(`freelance.consultations.phase.${c.phase}`) }}</span>
                                    <span v-else-if="c.cancel" class="d-block text-muted fs-11 mt-1">{{ t(`freelance.consultations.cancelled_by.${c.cancel.by}`) }}<template v-if="c.cancel.late"> · {{ t('freelance.consultations.late') }}</template></span>
                                </td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-wave" :class="c.status === 'disputed' && canUpdate ? 'btn-warning' : 'btn-light'" @click="open(c)">
                                        <i :class="c.status === 'disputed' && canUpdate ? 'ri-scales-3-line' : 'ri-eye-line'" class="me-1"></i>{{ c.status === 'disputed' && canUpdate ? t('freelance.consultations.decide') : t('freelance.consultations.open') }}
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

        <!-- One consultation: who, when, the money, what happened, and the decision on a complaint. -->
        <WalletModal :show="!!current" :title="current?.title || ''" size="lg" @close="current = null">
            <div v-if="current">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge" :class="badge(current.status)">{{ t(`freelance.consultations.status.${current.status}`) }}</span>
                    <span class="text-muted fs-12"><i class="ri-calendar-line me-1"></i>{{ when(current.starts_at) }} · {{ t('freelance.consultations.minutes_n', { n: current.minutes }) }}</span>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-muted fs-11 text-uppercase mb-1">{{ t('freelance.consultations.client') }}</div>
                            <div class="fw-semibold">{{ current.client?.name }}</div>
                            <div class="text-muted fs-12" dir="ltr">{{ current.client?.phone }}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-muted fs-11 text-uppercase mb-1">{{ t('freelance.consultations.freelancer') }}</div>
                            <div class="fw-semibold">{{ current.freelancer?.name }}</div>
                            <div class="text-muted fs-12" dir="ltr">{{ current.freelancer?.phone }}</div>
                        </div>
                    </div>
                </div>

                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.consultations.topic') }}</h6>
                <p class="fs-13 pre-line bg-light rounded-3 p-3">{{ current.topic }}</p>

                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.consultations.money') }}</h6>
                <ul class="list-group mb-3 fs-13">
                    <li class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.consultations.price') }}</span><span>{{ money(current.money.price_minor, current.decimals, current.currency) }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.consultations.client_fee') }}</span><span>{{ money(current.money.client_fee_minor, current.decimals, current.currency) }}</span></li>
                    <li class="list-group-item d-flex justify-content-between fw-semibold"><span>{{ t('freelance.consultations.total') }}</span><span>{{ money(current.money.total_minor, current.decimals, current.currency) }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.consultations.commission') }}</span><span>{{ money(current.money.commission_minor, current.decimals, current.currency) }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.consultations.net') }}</span><span>{{ money(current.money.net_minor, current.decimals, current.currency) }}</span></li>
                </ul>

                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.consultations.timeline') }}</h6>
                <ul class="list-unstyled timeline-list fs-13 mb-3">
                    <li v-for="e in events" :key="e.key"><i :class="e.icon" class="me-2"></i>{{ e.text }} <span class="text-muted fs-11 ms-1">{{ when(e.at) }}</span></li>
                </ul>

                <div v-if="current.complaint" class="alert alert-warning fs-13">
                    <div class="fw-semibold mb-1"><i class="ri-error-warning-line me-1"></i>{{ t('freelance.consultations.complaint') }}</div>
                    <div class="pre-line">{{ current.complaint.text }}</div>
                </div>
                <div v-if="current.resolution" class="alert alert-light border fs-13">
                    <div class="fw-semibold mb-1">{{ t(`freelance.consultations.decision.${current.resolution.decision}`) }}</div>
                    <div v-if="current.resolution.note" class="pre-line">{{ current.resolution.note }}</div>
                </div>

                <SessionPanel :uuid="current.uuid" :evidence="current.status === 'disputed'" :can-update="canUpdate" />

                <template v-if="current.can?.resolve && canUpdate">
                    <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.consultations.your_decision') }}</h6>
                    <div class="row g-2 mb-2">
                        <div v-for="d in ['refund', 'pay']" :key="d" class="col-6">
                            <label class="decision border rounded-3 p-3 d-block h-100" :class="{ 'decision-on': decision === d }">
                                <input v-model="decision" type="radio" :value="d" class="form-check-input me-2">
                                <span class="fw-semibold">{{ t(`freelance.consultations.decision.${d}`) }}</span>
                                <span class="d-block text-muted fs-12 mt-1">{{ t(`freelance.consultations.decision_hint.${d}`) }}</span>
                            </label>
                        </div>
                    </div>
                    <textarea v-model="note" class="form-control" rows="2" maxlength="1000" :placeholder="t('freelance.consultations.note_hint')"></textarea>
                </template>
            </div>
            <template v-if="current?.can?.resolve && canUpdate" #footer>
                <button type="button" class="btn btn-light" @click="current = null">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="!decision || deciding" @click="resolve">
                    <span v-if="deciding" class="spinner-border spinner-border-sm me-1"></span>{{ t('freelance.consultations.confirm_decision') }}
                </button>
            </template>
        </WalletModal>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import OffersPanel from './OffersPanel.vue';
import SessionPanel from './SessionPanel.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Paid consultations (FL-L07): every booking, complaints first, and deciding one — pay the freelancer or give the client everything
// back. Money is in minor units of the booking's currency.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const base = '/api/admin/v1/freelance-consultations';
const canUpdate = computed(() => can('freelance-consultations.update'));
const tab = ref('offers');

const STATUSES = ['disputed', 'pending', 'booked', 'completed', 'declined', 'cancelled', 'refunded'];
const kpis = [
    { key: 'disputed', icon: 'ri-scales-3-line', tone: 'bg-warning-transparent' },
    { key: 'pending', icon: 'ri-hourglass-2-line', tone: 'bg-info-transparent' },
    { key: 'booked', icon: 'ri-calendar-check-line', tone: 'bg-primary-transparent' },
    { key: 'completed', icon: 'ri-checkbox-circle-line', tone: 'bg-success-transparent' },
    { key: 'declined', icon: 'ri-user-unfollow-line', tone: 'bg-secondary-transparent' },
    { key: 'cancelled', icon: 'ri-close-circle-line', tone: 'bg-secondary-transparent' },
    { key: 'refunded', icon: 'ri-refund-2-line', tone: 'bg-danger-transparent' },
];

const money = (minor, decimals = 2, currency = '') => (minor == null ? '—' : `${(Number(minor) / 10 ** decimals).toFixed(decimals)} ${currency}`.trim());
const when = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const badge = (s) => ({
    disputed: 'bg-warning-transparent', pending: 'bg-info-transparent', booked: 'bg-primary-transparent', completed: 'bg-success-transparent',
    declined: 'bg-secondary-transparent', cancelled: 'bg-secondary-transparent', refunded: 'bg-danger-transparent',
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
        value: s, label: t(`freelance.consultations.status.${s}`), count: counts.value[s] ?? 0,
        tone: { disputed: 'pending', pending: 'pending', booked: 'active', completed: 'active', declined: 'inactive', cancelled: 'inactive', refunded: 'deleted' }[s],
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

// ---- one consultation
const current = ref(null);
const decision = ref(null);
const note = ref('');
const deciding = ref(false);

function open(c) {
    current.value = c;
    decision.value = null;
    note.value = '';
}

const events = computed(() => {
    const c = current.value;
    if (!c) return [];
    const list = [{ key: 'requested', icon: 'ri-hand-coin-line text-primary', text: t('freelance.consultations.ev.requested'), at: c.created_at }];
    if (c.accepted_at) list.push({ key: 'accepted', icon: 'ri-calendar-check-line text-primary', text: t('freelance.consultations.ev.accepted'), at: c.accepted_at });
    if (c.cancel) {
        const why = c.cancel.reason && ['expired', 'taken'].includes(c.cancel.reason) ? t(`freelance.consultations.system_reason.${c.cancel.reason}`) : c.cancel.reason;
        const text = c.status === 'declined' ? t(`freelance.consultations.declined_by.${c.cancel.by}`) : t(`freelance.consultations.cancelled_by.${c.cancel.by}`);
        list.push({ key: 'cancelled', icon: 'ri-close-circle-line text-secondary', text: text + (why ? ` — ${why}` : ''), at: c.cancel.at });
    }
    if (c.confirmed_at) list.push({ key: 'confirmed', icon: 'ri-thumb-up-line text-success', text: t('freelance.consultations.ev.confirmed'), at: c.confirmed_at });
    if (c.complaint) list.push({ key: 'complained', icon: 'ri-error-warning-line text-warning', text: t('freelance.consultations.ev.complained'), at: c.complaint.at });
    if (c.resolution) list.push({ key: 'resolved', icon: 'ri-scales-3-line text-info', text: t(`freelance.consultations.decision.${c.resolution.decision}`), at: c.resolution.at });
    if (c.settled_at) list.push({ key: 'settled', icon: 'ri-money-dollar-circle-line text-success', text: t('freelance.consultations.ev.settled'), at: c.settled_at });
    if (c.refunded_at) list.push({ key: 'refunded', icon: 'ri-refund-2-line text-danger', text: t('freelance.consultations.ev.refunded'), at: c.refunded_at });
    return list;
});

async function resolve() {
    deciding.value = true;
    try {
        const response = await adminAxios.post(`${base}/${current.value.uuid}/resolve`, { decision: decision.value, note: note.value.trim() || undefined });
        showSuccess(extractApiMessage(response, t('toast.success')));
        current.value = response.data.data;
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

.kpi {
    border: 1px solid transparent;
    transition: border-color 0.15s ease, transform 0.15s ease;
}

.kpi:hover {
    transform: translateY(-1px);
}

.kpi-active {
    border-color: var(--primary-color, #845adf);
}

.timeline-list li {
    padding: 4px 0;
}

.decision {
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}

.decision-on {
    border-color: var(--primary-color, #845adf) !important;
    background: rgba(132, 90, 223, 0.06);
}
</style>
