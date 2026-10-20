<template>
    <div>
        <WalletPageHeader :title="t('rides.rides.title')" :section="t('sidebar.rides')" :total="pagination?.total ?? null" />

        <!-- O-06 (LeeTaxi's bookings page): the filters, then the maps — the heat map and the captains on the map. -->
        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 240px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('rides.rides.search')">
                </div>
                <select v-model="filters.status" class="form-select form-select-sm" style="max-width: 160px;">
                    <option value="">{{ t('rides.rides.all_statuses') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`rides.ride_status.${s}`) }}</option>
                </select>
                <select v-model="filters.category_id" class="form-select form-select-sm" style="max-width: 160px;">
                    <option value="">{{ t('rides.maps.all_categories') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.names?.[locale] || c.names?.ar || c.slug }}</option>
                </select>
                <select v-model="filters.payment_method" class="form-select form-select-sm" style="max-width: 130px;">
                    <option value="">{{ t('rides.rides.all_payments') }}</option>
                    <option v-for="p in ['wallet', 'card', 'cash']" :key="p" :value="p">{{ t(`rides.payment.${p}`) }}</option>
                </select>
                <select v-model="filters.wasl_status" class="form-select form-select-sm" style="max-width: 160px;">
                    <option value="">{{ t('rides.rides.wasl_any') }}</option>
                    <option v-for="w in ['pending', 'sent', 'failed', 'not_required']" :key="w" :value="w">{{ t('rides.wasl.title') }}: {{ t(`rides.wasl_trip.${w}`) }}</option>
                </select>
                <div class="input-group input-group-sm" style="max-width: 300px;">
                    <span class="input-group-text"><i class="ri-calendar-line"></i></span>
                    <input v-model="filters.from" type="date" class="form-control">
                    <input v-model="filters.to" type="date" class="form-control">
                </div>
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary-light" @click="heatOpen = true"><i class="ri-fire-line me-1"></i>{{ t('rides.maps.heat_button') }}</button>
                    <button type="button" class="btn btn-sm btn-primary-light" @click="driversOpen = true"><i class="ri-map-pin-user-line me-1"></i>{{ t('rides.maps.drivers_button') }}</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('rides.rides.rider') }}</th>
                                <th>{{ t('rides.drivers.driver') }}</th>
                                <th>{{ t('rides.rides.ride') }}</th>
                                <th>{{ t('rides.maps.plate') }}</th>
                                <th>{{ t('rides.maps.category') }}</th>
                                <th>{{ t('rides.maps.from') }}</th>
                                <th>{{ t('rides.maps.to') }}</th>
                                <th>{{ t('rides.rides.total') }}</th>
                                <th>{{ t('rides.drivers.status') }}</th>
                                <th>{{ t('rides.maps.booked_at') }}</th>
                                <th>{{ t('rides.wasl.title') }}</th>
                                <th class="pe-4 text-end">{{ t('rides.maps.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="12" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="12" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="r in rows" v-else :key="r.id">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-md avatar-rounded bg-primary-transparent fw-semibold">
                                            <img v-if="r.rider?.avatar" :src="r.rider.avatar" alt=""><template v-else>{{ initials(r.rider?.name) }}</template>
                                        </span>
                                        <div><div class="fw-semibold">{{ r.rider?.name || '—' }}</div><div class="fs-11 text-muted" dir="ltr">{{ r.rider?.phone }}</div></div>
                                    </div>
                                </td>
                                <td>
                                    <div v-if="r.driver" class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-md avatar-rounded bg-success-transparent fw-semibold">
                                            <img v-if="r.driver.avatar" :src="r.driver.avatar" alt=""><template v-else>{{ initials(r.driver.name) }}</template>
                                        </span>
                                        <div><div class="fw-semibold">{{ r.driver.name || '—' }}</div><div class="fs-11 text-muted" dir="ltr">{{ r.driver.phone }}</div></div>
                                    </div>
                                    <span v-else class="text-muted fs-12">{{ t('rides.maps.no_driver') }}</span>
                                </td>
                                <td><div class="fw-semibold" dir="ltr">{{ r.number }}</div><div class="fs-11 text-muted">{{ t(`rides.payment.${r.payment_method}`) }}</div></td>
                                <td><span v-if="r.plate" class="badge bg-light text-dark border" dir="rtl">{{ r.plate }}</span><span v-else class="text-muted">—</span></td>
                                <td>
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <span class="rounded-circle d-inline-block" :style="{ width: '10px', height: '10px', background: r.category_color || '#94a3b8' }"></span>{{ r.category || '—' }}
                                    </span>
                                </td>
                                <td class="text-wrap fs-12" style="max-width: 190px">{{ r.origin_address }}</td>
                                <td class="text-wrap fs-12" style="max-width: 190px">{{ r.destination_address || t('rides.rides.open_destination') }}</td>
                                <td class="fw-semibold">{{ money(r.total_minor) }} {{ r.currency }}</td>
                                <td><span class="badge rounded-pill fs-12" :class="statusClass(r.status)"><i :class="statusIcon(r.status)" class="me-1"></i>{{ t(`rides.ride_status.${r.status}`) }}</span></td>
                                <td class="fs-12">{{ formatDate(r.requested_at) }}</td>
                                <td>
                                    <span v-if="r.wasl_status" class="badge" :class="waslClass(r.wasl_status)">{{ t(`rides.wasl_trip.${r.wasl_status}`) }}</span>
                                    <div v-if="r.wasl_result_code && r.wasl_status !== 'sent'" class="fs-11 text-danger">{{ r.wasl_result_code }}</div>
                                </td>
                                <td class="pe-4 text-end">
                                    <button type="button" class="btn btn-icon btn-sm btn-primary-transparent rounded-pill me-1" :title="t('rides.maps.on_map')" @click="mapRide = r"><i class="ri-map-2-line"></i></button>
                                    <button type="button" class="btn btn-icon btn-sm btn-light rounded-pill" :title="t('rides.maps.details')" @click="router.push({ name: 'admin.rides.rides.show', params: { id: r.id } })"><i class="ri-eye-line"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <HeatMapModal :show="heatOpen" @close="heatOpen = false" />
        <DriversMapModal :show="driversOpen" @close="driversOpen = false" />
        <RideRouteModal :ride="mapRide" @close="mapRide = null" />
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useWalletList from '../../../../../../../composables/useWalletList';
import DriversMapModal from './maps/DriversMapModal.vue';
import HeatMapModal from './maps/HeatMapModal.vue';
import RideRouteModal from './maps/RideRouteModal.vue';

// Every ride; the Wasl queue is this list with "Wasl: failed / pending" (opened from the sidebar's queue link).
const { t, locale } = useI18n();
const router = useRouter();
const route = useRoute();
const statuses = ['awaiting_payment', 'scheduled', 'searching', 'bidding', 'accepted', 'arrived', 'in_progress', 'completed', 'canceled', 'no_show', 'expired'];
const { rows, loading, pagination, filters, fetch } = useWalletList('rides', {
    defaults: { search: '', status: '', category_id: '', payment_method: '', wasl_status: route.query.wasl_status ?? '', from: '', to: '' },
});
const categories = ref([]);
const heatOpen = ref(false);
const driversOpen = ref(false);
const mapRide = ref(null);

const money = (minor) => (minor == null ? '—' : (minor / 100).toFixed(2));
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) : '');
const initials = (name) => (name || '?').trim().split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
const statusClass = (s) => ({ completed: 'bg-success-transparent', canceled: 'bg-danger-transparent', no_show: 'bg-danger-transparent', expired: 'bg-secondary-transparent', searching: 'bg-info-transparent', bidding: 'bg-info-transparent', awaiting_payment: 'bg-warning-transparent', scheduled: 'bg-purple-transparent', in_progress: 'bg-primary-transparent' }[s] ?? 'bg-primary-transparent');
const statusIcon = (s) => ({ completed: 'ri-checkbox-circle-line', canceled: 'ri-close-circle-line', no_show: 'ri-user-unfollow-line', expired: 'ri-time-line', searching: 'ri-radar-line', bidding: 'ri-auction-line', awaiting_payment: 'ri-bank-card-line', scheduled: 'ri-calendar-event-line', accepted: 'ri-user-follow-line', arrived: 'ri-map-pin-user-line', in_progress: 'ri-steering-2-line' }[s] ?? 'ri-information-line');
const waslClass = (s) => ({ sent: 'bg-success-transparent', failed: 'bg-danger-transparent', pending: 'bg-warning-transparent' }[s] ?? 'bg-light text-muted');

onMounted(async () => {
    fetch(1);
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ride-categories');
        categories.value = data.data?.categories ?? [];
    } catch {
        categories.value = [];
    }
});
</script>
