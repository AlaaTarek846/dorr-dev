<template>
    <div>
        <WalletPageHeader :title="t('rides.rides.title')" :section="t('sidebar.rides')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('rides.rides.search')">
                </div>
                <select v-model="filters.status" class="form-select form-select-sm" style="max-width: 170px;">
                    <option value="">{{ t('rides.rides.all_statuses') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`rides.ride_status.${s}`) }}</option>
                </select>
                <select v-model="filters.payment_method" class="form-select form-select-sm" style="max-width: 140px;">
                    <option value="">{{ t('rides.rides.all_payments') }}</option>
                    <option v-for="p in ['wallet', 'card', 'cash']" :key="p" :value="p">{{ t(`rides.payment.${p}`) }}</option>
                </select>
                <select v-model="filters.wasl_status" class="form-select form-select-sm" style="max-width: 170px;">
                    <option value="">{{ t('rides.rides.wasl_any') }}</option>
                    <option v-for="w in ['pending', 'sent', 'failed', 'not_required']" :key="w" :value="w">{{ t('rides.wasl.title') }}: {{ t(`rides.wasl_trip.${w}`) }}</option>
                </select>
                <input v-model="filters.from" type="date" class="form-control form-control-sm" style="max-width: 150px;">
                <input v-model="filters.to" type="date" class="form-control form-control-sm" style="max-width: 150px;">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('rides.rides.ride') }}</th>
                                <th>{{ t('rides.rides.rider') }}</th>
                                <th>{{ t('rides.drivers.driver') }}</th>
                                <th>{{ t('rides.rides.trip') }}</th>
                                <th>{{ t('rides.rides.total') }}</th>
                                <th>{{ t('rides.drivers.status') }}</th>
                                <th>{{ t('rides.wasl.title') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="7" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="7" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="r in rows" v-else :key="r.id" role="button" @click="router.push({ name: 'admin.rides.rides.show', params: { id: r.id } })">
                                <td class="ps-4">
                                    <div class="fw-semibold" dir="ltr">{{ r.number }}</div>
                                    <div class="fs-11 text-muted">{{ r.category }} · {{ formatDate(r.requested_at) }}</div>
                                </td>
                                <td><div>{{ r.rider?.name || '—' }}</div><div class="fs-11 text-muted" dir="ltr">{{ r.rider?.phone }}</div></td>
                                <td><div>{{ r.driver?.name || '—' }}</div><div class="fs-11 text-muted" dir="ltr">{{ r.driver?.phone }}</div></td>
                                <td class="text-wrap fs-12" style="max-width: 260px">{{ r.origin_address }} <i class="ri-arrow-left-line rtl-arrow"></i> {{ r.destination_address || '—' }}</td>
                                <td>
                                    <div class="fw-semibold">{{ money(r.total_minor) }} {{ r.currency }}</div>
                                    <div class="fs-11 text-muted">{{ t(`rides.payment.${r.payment_method}`) }}</div>
                                </td>
                                <td><span class="badge" :class="statusClass(r.status)">{{ t(`rides.ride_status.${r.status}`) }}</span></td>
                                <td>
                                    <span v-if="r.wasl_status" class="badge" :class="waslClass(r.wasl_status)">{{ t(`rides.wasl_trip.${r.wasl_status}`) }}</span>
                                    <div v-if="r.wasl_result_code && r.wasl_status !== 'sent'" class="fs-11 text-danger">{{ r.wasl_result_code }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>
    </div>
</template>

<script setup>
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useWalletList from '../../../../../../../composables/useWalletList';

// Every ride; the Wasl queue is this list with "Wasl: failed / pending" (opened from the sidebar's queue link).
const { t, locale } = useI18n();
const router = useRouter();
const route = useRoute();
const statuses = ['awaiting_payment', 'scheduled', 'searching', 'bidding', 'accepted', 'arrived', 'in_progress', 'completed', 'canceled', 'no_show', 'expired'];
const { rows, loading, pagination, filters, fetch } = useWalletList('rides', {
    defaults: { search: '', status: '', payment_method: '', wasl_status: route.query.wasl_status ?? '', from: '', to: '' },
});

const money = (minor) => (minor == null ? '—' : (minor / 100).toFixed(2));
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) : '');
const statusClass = (s) => ({ completed: 'bg-success-transparent', canceled: 'bg-danger-transparent', no_show: 'bg-danger-transparent', expired: 'bg-secondary-transparent', searching: 'bg-info-transparent', awaiting_payment: 'bg-warning-transparent' }[s] ?? 'bg-primary-transparent');
const waslClass = (s) => ({ sent: 'bg-success-transparent', failed: 'bg-danger-transparent', pending: 'bg-warning-transparent' }[s] ?? 'bg-light text-muted');

onMounted(() => fetch(1));
</script>

<style scoped>
[dir='ltr'] .rtl-arrow {
    display: inline-block;
    transform: scaleX(-1);
}
</style>
