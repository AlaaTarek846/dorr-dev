<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.services.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.services.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border fs-13">{{ t('freelance.services.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-3 py-3">
                <FlStatusFilter v-model="tab" :options="tabOptions" />
            </div>

            <!-- The services: the queue first. -->
            <template v-if="tab === 'services'">
                <div class="card-body border-bottom py-3 d-flex align-items-center flex-wrap gap-2">
                    <FlStatusFilter v-model="status" :options="statusOptions" />
                    <div class="input-group input-group-sm catalog-toolbar-search ms-auto">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.services.search')">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ t('freelance.services.service') }}</th>
                                    <th>{{ t('freelance.services.freelancer') }}</th>
                                    <th>{{ t('freelance.services.starting_price') }}</th>
                                    <th>{{ t('freelance.services.status') }}</th>
                                    <th>{{ t('freelance.services.submitted_at') }}</th>
                                    <th class="text-end pe-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <TableSkeleton v-if="loading" :rows="6" :layout="['two-lines', 'two-lines', 'text', 'badge', 'text', 'actions']" />
                                <tr v-else-if="!services.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                                <tr v-for="s in services" v-else :key="s.uuid">
                                    <td class="ps-4">
                                        <span class="d-block fw-semibold text-truncate service-title">{{ s.title }}<span v-if="s.billing === 'monthly'" class="badge bg-info-transparent ms-2">{{ t('freelance.services.monthly') }}</span></span>
                                        <span class="text-muted fs-11">{{ s.specialty || '—' }} · {{ t('freelance.services.levels_n', { n: s.levels }) }} · {{ s.country }}</span>
                                    </td>
                                    <td>
                                        <span class="d-block">{{ s.freelancer?.name || '—' }}</span>
                                        <span class="text-muted fs-11" dir="ltr">{{ s.freelancer?.phone }}</span>
                                    </td>
                                    <td>{{ money(s.starting_price_minor, s.decimals, s.currency) }}</td>
                                    <td><span class="badge" :class="badge(s.status)">{{ s.status_label }}</span></td>
                                    <td class="fs-12">{{ s.submitted_at ? formatCatalogDate(s.submitted_at, locale) : '—' }}</td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-light btn-wave" @click="open(s.uuid)"><i class="ri-eye-line me-1"></i>{{ t('freelance.services.open') }}</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <PagerFooter v-if="!loading && services.length" :pagination="pagination" :page="pageNo" @prev="pageNo -= 1" @next="pageNo += 1" />
            </template>

            <!-- The orders placed on services. -->
            <template v-else-if="tab === 'orders'">
                <div class="card-body border-bottom py-3 d-flex align-items-center flex-wrap gap-2">
                    <FlStatusFilter v-model="orderStatus" :options="orderStatusOptions" />
                    <div class="input-group input-group-sm catalog-toolbar-search ms-auto">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="orderSearch" type="search" class="form-control" :placeholder="t('freelance.services.search_orders')">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ t('freelance.services.service') }}</th>
                                    <th>{{ t('freelance.services.client') }}</th>
                                    <th>{{ t('freelance.services.freelancer') }}</th>
                                    <th>{{ t('freelance.services.price') }}</th>
                                    <th>{{ t('freelance.services.commission') }}</th>
                                    <th>{{ t('freelance.services.status') }}</th>
                                    <th class="pe-4">{{ t('freelance.services.ordered_at') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <TableSkeleton v-if="orderLoading" :rows="6" :layout="['two-lines', 'two-lines', 'text', 'text', 'text', 'badge', 'text']" />
                                <tr v-else-if="!orders.length"><td colspan="7" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                                <tr v-for="o in orders" v-else :key="o.uuid">
                                    <td class="ps-4">
                                        <span class="d-block fw-semibold text-truncate service-title">{{ o.service?.title }}</span>
                                        <span class="text-muted fs-11">{{ o.level_label }} · {{ t('freelance.services.days_n', { n: o.delivery_days }) }}<template v-if="o.addons.length"> · {{ t('freelance.services.extras_n', { n: o.addons.length }) }}</template></span>
                                    </td>
                                    <td>
                                        <span class="d-block">{{ o.client?.name || '—' }}</span>
                                        <span class="text-muted fs-11" dir="ltr">{{ o.client?.phone }}</span>
                                    </td>
                                    <td>{{ o.freelancer?.name || '—' }}</td>
                                    <td>{{ money(o.price_minor, o.decimals, o.currency) }}</td>
                                    <td>{{ money(o.money?.commission_minor ?? 0, o.decimals, o.currency) }}</td>
                                    <td><span class="badge" :class="orderBadge(o.status)">{{ t(`freelance.services.order_status.${o.status}`) }}</span></td>
                                    <td class="pe-4 fs-12">{{ formatCatalogDate(o.created_at, locale) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <PagerFooter v-if="!orderLoading && orders.length" :pagination="orderPage" :page="orderPageNo" @prev="orderPageNo -= 1" @next="orderPageNo += 1" />
            </template>

            <!-- FL-L05: the monthly subscriptions. -->
            <template v-else>
                <div class="card-body border-bottom py-3 d-flex align-items-center flex-wrap gap-2">
                    <FlStatusFilter v-model="subStatus" :options="subStatusOptions" />
                    <div class="input-group input-group-sm catalog-toolbar-search ms-auto">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="subSearch" type="search" class="form-control" :placeholder="t('freelance.services.search_orders')">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ t('freelance.services.service') }}</th>
                                    <th>{{ t('freelance.services.client') }}</th>
                                    <th>{{ t('freelance.services.freelancer') }}</th>
                                    <th>{{ t('freelance.services.per_month') }}</th>
                                    <th>{{ t('freelance.services.month') }}</th>
                                    <th>{{ t('freelance.services.status') }}</th>
                                    <th class="pe-4">{{ t('freelance.services.month_ends') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <TableSkeleton v-if="subLoading" :rows="6" :layout="['two-lines', 'two-lines', 'text', 'text', 'text', 'badge', 'two-lines']" />
                                <tr v-else-if="!subs.length"><td colspan="7" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                                <tr v-for="s in subs" v-else :key="s.uuid">
                                    <td class="ps-4">
                                        <span class="d-block fw-semibold text-truncate service-title">{{ s.service?.title }}</span>
                                        <span class="text-muted fs-11">{{ s.level_label }} · {{ t('freelance.services.days_n', { n: s.delivery_days }) }}</span>
                                    </td>
                                    <td>
                                        <span class="d-block">{{ s.client?.name || '—' }}</span>
                                        <span class="text-muted fs-11" dir="ltr">{{ s.client?.phone }}</span>
                                    </td>
                                    <td>{{ s.freelancer?.name || '—' }}</td>
                                    <td>{{ money(s.price_minor, s.decimals, s.currency) }}</td>
                                    <td>{{ s.cycle }}</td>
                                    <td>
                                        <span class="badge" :class="subBadge(s.status)">{{ t(`freelance.services.sub_status.${s.status}`) }}</span>
                                        <span v-if="s.end_reason" class="d-block text-muted fs-11 mt-1">{{ t(`freelance.services.sub_end.${s.end_reason}`) }}</span>
                                        <span v-else-if="s.cancel" class="d-block text-warning fs-11 mt-1">{{ t('freelance.services.sub_ending', { by: t(`freelance.services.side.${s.cancel.by}`), date: formatCatalogDate(s.cancel.ends_at, locale) }) }}</span>
                                    </td>
                                    <td class="pe-4 fs-12">{{ s.period_ends_at ? formatCatalogDate(s.period_ends_at, locale) : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <PagerFooter v-if="!subLoading && subs.length" :pagination="subPage" :page="subPageNo" @prev="subPageNo -= 1" @next="subPageNo += 1" />
            </template>
        </div>

        <!-- One service: what clients would see, and the decision. -->
        <WalletModal :show="!!current" :title="current?.title || ''" size="xl" @close="close">
            <div v-if="current">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge" :class="badge(current.status)">{{ current.status_label }}</span>
                    <span class="text-muted fs-12">{{ current.freelancer?.name }} · <span dir="ltr">{{ current.freelancer?.phone }}</span> · {{ current.country }}</span>
                    <span v-if="current.specialty" class="badge bg-light text-default">{{ current.specialty.name }}</span>
                </div>
                <div v-if="current.status_reason" class="alert alert-warning py-2 fs-13">{{ t('freelance.services.reason') }}: {{ current.status_reason }}</div>

                <div v-if="images.length" class="d-flex flex-wrap gap-2 mb-3">
                    <img v-for="img in images" :key="img.id" :src="img.src" :alt="img.name" class="rounded border service-image">
                </div>
                <p class="fs-13 service-text">{{ current.description }}</p>
                <p v-if="current.requirements" class="fs-13 mb-3"><i class="ri-question-line me-1 text-primary"></i>{{ t('freelance.services.asks') }}: {{ current.requirements }}</p>

                <div class="row g-3 mb-3">
                    <div v-for="tier in current.tiers" :key="tier.level" class="col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex align-items-start justify-content-between mb-1">
                                <span class="fw-semibold">{{ tier.name }}</span>
                                <span class="badge bg-primary-transparent">{{ tier.level_label }}</span>
                            </div>
                            <div class="fs-18 fw-semibold mb-1">{{ money(tier.price_minor, current.decimals, current.currency) }}</div>
                            <div class="text-muted fs-12 mb-2">{{ t('freelance.services.days_n', { n: tier.delivery_days }) }} · {{ t('freelance.services.revisions_n', { n: tier.revisions }) }}</div>
                            <p v-if="tier.description" class="fs-12 mb-2">{{ tier.description }}</p>
                            <ul class="list-unstyled fs-12 mb-0">
                                <li v-for="(f, i) in tier.features" :key="i"><i class="ri-check-line text-success me-1"></i>{{ f }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div v-if="current.addons.length" class="mb-3">
                    <h6 class="fw-semibold fs-14">{{ t('freelance.services.addons') }}</h6>
                    <ul class="list-group">
                        <li v-for="a in current.addons" :key="a.id" class="list-group-item d-flex justify-content-between fs-13">
                            <span>{{ a.title }}<span v-if="a.extra_days" class="text-muted"> · +{{ t('freelance.services.days_n', { n: a.extra_days }) }}</span></span>
                            <span class="fw-semibold">{{ money(a.price_minor, current.decimals, current.currency) }}</span>
                        </li>
                    </ul>
                </div>

                <div class="text-muted fs-12">
                    {{ t('freelance.services.orders_summary', { awaiting: current.orders?.awaiting ?? 0, active: current.orders?.active ?? 0, completed: current.orders?.completed ?? 0 }) }}
                </div>

                <div v-if="canUpdate && needsReason" class="mt-3">
                    <label class="form-label">{{ t('freelance.services.reason') }} <span class="text-danger">*</span></label>
                    <textarea v-model="reason" class="form-control" rows="2" maxlength="500" :placeholder="t('freelance.services.reason_hint')"></textarea>
                </div>
            </div>
            <template v-if="current && canUpdate" #footer>
                <button type="button" class="btn btn-light" @click="close">{{ t('cancel') }}</button>
                <template v-if="needsReason">
                    <button type="button" class="btn btn-light" @click="pending = null">{{ t('freelance.services.back') }}</button>
                    <button type="button" class="btn btn-danger" :disabled="deciding || reason.trim().length < 5" @click="decide(pending)">
                        <span v-if="deciding" class="spinner-border spinner-border-sm me-1"></span>{{ t(`freelance.services.do.${pending}`) }}
                    </button>
                </template>
                <template v-else>
                    <button v-if="current.status === 'suspended'" type="button" class="btn btn-outline-primary" :disabled="deciding" @click="decide('unsuspend')">{{ t('freelance.services.do.unsuspend') }}</button>
                    <button v-if="current.status !== 'suspended'" type="button" class="btn btn-outline-danger" :disabled="deciding" @click="pending = 'suspend'">{{ t('freelance.services.do.suspend') }}</button>
                    <template v-if="current.status === 'pending_review'">
                        <button type="button" class="btn btn-outline-warning" :disabled="deciding" @click="pending = 'reject'">{{ t('freelance.services.do.reject') }}</button>
                        <button type="button" class="btn btn-success" :disabled="deciding" @click="decide('approve')">
                            <span v-if="deciding" class="spinner-border spinner-border-sm me-1"></span>{{ t('freelance.services.do.approve') }}
                        </button>
                    </template>
                </template>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

// Ready-made services (FL-L04): the review queue (the oldest waiting first), one service as clients would see it with the decision, and
// the orders placed on services. Prices are minor units of the service's currency, shown with that currency's decimals.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const base = '/api/admin/v1/freelance-services';
const canUpdate = computed(() => can('freelance-services.update'));

const PagerFooter = defineComponent({
    props: { pagination: { type: Object, default: () => ({}) }, page: { type: Number, default: 1 } },
    emits: ['prev', 'next'],
    setup(props, { emit }) {
        return () => h('div', { class: 'card-footer border-top-0 d-flex align-items-center flex-wrap gap-3' }, [
            h('div', { class: 'text-muted fs-13' }, t('countries.showing_entries', { from: props.pagination.from ?? 0, to: props.pagination.to ?? 0, total: props.pagination.total ?? 0 })),
            (props.pagination.last_page ?? 1) > 1 && h('nav', { class: 'pagination-style-4 ms-md-auto' }, h('ul', { class: 'pagination mb-0' }, [
                h('li', { class: ['page-item', { disabled: props.page <= 1 }] }, h('button', { type: 'button', class: 'page-link', onClick: () => emit('prev') }, t('countries.previous'))),
                h('li', { class: 'page-item active' }, h('span', { class: 'page-link' }, props.page)),
                h('li', { class: ['page-item', { disabled: props.page >= props.pagination.last_page }] }, h('button', { type: 'button', class: 'page-link text-primary', onClick: () => emit('next') }, t('countries.next'))),
            ])),
        ]);
    },
});

const money = (minor, decimals = 2, currency = '') => (minor == null ? '—' : `${(Number(minor) / 10 ** decimals).toFixed(decimals)} ${currency}`.trim());
const badge = (s) => ({
    pending_review: 'bg-warning-transparent', published: 'bg-success-transparent', paused: 'bg-secondary-transparent',
    rejected: 'bg-danger-transparent', suspended: 'bg-dark-transparent',
}[s] ?? 'bg-light text-default');
const orderBadge = (s) => ({ awaiting: 'bg-warning-transparent', active: 'bg-primary-transparent', completed: 'bg-success-transparent' }[s] ?? 'bg-secondary-transparent');

const tab = ref('services');
const tabOptions = computed(() => [
    { value: 'services', label: t('freelance.services.tabs.services'), count: counts.value.pending_review || undefined, tone: 'all' },
    { value: 'orders', label: t('freelance.services.tabs.orders'), tone: 'all' },
    { value: 'subscriptions', label: t('freelance.services.tabs.subscriptions'), tone: 'all' },
]);

// ---- the services
const services = ref([]);
const pagination = ref({});
const pageNo = ref(1);
const loading = ref(false);
const status = ref('pending_review');
const search = ref('');
const counts = ref({});
const statusOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), count: Object.values(counts.value).reduce((a, b) => a + b, 0), tone: 'all' },
    ...['pending_review', 'published', 'paused', 'rejected', 'suspended'].map((s) => ({
        value: s, label: t(`freelance.services.status_label.${s}`), count: counts.value[s] ?? 0,
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
        services.value = data.data?.services ?? [];
        counts.value = data.data?.counts ?? {};
        pagination.value = data.pagination ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

// ---- one service
const current = ref(null);
const images = ref([]);
const pending = ref(null);
const reason = ref('');
const deciding = ref(false);
const needsReason = computed(() => pending.value === 'reject' || pending.value === 'suspend');

async function open(uuid) {
    try {
        const { data } = await adminAxios.get(`${base}/${uuid}`);
        current.value = data.data;
        pending.value = null;
        reason.value = '';
        loadImages();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

// The images come through the signed-in endpoint (never a public link), so they are read as files.
async function loadImages() {
    images.value.forEach((i) => URL.revokeObjectURL(i.src));
    images.value = [];
    for (const img of current.value?.images ?? []) {
        try {
            const { data } = await adminAxios.get(`/api/admin/v1/${img.path}`, { responseType: 'blob' });
            images.value.push({ id: img.id, name: img.name, src: URL.createObjectURL(data) });
        } catch {
            // a missing file is just not shown
        }
    }
}

function close() {
    images.value.forEach((i) => URL.revokeObjectURL(i.src));
    images.value = [];
    current.value = null;
}

async function decide(action) {
    deciding.value = true;
    try {
        const response = await adminAxios.post(`${base}/${current.value.uuid}/${action}`, needsReason.value ? { reason: reason.value.trim() } : {});
        showSuccess(extractApiMessage(response, t('toast.success')));
        current.value = { ...current.value, ...response.data.data };
        pending.value = null;
        reason.value = '';
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        deciding.value = false;
    }
}

// ---- the orders
const orders = ref([]);
const orderPage = ref({});
const orderPageNo = ref(1);
const orderLoading = ref(false);
const orderStatus = ref('all');
const orderSearch = ref('');
const orderStatusOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), tone: 'all' },
    ...['awaiting', 'active', 'completed', 'ended'].map((s) => ({ value: s, label: t(`freelance.services.order_filter.${s}`), tone: { awaiting: 'pending', active: 'active', completed: 'active', ended: 'inactive' }[s] })),
]);

let orderTimer = null;
watch(orderSearch, () => { clearTimeout(orderTimer); orderTimer = setTimeout(() => (orderPageNo.value === 1 ? loadOrders() : (orderPageNo.value = 1)), 300); });
watch(orderStatus, () => (orderPageNo.value === 1 ? loadOrders() : (orderPageNo.value = 1)));
watch(orderPageNo, loadOrders);
watch(tab, (v) => ({ orders: loadOrders, subscriptions: loadSubs }[v] ?? load)());

async function loadOrders() {
    orderLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/orders`, { params: { page: orderPageNo.value, search: orderSearch.value || undefined, status: orderStatus.value === 'all' ? undefined : orderStatus.value } });
        orders.value = data.data ?? [];
        orderPage.value = data.pagination ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        orderLoading.value = false;
    }
}

// ---- FL-L05: the monthly subscriptions
const subs = ref([]);
const subPage = ref({});
const subPageNo = ref(1);
const subLoading = ref(false);
const subStatus = ref('all');
const subSearch = ref('');
const subStatusOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), tone: 'all' },
    ...['active', 'pending', 'ended'].map((s) => ({ value: s, label: t(`freelance.services.sub_status.${s}`), tone: { active: 'active', pending: 'pending', ended: 'inactive' }[s] })),
]);
const subBadge = (s) => ({ pending: 'bg-warning-transparent', active: 'bg-primary-transparent' }[s] ?? 'bg-secondary-transparent');

let subTimer = null;
watch(subSearch, () => { clearTimeout(subTimer); subTimer = setTimeout(() => (subPageNo.value === 1 ? loadSubs() : (subPageNo.value = 1)), 300); });
watch(subStatus, () => (subPageNo.value === 1 ? loadSubs() : (subPageNo.value = 1)));
watch(subPageNo, loadSubs);

async function loadSubs() {
    subLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/subscriptions`, { params: { page: subPageNo.value, search: subSearch.value || undefined, status: subStatus.value === 'all' ? undefined : subStatus.value } });
        subs.value = data.data ?? [];
        subPage.value = data.pagination ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        subLoading.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.service-title {
    max-width: 280px;
}

.service-image {
    width: 140px;
    height: 100px;
    object-fit: cover;
}

.service-text {
    white-space: pre-line;
}
</style>
