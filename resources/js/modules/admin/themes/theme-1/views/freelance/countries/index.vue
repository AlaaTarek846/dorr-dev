<template>
    <div>
        <WalletPageHeader :title="t('freelance.countries.title')" :section="t('sidebar.freelance')" :total="rows.length" />

        <div class="alert alert-light border fs-13">{{ t('freelance.countries.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <FlStatusFilter v-model="filter" :options="filterOptions" />
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.countries.search')">
                    </div>
                </div>
                <div class="btn-list">
                    <router-link v-if="can('freelance-feature-rules.view')" :to="{ name: 'admin.freelance.switches' }" class="btn btn-sm btn-outline-primary btn-wave">
                        <i class="ri-toggle-line me-1"></i>{{ t('freelance.switches.title') }}
                    </router-link>
                    <router-link v-if="can('service-availability.view')" :to="{ name: 'admin.service-availability.index' }" class="btn btn-sm btn-outline-secondary btn-wave">
                        <i class="ri-map-pin-line me-1"></i>{{ t('freelance.countries.availability') }}
                    </router-link>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.reports.country') }}</th>
                                <th>{{ t('freelance.countries.currency') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th>{{ t('freelance.countries.features') }}</th>
                                <th class="pe-4">{{ t('freelance.countries.projects') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :layout="['two-lines', 'text', 'badge', 'text', 'text']" />
                            <tr v-else-if="!visible.length">
                                <td colspan="5" class="border-0">
                                    <FlEmpty icon="ri-earth-line" :title="t('freelance.countries.empty_title')" :text="t('freelance.countries.empty')" />
                                </td>
                            </tr>
                            <tr v-for="c in visible" v-else :key="c.id">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-sm avatar-rounded bg-primary-transparent"><i class="ri-map-pin-2-line text-primary"></i></span>
                                        <div>
                                            <span class="fw-semibold d-block">{{ c.code }}</span>
                                            <span class="text-muted fs-11">#{{ c.id }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td dir="ltr">{{ c.currency || '—' }}</td>
                                <td>
                                    <span v-if="c.closed_by_availability" class="badge bg-danger-transparent"><i class="ri-lock-line me-1"></i>{{ t('freelance.countries.closed_by_availability') }}</span>
                                    <span v-else-if="c.service_open" class="badge bg-success-transparent">{{ t('freelance.countries.open') }}</span>
                                    <span v-else class="badge bg-secondary-transparent">{{ t('freelance.countries.off') }}</span>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1 features">
                                        <span v-for="key in enabled(c)" :key="key" class="badge fs-10 bg-success-transparent">{{ t(`freelance.switches.features.${key}`, key) }}</span>
                                        <span v-if="!enabled(c).length" class="text-muted">—</span>
                                    </div>
                                </td>
                                <td class="pe-4"><span class="badge bg-light text-default">{{ c.projects }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="page = $event" />
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import FlEmpty from '../../../../../../../components/freelance/FlEmpty.vue';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Where the service is open (FL-804): the freelance switch of each country, and whether the Service control page closed the service
// there. The switches themselves are changed on their own pages — this only shows the result the apps will follow.
const { t } = useI18n();
const { can } = usePermission();
const { showError } = useToast();

const rows = ref([]);
const loading = ref(false);
const filter = ref('all');

const state = (c) => (c.closed_by_availability ? 'closed' : c.service_open ? 'open' : 'off');
const filterOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), count: rows.value.length, tone: 'all' },
    { value: 'open', label: t('freelance.countries.open'), count: rows.value.filter((c) => state(c) === 'open').length, tone: 'active' },
    { value: 'off', label: t('freelance.countries.off'), count: rows.value.filter((c) => state(c) === 'off').length, tone: 'inactive' },
    { value: 'closed', label: t('freelance.countries.closed_by_availability'), count: rows.value.filter((c) => state(c) === 'closed').length, tone: 'deleted' },
]);
const search = ref('');
const page = ref(1);
const PER_PAGE = 15;
const ORDER = { open: 0, closed: 1, off: 2 };
const shown = computed(() => rows.value.filter((c) => (filter.value === 'all' || state(c) === filter.value)
    && (!search.value || (c.code ?? '').toLowerCase().includes(search.value.toLowerCase()) || (c.currency ?? '').toLowerCase().includes(search.value.toLowerCase())))
    .sort((a, b) => ORDER[state(a)] - ORDER[state(b)]));
const visible = computed(() => shown.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE));
const pagination = computed(() => {
    const total = shown.value.length;
    const last = Math.max(1, Math.ceil(total / PER_PAGE));
    return { total, current_page: page.value, last_page: last, from: total ? (page.value - 1) * PER_PAGE + 1 : 0, to: Math.min(total, page.value * PER_PAGE) };
});
// A feature the apps will show in this country: the service is open there and the feature is on.
const enabled = (c) => (c.service_open ? Object.keys(c.features).filter((k) => c.features[k]) : []);
watch([filter, search], () => (page.value = 1));

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/freelance-oversight/countries');
        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.features {
    max-width: 420px;
    white-space: normal;
}
</style>
