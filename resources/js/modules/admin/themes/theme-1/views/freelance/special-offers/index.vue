<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.specialOffers.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.specialOffers.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border fs-13">{{ t('freelance.specialOffers.hint') }}</div>

        <div class="row g-3 mb-3">
            <div v-for="k in kpis" :key="k.key" class="col-6 col-lg">
                <button type="button" class="card custom-card mb-0 w-100 text-start kpi" :class="{ 'kpi-active': status === k.key }" @click="status = k.key">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <span class="avatar avatar-md rounded-3" :class="k.tone"><i :class="k.icon" class="fs-18"></i></span>
                        <div>
                            <div class="fs-20 fw-semibold lh-1">{{ counts[k.key] ?? 0 }}</div>
                            <div class="text-muted fs-12 mt-1">{{ t(`freelance.specialOffers.status.${k.key}`) }}</div>
                        </div>
                    </div>
                </button>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <FlStatusFilter v-model="status" :options="statusOptions" />
                <FlStatusFilter v-model="kind" :options="kindOptions" />
                <div class="input-group input-group-sm catalog-toolbar-search ms-auto">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.specialOffers.search')">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.specialOffers.offer') }}</th>
                                <th>{{ t('freelance.specialOffers.freelancer') }}</th>
                                <th>{{ t('freelance.specialOffers.client') }}</th>
                                <th>{{ t('freelance.specialOffers.price') }}</th>
                                <th>{{ t('freelance.specialOffers.state') }}</th>
                                <th>{{ t('freelance.specialOffers.sent') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :layout="['two-lines', 'text', 'two-lines', 'two-lines', 'badge', 'text', 'actions']" />
                            <tr v-else-if="!rows.length"><td colspan="7" class="text-center text-muted py-5">{{ t('freelance.specialOffers.empty') }}</td></tr>
                            <tr v-for="o in rows" v-else :key="o.uuid">
                                <td class="ps-4">
                                    <span class="d-block fw-semibold text-truncate cell-title">{{ o.title }}</span>
                                    <span class="badge bg-light text-default fs-10">{{ o.kind_label }}</span>
                                </td>
                                <td>{{ o.freelancer?.name || '—' }}</td>
                                <td>
                                    <span class="d-block">{{ o.client?.name || '—' }}</span>
                                    <span class="text-muted fs-11" dir="ltr">{{ o.client?.phone }}</span>
                                </td>
                                <td>
                                    <span class="d-block">{{ headline(o) }}</span>
                                    <span v-if="o.list_price_minor != null" class="text-muted fs-11 text-decoration-line-through">{{ money(o.list_price_minor, o.decimals, o.currency) }}</span>
                                </td>
                                <td>
                                    <span class="badge" :class="badge(o.status)">{{ o.status_label }}</span>
                                    <span v-if="o.viewed_at" class="d-block text-muted fs-11 mt-1"><i class="ri-eye-line me-1"></i>{{ t('freelance.specialOffers.seen') }}</span>
                                </td>
                                <td class="fs-12">{{ when(o.sent_at) }}</td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-light btn-wave" @click="current = o"><i class="ri-eye-line me-1"></i>{{ t('freelance.specialOffers.open') }}</button>
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

        <!-- One offer: what was offered, the money, and what came of it. -->
        <WalletModal :show="!!current" :title="current?.title || ''" size="lg" @close="current = null">
            <div v-if="current">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge" :class="badge(current.status)">{{ current.status_label }}</span>
                    <span class="badge bg-light text-default">{{ current.kind_label }}</span>
                    <span class="text-muted fs-12"><i class="ri-timer-line me-1"></i>{{ t('freelance.specialOffers.expires') }}: {{ when(current.expires_at) }}</span>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-muted fs-11 text-uppercase mb-1">{{ t('freelance.specialOffers.freelancer') }}</div>
                            <div class="fw-semibold">{{ current.freelancer?.name }}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-muted fs-11 text-uppercase mb-1">{{ t('freelance.specialOffers.client') }}</div>
                            <div class="fw-semibold">{{ current.client?.name }}</div>
                            <div class="text-muted fs-12" dir="ltr">{{ current.client?.phone }}</div>
                        </div>
                    </div>
                </div>

                <template v-if="current.description">
                    <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.specialOffers.description') }}</h6>
                    <p class="fs-13 pre-line bg-light rounded-3 p-3">{{ current.description }}</p>
                </template>
                <template v-if="current.message">
                    <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.specialOffers.message') }}</h6>
                    <p class="fs-13 pre-line bg-light rounded-3 p-3">{{ current.message }}</p>
                </template>

                <template v-if="current.service">
                    <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.specialOffers.service') }}</h6>
                    <p class="fs-13">{{ current.service.title }} · {{ current.service.level_label }}<span v-if="current.service.tier_name"> · {{ current.service.tier_name }}</span></p>
                </template>

                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.specialOffers.money') }}</h6>
                <ul v-if="current.consultation" class="list-group mb-3 fs-13">
                    <li class="list-group-item fw-semibold">{{ current.consultation.title }} · {{ t('freelance.specialOffers.discount_n', { n: percent(current.discount_bp) }) }}</li>
                    <li v-for="l in current.consultation.lengths" :key="l.minutes" class="list-group-item d-flex justify-content-between">
                        <span>{{ t('freelance.specialOffers.minutes_n', { n: l.minutes }) }}</span>
                        <span><span class="text-muted text-decoration-line-through me-2">{{ money(l.list_price_minor, current.decimals, current.currency) }}</span>{{ money(l.price_minor, current.decimals, current.currency) }}</span>
                    </li>
                </ul>
                <ul v-else class="list-group mb-3 fs-13">
                    <li class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.specialOffers.price') }}</span><span>{{ money(current.price_minor, current.decimals, current.currency) }}</span></li>
                    <li v-if="current.list_price_minor != null" class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.specialOffers.usual') }}</span><span class="text-decoration-line-through">{{ money(current.list_price_minor, current.decimals, current.currency) }}</span></li>
                    <template v-if="current.money">
                        <li class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.specialOffers.client_fee') }}</span><span>{{ money(current.money.client_fee_minor, current.decimals, current.currency) }}</span></li>
                        <li class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.specialOffers.commission') }}</span><span>{{ money(current.money.commission_minor, current.decimals, current.currency) }}</span></li>
                        <li class="list-group-item d-flex justify-content-between fw-semibold"><span>{{ t('freelance.specialOffers.net') }}</span><span>{{ money(current.money.net_minor, current.decimals, current.currency) }}</span></li>
                    </template>
                    <li v-if="current.delivery_days" class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.specialOffers.delivery') }}</span><span>{{ t('freelance.specialOffers.days_n', { n: current.delivery_days }) }}</span></li>
                </ul>

                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.specialOffers.timeline') }}</h6>
                <ul class="list-unstyled fs-13 mb-0">
                    <li class="py-1"><i class="ri-send-plane-line text-primary me-2"></i>{{ t('freelance.specialOffers.ev.sent') }} <span class="text-muted fs-11 ms-1">{{ when(current.sent_at) }}</span></li>
                    <li v-if="current.viewed_at" class="py-1"><i class="ri-eye-line text-info me-2"></i>{{ t('freelance.specialOffers.ev.viewed') }} <span class="text-muted fs-11 ms-1">{{ when(current.viewed_at) }}</span></li>
                    <li v-if="current.answered_at" class="py-1"><i class="ri-reply-line text-success me-2"></i>{{ current.status_label }} <span class="text-muted fs-11 ms-1">{{ when(current.answered_at) }}</span></li>
                    <li v-if="current.decline_reason" class="py-1 text-muted"><i class="ri-chat-quote-line me-2"></i>{{ current.decline_reason }}</li>
                </ul>
            </div>
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
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';

// Special offers (FL-L09 part A), read only: who offered whom what, at which price, and what came of it — to spot a freelancer pressing
// clients. The limits are in Prices & rules → Special offers. Money is in minor units of the offer's currency.
const { t, locale } = useI18n();
const { showError } = useToast();
const base = '/api/admin/v1/freelance-special-offers';

const STATUSES = ['sent', 'accepted', 'declined', 'withdrawn', 'expired'];
const KINDS = ['custom', 'service', 'consultation'];
const kpis = [
    { key: 'sent', icon: 'ri-send-plane-line', tone: 'bg-info-transparent' },
    { key: 'accepted', icon: 'ri-checkbox-circle-line', tone: 'bg-success-transparent' },
    { key: 'declined', icon: 'ri-close-circle-line', tone: 'bg-danger-transparent' },
    { key: 'withdrawn', icon: 'ri-arrow-go-back-line', tone: 'bg-secondary-transparent' },
    { key: 'expired', icon: 'ri-timer-line', tone: 'bg-warning-transparent' },
];

const percent = (bp) => (bp == null ? '' : bp % 100 === 0 ? String(bp / 100) : (bp / 100).toFixed(1));
const money = (minor, decimals = 2, currency = '') => (minor == null ? '—' : `${(Number(minor) / 10 ** decimals).toFixed(decimals)} ${currency ?? ''}`.trim());
const when = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const badge = (s) => ({ sent: 'bg-info-transparent', accepted: 'bg-success-transparent', declined: 'bg-danger-transparent', withdrawn: 'bg-secondary-transparent', expired: 'bg-warning-transparent' }[s] ?? 'bg-light text-default');
/** A consultation is a discount on every length; the others carry one price. */
const headline = (o) => (o.kind === 'consultation' ? t('freelance.specialOffers.discount_n', { n: percent(o.discount_bp) }) : money(o.price_minor, o.decimals, o.currency));

const rows = ref([]);
const pagination = ref({});
const counts = ref({});
const pageNo = ref(1);
const loading = ref(false);
const status = ref('all');
const kind = ref('all');
const search = ref('');
const current = ref(null);

const statusOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), count: Object.values(counts.value).reduce((a, b) => a + b, 0), tone: 'all' },
    ...STATUSES.map((s) => ({ value: s, label: t(`freelance.specialOffers.status.${s}`), count: counts.value[s] ?? 0, tone: { sent: 'pending', accepted: 'active', declined: 'deleted', withdrawn: 'inactive', expired: 'inactive' }[s] })),
]);
const kindOptions = computed(() => [
    { value: 'all', label: t('freelance.specialOffers.all_kinds'), tone: 'all' },
    ...KINDS.map((k) => ({ value: k, label: t(`freelance.specialOffers.kind.${k}`), tone: 'all' })),
]);

let timer = null;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => (pageNo.value === 1 ? load() : (pageNo.value = 1)), 300); });
watch([status, kind], () => (pageNo.value === 1 ? load() : (pageNo.value = 1)));
watch(pageNo, load);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(base, {
            params: { page: pageNo.value, search: search.value || undefined, status: status.value === 'all' ? undefined : status.value, kind: kind.value === 'all' ? undefined : kind.value },
        });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
        counts.value = data.counts ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
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
</style>
