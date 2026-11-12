<template>
    <div>
        <WalletPageHeader :title="t('moving.requests.title')" :section="t('sidebar.moving')" :total="pagination?.total ?? null" />

        <!-- The month's money: settled jobs, what establishments got, Dorr's commission, refunds. -->
        <div class="row">
            <div v-for="row in summary.settled" :key="row.currency_code" class="col-xl-3 col-md-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <div class="text-muted fs-12 mb-1">{{ t('moving.requests.settled_this_month') }} · {{ row.currency_code }}</div>
                        <h5 class="fw-semibold mb-1">{{ money(row.total_minor, row.currency_code) }}</h5>
                        <div class="fs-12 text-muted">{{ row.jobs }} {{ t('moving.requests.jobs') }} · {{ t('moving.requests.commission') }} {{ money(row.commission_minor, row.currency_code) }}</div>
                    </div>
                </div>
            </div>
            <div v-if="Object.keys(summary.by_status).length" class="col-xl-3 col-md-6">
                <div class="card custom-card">
                    <div class="card-body d-flex flex-wrap gap-1">
                        <span v-for="(count, status) in summary.by_status" :key="status" class="badge" :class="statusClass(status)">{{ t(`moving.request_status.${status}`) }} {{ count }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="filters.search" type="search" class="form-control" :placeholder="t('moving.requests.search')">
                        <button v-if="filters.search" type="button" class="btn btn-light border catalog-search-clear" :title="t('wallet.common.clear_search')" @click="filters.search = ''">
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                    <Select
                        v-model="filters.status"
                        :options="statusOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('moving.requests.all_statuses')"
                        show-clear
                        class="wallet-filter-select"
                    />
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('moving.requests.route') }}</th>
                                <th>{{ t('moving.requests.customer') }}</th>
                                <th>{{ t('moving.requests.establishment') }}</th>
                                <th>{{ t('moving.requests.time') }}</th>
                                <th>{{ t('moving.requests.total') }}</th>
                                <th>{{ t('moving.establishments.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :layout="['two-lines', 'two-lines', 'text', 'text', 'text', 'badge']" />
                            <tr v-else-if="!rows.length">
                                <td colspan="6" class="text-center text-muted py-5"><i class="ri-truck-line fs-24 d-block mb-1"></i>{{ t('wallet.common.empty') }}</td>
                            </tr>
                            <tr v-for="row in rows" v-else :key="row.uuid" role="button" @click="open(row)">
                                <td class="ps-4">
                                    <div class="fw-semibold">{{ row.pickup_area || '—' }} <i :class="arrowIcon" class="mx-1 text-muted"></i> {{ row.dropoff_area || '—' }}</div>
                                    <code class="fs-11 text-muted">{{ row.uuid.slice(0, 8).toUpperCase() }}</code>
                                </td>
                                <td><div>{{ row.customer?.name || '—' }}</div><div class="fs-12 text-muted" dir="ltr">{{ row.customer?.phone }}</div></td>
                                <td>{{ row.establishment || '—' }}</td>
                                <td class="fs-12">{{ formatDateTime(row.scheduled_at) }}</td>
                                <td>{{ money(row.total_minor, row.currency_code) }}</td>
                                <td><span class="badge" :class="statusClass(row.status)">{{ t(`moving.request_status.${row.status}`) }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <WalletModal :show="!!detail" :title="detail ? `${detail.request.pickup.area || '—'} ${arrow} ${detail.request.dropoff.area || '—'}` : ''" size="xl" smooth @close="detail = null">
            <template v-if="detail">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="border rounded-3 p-3 fs-13 mb-3">
                            <div v-for="line in moneyLines" :key="line.key" class="d-flex justify-content-between mb-1">
                                <span class="text-muted">{{ t(`moving.requests.${line.key}`) }}</span><b>{{ money(line.value, detail.request.currency_code) }}</b>
                            </div>
                        </div>
                        <div class="border rounded-3 p-3 fs-13">
                            <div><b>{{ detail.customer.name || '—' }}</b> <span class="text-muted" dir="ltr">{{ detail.customer.phone }}</span></div>
                            <div class="mt-1">{{ detail.request.establishment?.name || '—' }} · {{ detail.request.driver?.name || '—' }}</div>
                            <div class="text-muted" dir="ltr">{{ [detail.request.vehicle?.plate_letters, detail.request.vehicle?.plate_number].filter(Boolean).join(' ') }}</div>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <ul class="nav nav-tabs mb-2">
                            <li v-for="tab in ['timeline', 'offers', 'changes', 'inventory', 'tickets']" :key="tab" class="nav-item">
                                <a href="#" class="nav-link py-1 fs-13" :class="{ active: detailTab === tab }" @click.prevent="detailTab = tab">{{ t(`moving.requests.tabs.${tab}`) }}</a>
                            </li>
                        </ul>
                        <div v-if="!tabRows.length" class="text-center text-muted fs-13 py-4">{{ t('wallet.common.empty') }}</div>
                        <ul v-else-if="detailTab === 'timeline'" class="list-unstyled fs-12 mb-0" style="max-height: 320px; overflow-y: auto">
                            <li v-for="(e, i) in detail.events" :key="i" class="border-start border-2 ps-2 mb-2">
                                <b>{{ eventLabel(e.action) }}</b>
                                <span v-if="e.to_status" class="badge ms-1" :class="statusClass(e.to_status)">{{ t(`moving.request_status.${e.to_status}`) }}</span>
                                <span class="text-muted"> · {{ actorLabel(e.actor_type) }} · {{ formatDateTime(e.created_at) }}</span>
                                <span v-if="e.lat" class="text-muted" dir="ltr"> · {{ e.lat }}, {{ e.lng }}</span>
                            </li>
                        </ul>
                        <div v-else-if="detailTab === 'offers'" class="table-responsive">
                            <table class="table table-sm align-middle fs-13 mb-0">
                                <tbody>
                                    <tr v-for="o in detail.offers" :key="o.uuid">
                                        <td>{{ o.establishment || '—' }}</td>
                                        <td>{{ money(o.price_minor, detail.request.currency_code) }}</td>
                                        <td class="text-end"><span class="badge" :class="offerClass(o.status)">{{ labelOf('offer_status', o.status) }}</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else-if="detailTab === 'changes'" class="table-responsive">
                            <table class="table table-sm align-middle fs-13 mb-0">
                                <tbody>
                                    <tr v-for="c in detail.changes" :key="c.uuid">
                                        <td>{{ t(`moving.requests.change_kind.${c.kind}`) }}</td>
                                        <td>{{ c.price_delta_minor ? money(c.price_delta_minor, detail.request.currency_code) : (c.new_scheduled_at ? formatDateTime(c.new_scheduled_at) : '—') }}</td>
                                        <td class="text-muted">{{ c.reason }}</td>
                                        <td class="text-end"><span class="badge" :class="changeClass(c.status)">{{ labelOf('change_status', c.status) }}</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else-if="detailTab === 'inventory'">
                            <div v-for="item in detail.inventory" :key="item.uuid" class="d-flex align-items-center gap-2 border-top py-2 fs-13">
                                <span class="badge bg-light text-dark">#{{ item.number }}</span>
                                <span class="flex-grow-1">{{ item.label }}</span>
                                <a v-if="item.before" :href="item.before" target="_blank" rel="noopener noreferrer"><img :src="item.before" alt="" class="rounded border" style="width: 56px; height: 44px; object-fit: cover"></a>
                                <a v-if="item.after" :href="item.after" target="_blank" rel="noopener noreferrer"><img :src="item.after" alt="" class="rounded border" style="width: 56px; height: 44px; object-fit: cover"></a>
                            </div>
                        </div>
                        <div v-else>
                            <div v-for="tk in detail.tickets" :key="tk.uuid" class="border-top py-2 fs-13">
                                <b>{{ tk.subject }}</b> <span class="badge bg-light text-dark ms-1">{{ t(`moving.tickets.type.${tk.type}`) }}</span>
                                <span class="badge bg-info-transparent ms-1">{{ t(`moving.tickets.status.${tk.status}`) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="detail = null">{{ t('close') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { fmtMinor, formatDateTime as walletDateTime } from '../../../../../../../utils/walletMoney';

// Every move live and one move's whole story (docs/moving_tasks.md B-17): steps with places and times, offers,
// changes, inventory photos, tickets, and the money held, paid and given back.
const { t, te, locale } = useI18n();
const { showError } = useToast();

const { rows, loading, pagination, filters, fetch } = useWalletList('moving-requests', { defaults: { search: '', status: '' }, perPage: 20 });
const summary = reactive({ by_status: {}, settled: [] });
const detail = ref(null);
const detailTab = ref('timeline');

const statusOptions = computed(() => [
    { value: 'open', label: t('moving.request_status.open') },
    { value: 'confirmed,assigned,driver_on_way,arrived,loading,in_transit,unloading', label: t('moving.requests.running') },
    { value: 'delivered,disputed', label: t('moving.requests.after_delivery') },
    { value: 'completed', label: t('moving.request_status.completed') },
    { value: 'cancelled', label: t('moving.request_status.cancelled') },
]);

// "from → to" reads the right way in both directions.
const arrow = computed(() => (locale.value === 'ar' ? '←' : '→'));
const arrowIcon = computed(() => (locale.value === 'ar' ? 'ri-arrow-left-line' : 'ri-arrow-right-line'));

const money = (minor, currency) => (minor === null || minor === undefined ? '—' : fmtMinor(minor, currency ?? ''));
const formatDateTime = (iso) => (iso ? walletDateTime(iso, locale.value) : '—');
const labelOf = (group, value) => (te(`moving.requests.${group}.${value}`) ? t(`moving.requests.${group}.${value}`) : value);
const eventLabel = (action) => labelOf('events', action);
const actorLabel = (actor) => labelOf('actor', actor);
const statusClass = (status) => ({
    open: 'bg-info-transparent', completed: 'bg-success-transparent', delivered: 'bg-success-transparent', cancelled: 'bg-danger-transparent',
    disputed: 'bg-danger-transparent', expired: 'bg-light text-dark', withdrawn: 'bg-light text-dark', draft: 'bg-light text-dark',
}[status] ?? 'bg-warning-transparent');
const offerClass = (status) => ({
    accepted: 'bg-success-transparent', sent: 'bg-info-transparent', awaiting_approval: 'bg-warning-transparent',
}[status] ?? 'bg-light text-dark');
const changeClass = (status) => ({
    approved: 'bg-success-transparent', rejected: 'bg-danger-transparent', pending: 'bg-warning-transparent', awaiting_payment: 'bg-warning-transparent',
}[status] ?? 'bg-light text-dark');

const moneyLines = computed(() => [
    { key: 'price', value: detail.value.money.price_minor },
    { key: 'tax', value: detail.value.money.tax_minor },
    { key: 'total', value: detail.value.money.total_minor },
    { key: 'commission', value: detail.value.money.commission_minor },
    { key: 'net', value: detail.value.money.provider_net_minor },
    { key: 'held', value: detail.value.held_minor },
    { key: 'refunded', value: detail.value.money.refunded_minor },
]);
const tabRows = computed(() => ({
    timeline: detail.value?.events, offers: detail.value?.offers, changes: detail.value?.changes,
    inventory: detail.value?.inventory, tickets: detail.value?.tickets,
}[detailTab.value] ?? []));

async function loadSummary() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/moving-requests/summary');
        Object.assign(summary, data.data ?? {});
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
}

async function open(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/moving-requests/${row.uuid}`);
        detail.value = data.data;
        detailTab.value = 'timeline';
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
}

onMounted(() => {
    fetch(1);
    loadSummary();
});
</script>
