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
            <div class="col-xl-3 col-md-6">
                <div class="card custom-card">
                    <div class="card-body d-flex flex-wrap gap-1">
                        <span v-for="(count, status) in summary.by_status" :key="status" class="badge" :class="statusClass(status)">{{ t(`moving.request_status.${status}`) }} {{ count }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center py-3">
                <input v-model="filters.search" type="search" class="form-control form-control-sm" style="max-width: 240px" :placeholder="t('moving.requests.search')" @keyup.enter="load(1)">
                <select v-model="filters.status" class="form-select form-select-sm" style="max-width: 200px" @change="load(1)">
                    <option value="">{{ t('moving.requests.all_statuses') }}</option>
                    <option value="open">{{ t('moving.request_status.open') }}</option>
                    <option value="confirmed,assigned,driver_on_way,arrived,loading,in_transit,unloading">{{ t('moving.requests.running') }}</option>
                    <option value="delivered,disputed">{{ t('moving.requests.after_delivery') }}</option>
                    <option value="completed">{{ t('moving.request_status.completed') }}</option>
                    <option value="cancelled">{{ t('moving.request_status.cancelled') }}</option>
                </select>
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
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.uuid" role="button" @click="open(row)">
                                <td class="ps-4">
                                    <div class="fw-semibold">{{ row.pickup_area || '—' }} <i class="ri-arrow-left-line mx-1 text-muted"></i> {{ row.dropoff_area || '—' }}</div>
                                    <code class="fs-11 text-muted">{{ row.uuid.slice(0, 8).toUpperCase() }}</code>
                                </td>
                                <td><div>{{ row.customer?.name || '—' }}</div><div class="fs-12 text-muted" dir="ltr">{{ row.customer?.phone }}</div></td>
                                <td>{{ row.establishment || '—' }}</td>
                                <td class="fs-12">{{ row.scheduled_at ? new Date(row.scheduled_at).toLocaleString() : '—' }}</td>
                                <td>{{ money(row.total_minor, row.currency_code) }}</td>
                                <td><span class="badge" :class="statusClass(row.status)">{{ t(`moving.request_status.${row.status}`) }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="pagination && pagination.last_page > 1" class="card-footer d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-sm btn-light" :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)"><i class="ri-arrow-right-s-line"></i></button>
                <span class="fs-13">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
                <button type="button" class="btn btn-sm btn-light" :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)"><i class="ri-arrow-left-s-line"></i></button>
            </div>
        </div>

        <WalletModal :show="!!detail" :title="detail ? `${detail.request.pickup.area || '—'} ← ${detail.request.dropoff.area || '—'}` : ''" size="xl" @close="detail = null">
            <template v-if="detail">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="border rounded-3 p-3 fs-13 mb-3">
                            <div class="d-flex justify-content-between mb-1"><span class="text-muted">{{ t('moving.requests.price') }}</span><b>{{ money(detail.money.price_minor, detail.request.currency_code) }}</b></div>
                            <div class="d-flex justify-content-between mb-1"><span class="text-muted">{{ t('moving.requests.tax') }}</span><b>{{ money(detail.money.tax_minor, detail.request.currency_code) }}</b></div>
                            <div class="d-flex justify-content-between mb-1"><span class="text-muted">{{ t('moving.requests.total') }}</span><b>{{ money(detail.money.total_minor, detail.request.currency_code) }}</b></div>
                            <div class="d-flex justify-content-between mb-1"><span class="text-muted">{{ t('moving.requests.commission') }}</span><b>{{ money(detail.money.commission_minor, detail.request.currency_code) }}</b></div>
                            <div class="d-flex justify-content-between mb-1"><span class="text-muted">{{ t('moving.requests.net') }}</span><b>{{ money(detail.money.provider_net_minor, detail.request.currency_code) }}</b></div>
                            <div class="d-flex justify-content-between mb-1"><span class="text-muted">{{ t('moving.requests.held') }}</span><b>{{ money(detail.held_minor, detail.request.currency_code) }}</b></div>
                            <div class="d-flex justify-content-between"><span class="text-muted">{{ t('moving.requests.refunded') }}</span><b>{{ money(detail.money.refunded_minor, detail.request.currency_code) }}</b></div>
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
                        <ul v-if="detailTab === 'timeline'" class="list-unstyled fs-12 mb-0" style="max-height: 320px; overflow-y: auto">
                            <li v-for="(e, i) in detail.events" :key="i" class="border-start border-2 ps-2 mb-2">
                                <b>{{ e.action }}</b>
                                <span v-if="e.to_status" class="badge ms-1" :class="statusClass(e.to_status)">{{ t(`moving.request_status.${e.to_status}`) }}</span>
                                <span class="text-muted"> · {{ e.actor_type }} · {{ new Date(e.created_at).toLocaleString() }}</span>
                                <span v-if="e.lat" class="text-muted" dir="ltr"> · {{ e.lat }}, {{ e.lng }}</span>
                            </li>
                        </ul>
                        <table v-else-if="detailTab === 'offers'" class="table table-sm fs-13 mb-0">
                            <tbody><tr v-for="o in detail.offers" :key="o.uuid"><td>{{ o.establishment }}</td><td>{{ money(o.price_minor, detail.request.currency_code) }}</td><td><span class="badge bg-light text-dark">{{ o.status }}</span></td></tr></tbody>
                        </table>
                        <table v-else-if="detailTab === 'changes'" class="table table-sm fs-13 mb-0">
                            <tbody><tr v-for="c in detail.changes" :key="c.uuid"><td>{{ t(`moving.requests.change_kind.${c.kind}`) }}</td><td>{{ c.price_delta_minor ? money(c.price_delta_minor, detail.request.currency_code) : (c.new_scheduled_at ? new Date(c.new_scheduled_at).toLocaleString() : '—') }}</td><td class="text-muted">{{ c.reason }}</td><td><span class="badge bg-light text-dark">{{ c.status }}</span></td></tr></tbody>
                        </table>
                        <div v-else-if="detailTab === 'inventory'">
                            <div v-for="item in detail.inventory" :key="item.uuid" class="d-flex align-items-center gap-2 border-top py-2 fs-13">
                                <span class="badge bg-light text-dark">#{{ item.number }}</span>
                                <span class="flex-grow-1">{{ item.label }}</span>
                                <a v-if="item.before" :href="item.before" target="_blank"><img :src="item.before" alt="" class="rounded border" style="width: 56px; height: 44px; object-fit: cover"></a>
                                <a v-if="item.after" :href="item.after" target="_blank"><img :src="item.after" alt="" class="rounded border" style="width: 56px; height: 44px; object-fit: cover"></a>
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
                <button type="button" class="btn btn-light" @click="detail = null">{{ t('cancel') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';

// Every move live and one move's whole story (docs/moving_tasks.md B-17): steps with places and times, offers,
// changes, inventory photos, tickets, and the money held, paid and given back.
const { t } = useI18n();
const { showError } = useToast();

const rows = ref([]);
const pagination = ref(null);
const loading = ref(false);
const summary = reactive({ by_status: {}, settled: [] });
const filters = reactive({ search: '', status: '' });
const detail = ref(null);
const detailTab = ref('timeline');

const money = (minor, currency) => (minor === null || minor === undefined ? '—' : `${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })} ${currency ?? ''}`);
const statusClass = (status) => ({
    open: 'bg-info-transparent', completed: 'bg-success-transparent', delivered: 'bg-success-transparent', cancelled: 'bg-danger-transparent',
    disputed: 'bg-danger-transparent', expired: 'bg-light text-dark', withdrawn: 'bg-light text-dark', draft: 'bg-light text-dark',
}[status] ?? 'bg-warning-transparent');

async function load(page = 1) {
    loading.value = true;
    try {
        const params = { page, search: filters.search || undefined, status: filters.status || undefined };
        const { data } = await adminAxios.get('/api/admin/v1/moving-requests', { params });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? null;
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        loading.value = false;
    }
}

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
    load();
    loadSummary();
});
</script>
