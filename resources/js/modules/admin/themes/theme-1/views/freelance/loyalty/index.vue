<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.loyalty.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.loyalty.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border fs-13">{{ t('freelance.loyalty.hint') }}</div>

        <!-- The numbers at a glance. -->
        <div class="row g-3 mb-3">
            <div v-for="k in kpis" :key="k.key" class="col-12 col-md-4">
                <div class="card custom-card mb-0">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <span class="avatar avatar-md rounded-3" :class="k.tone"><i :class="k.icon" class="fs-18"></i></span>
                        <div>
                            <div class="fs-20 fw-semibold lh-1">{{ k.value }}</div>
                            <div class="text-muted fs-12 mt-1">{{ t(`freelance.loyalty.kpi.${k.key}`) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <FlStatusFilter v-model="state" :options="stateOptions" />
                <div class="input-group input-group-sm catalog-toolbar-search ms-auto">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.loyalty.search')">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.loyalty.freelancer') }}</th>
                                <th>{{ t('freelance.loyalty.state') }}</th>
                                <th>{{ t('freelance.loyalty.steps') }}</th>
                                <th>{{ t('freelance.loyalty.max') }}</th>
                                <th>{{ t('freelance.loyalty.given') }}</th>
                                <th>{{ t('freelance.loyalty.updated') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :layout="['two-lines', 'badge', 'text', 'text', 'two-lines', 'text', 'actions']" />
                            <tr v-else-if="!rows.length"><td colspan="7" class="text-center text-muted py-5">{{ t('freelance.loyalty.empty') }}</td></tr>
                            <tr v-for="p in rows" v-else :key="p.id">
                                <td class="ps-4">
                                    <span class="d-block fw-semibold">{{ p.freelancer?.name || '—' }}</span>
                                    <span class="text-muted fs-11" dir="ltr">{{ p.freelancer?.phone }}</span>
                                </td>
                                <td><span class="badge" :class="p.enabled ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ t(p.enabled ? 'freelance.loyalty.on' : 'freelance.loyalty.off') }}</span></td>
                                <td>
                                    <span v-for="s in p.tiers" :key="s.after" class="badge bg-primary-transparent me-1">{{ t('freelance.loyalty.step', { after: s.after, percent: percent(s.discount_bp) }) }}</span>
                                </td>
                                <td>{{ percent(top(p)) }}%</td>
                                <td>
                                    <span class="d-block">{{ t('freelance.loyalty.uses_n', { n: p.uses }) }}</span>
                                    <span class="text-muted fs-11">{{ money(p.saved_minor, decimalsOf(p.currency), p.currency) }}</span>
                                </td>
                                <td class="fs-12">{{ when(p.updated_at) }}</td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-light btn-wave" @click="current = p"><i class="ri-eye-line me-1"></i>{{ t('freelance.loyalty.open') }}</button>
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

        <!-- One program: the ladder as the freelancer set it. -->
        <WalletModal :show="!!current" :title="current?.freelancer?.name || ''" @close="current = null">
            <div v-if="current">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge" :class="current.enabled ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ t(current.enabled ? 'freelance.loyalty.on' : 'freelance.loyalty.off') }}</span>
                    <span class="text-muted fs-12" dir="ltr">{{ current.freelancer?.phone }}</span>
                </div>
                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.loyalty.ladder') }}</h6>
                <ul class="list-group mb-3 fs-13">
                    <li v-for="s in current.tiers" :key="s.after" class="list-group-item d-flex justify-content-between">
                        <span>{{ t('freelance.loyalty.after_n', { n: s.after }) }}</span>
                        <span class="fw-semibold text-success">{{ percent(s.discount_bp) }}%</span>
                    </li>
                </ul>
                <ul class="list-group fs-13">
                    <li class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.loyalty.given') }}</span><span>{{ t('freelance.loyalty.uses_n', { n: current.uses }) }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.loyalty.saved') }}</span><span>{{ money(current.saved_minor, decimalsOf(current.currency), current.currency) }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span>{{ t('freelance.loyalty.updated') }}</span><span>{{ when(current.updated_at) }}</span></li>
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

// Loyalty programs (FL-L09 part B), read only: what each freelancer gives returning clients and what it has come to. The limits are in
// Prices & rules → Loyalty. Money is in minor units of the currency it was taken in.
const { t, locale } = useI18n();
const { showError } = useToast();
const base = '/api/admin/v1/freelance-loyalty';

const rows = ref([]);
const pagination = ref({});
const totals = ref({ freelancers: 0, uses: 0, saved: [] });
const pageNo = ref(1);
const loading = ref(false);
const state = ref('all');
const search = ref('');
const current = ref(null);
const decimals = ref({});

const percent = (bp) => (bp % 100 === 0 ? String(bp / 100) : (bp / 100).toFixed(1));
const top = (p) => Math.max(0, ...p.tiers.map((s) => s.discount_bp));
const decimalsOf = (code) => decimals.value[code] ?? 2;
const money = (minor, dec = 2, currency = '') => (minor == null ? '—' : `${(Number(minor) / 10 ** dec).toFixed(dec)} ${currency ?? ''}`.trim());
const when = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—');

const kpis = computed(() => [
    { key: 'freelancers', icon: 'ri-user-star-line', tone: 'bg-primary-transparent', value: totals.value.freelancers },
    { key: 'uses', icon: 'ri-gift-line', tone: 'bg-success-transparent', value: totals.value.uses },
    {
        key: 'saved', icon: 'ri-money-dollar-circle-line', tone: 'bg-warning-transparent',
        value: totals.value.saved.length ? totals.value.saved.map((s) => money(s.saved_minor, s.decimals, s.currency)).join(' · ') : '—',
    },
]);
const stateOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), tone: 'all' },
    { value: '1', label: t('freelance.loyalty.on'), tone: 'active' },
    { value: '0', label: t('freelance.loyalty.off'), tone: 'inactive' },
]);

let timer = null;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => (pageNo.value === 1 ? load() : (pageNo.value = 1)), 300); });
watch(state, () => (pageNo.value === 1 ? load() : (pageNo.value = 1)));
watch(pageNo, load);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(base, { params: { page: pageNo.value, search: search.value || undefined, enabled: state.value === 'all' ? undefined : state.value } });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
        totals.value = data.totals ?? totals.value;
        totals.value.saved.forEach((s) => { decimals.value[s.currency] = s.decimals; });
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>
