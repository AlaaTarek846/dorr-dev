<template>
    <div>
        <WalletPageHeader :title="t('freelance.oversight.title')" :section="t('sidebar.freelance')" :total="pagination.total ?? null" />

        <div class="alert alert-light border fs-13">{{ t('freelance.oversight.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <FlStatusFilter v-model="tab" :options="tabs" />
                </div>
                <div v-if="tab === 'projects'" class="input-group input-group-sm catalog-toolbar-search">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.oversight.search')">
                    <button v-if="search" type="button" class="btn btn-light border" :title="t('users.clear_search')" @click="search = ''">
                        <i class="ri-close-line"></i>
                    </button>
                </div>
            </div>
            <div class="card-header border-top d-flex align-items-center flex-wrap gap-2 py-2">
                <FlStatusFilter v-model="status" :options="statusOptions" />
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr v-if="tab === 'projects'">
                                <th class="ps-4">{{ t('freelance.oversight.project') }}</th>
                                <th>{{ t('freelance.oversight.client') }}</th>
                                <th>{{ t('freelance.oversight.budget') }}</th>
                                <th>{{ t('freelance.oversight.offers') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="pe-4">{{ t('freelance.oversight.created') }}</th>
                            </tr>
                            <tr v-else-if="tab === 'offers'">
                                <th class="ps-4">{{ t('freelance.oversight.project') }}</th>
                                <th>{{ t('freelance.oversight.freelancer') }}</th>
                                <th>{{ t('freelance.oversight.price') }}</th>
                                <th>{{ t('freelance.oversight.duration') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="pe-4">{{ t('freelance.oversight.created') }}</th>
                            </tr>
                            <tr v-else>
                                <th class="ps-4">{{ t('freelance.oversight.project') }}</th>
                                <th>{{ t('freelance.oversight.parties') }}</th>
                                <th>{{ t('freelance.oversight.price') }}</th>
                                <th>{{ t('freelance.oversight.parts') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="pe-4">{{ t('freelance.oversight.started') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="['two-lines', 'two-lines', 'text', 'text', 'badge', 'text']" />
                            <tr v-else-if="!rows.length">
                                <td colspan="6" class="border-0">
                                    <FlEmpty icon="ri-survey-line" :title="t('freelance.oversight.empty_title')" :text="t('freelance.oversight.empty')" />
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.uuid">
                                    <template v-if="tab === 'projects'">
                                        <td class="ps-4">
                                            <span class="fw-semibold d-block text-truncate oversight-title">{{ row.title }}</span>
                                            <span class="text-muted fs-11">{{ t(`freelance.oversight.visibility.${row.visibility}`) }}</span>
                                        </td>
                                        <td>{{ row.client?.name || '—' }}</td>
                                        <td dir="ltr">{{ range(row.budget_min_minor, row.budget_max_minor) }} {{ row.currency }}</td>
                                        <td><span class="badge bg-light text-default">{{ row.offers_count }}</span></td>
                                        <td><span class="badge" :class="badge(row.status)">{{ statusLabel(row.status) }}</span></td>
                                        <td class="pe-4 fs-12">{{ formatCatalogDate(row.created_at, locale) }}</td>
                                    </template>
                                    <template v-else-if="tab === 'offers'">
                                        <td class="ps-4"><span class="fw-semibold d-block text-truncate oversight-title">{{ row.project?.title || '—' }}</span></td>
                                        <td>{{ row.freelancer?.name || '—' }}</td>
                                        <td dir="ltr">{{ money(row.price_minor) }} {{ row.currency }}</td>
                                        <td>{{ t('freelance.oversight.days', { n: row.duration_days }) }}</td>
                                        <td><span class="badge" :class="badge(row.status)">{{ statusLabel(row.status) }}</span></td>
                                        <td class="pe-4 fs-12">{{ formatCatalogDate(row.created_at, locale) }}</td>
                                    </template>
                                    <template v-else>
                                        <td class="ps-4"><span class="fw-semibold d-block text-truncate oversight-title">{{ row.project?.title || '—' }}</span></td>
                                        <td>
                                            <span class="d-block">{{ row.client?.name || '—' }}</span>
                                            <span class="text-muted fs-11">{{ row.freelancer?.name || '—' }}</span>
                                        </td>
                                        <td dir="ltr">{{ money(row.price_minor) }} {{ row.currency }}</td>
                                        <td style="min-width: 130px">
                                            <div class="progress progress-xs"><div class="progress-bar bg-success" :style="{ width: progress(row) + '%' }"></div></div>
                                            <span class="text-muted fs-11">{{ row.parts.approved }} / {{ row.parts.total }}</span>
                                        </td>
                                        <td><span class="badge" :class="badge(row.status)">{{ statusLabel(row.status) }}</span></td>
                                        <td class="pe-4 fs-12">{{ formatCatalogDate(row.activated_at, locale) }}</td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" :per-page="perPage" @change="page = $event" @update:per-page="perPage = $event" />
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
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

// Every project, offer and agreement for staff (FL-801): filter by status, with the number in each, and search the projects. Read only —
// a decision is taken on its own desk (review, disputes, sanctions).
const { t, locale } = useI18n();
const { showError } = useToast();
const endpoint = '/api/admin/v1/freelance-oversight';

const STATUSES = {
    projects: ['draft', 'pending_review', 'changes_requested', 'rejected', 'published', 'awarded', 'in_progress', 'closed', 'cancelled'],
    offers: ['submitted', 'viewed', 'shortlisted', 'accepted', 'rejected', 'withdrawn'],
    agreements: ['draft', 'pending_approval', 'active', 'completed', 'declined', 'cancelled', 'expired'],
};
// The colour of each status on the filter buttons and the badges.
const TONE = {
    published: 'active', active: 'active', completed: 'active', accepted: 'active',
    pending_review: 'pending', pending_approval: 'pending', changes_requested: 'pending', submitted: 'pending', viewed: 'pending', shortlisted: 'pending',
    rejected: 'deleted', cancelled: 'deleted', declined: 'deleted', expired: 'deleted', withdrawn: 'deleted',
    draft: 'inactive', closed: 'inactive', awarded: 'all', in_progress: 'all',
};
const BADGE = { active: 'bg-success-transparent', pending: 'bg-warning-transparent', deleted: 'bg-danger-transparent', inactive: 'bg-secondary-transparent', all: 'bg-primary-transparent' };

const tab = ref('projects');
const status = ref('all');
const search = ref('');
const page = ref(1);
const perPage = ref(15);
const rows = ref([]);
const pagination = ref({});
const counts = ref({});
const loading = ref(false);

const tabs = computed(() => ['projects', 'offers', 'agreements'].map((k) => ({ value: k, label: t(`freelance.oversight.tabs.${k}`), tone: tab.value === k ? 'all' : 'all' })));
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0));
const statusOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), count: total.value, tone: 'all' },
    ...STATUSES[tab.value].map((s) => ({ value: s, label: statusLabel(s), count: counts.value[s] ?? 0, tone: TONE[s] ?? 'inactive' })),
]);
const statusLabel = (s) => t(`freelance.oversight.status.${s}`);
const badge = (s) => BADGE[TONE[s] ?? 'inactive'];
const money = (minor) => (minor == null ? '—' : (minor / 100).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const range = (min, max) => (max && max !== min ? `${money(min)} – ${money(max)}` : money(min));
const progress = (row) => (row.parts.total ? Math.round((row.parts.approved * 100) / row.parts.total) : 0);

let timer;
watch(tab, () => {
    status.value = 'all';
    search.value = '';
    page.value === 1 ? load() : (page.value = 1);
});
watch([status, perPage], () => (page.value === 1 ? load() : (page.value = 1)));
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => (page.value === 1 ? load() : (page.value = 1)), 350);
});
watch(page, load);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(`${endpoint}/${tab.value}`, {
            params: { page: page.value, per_page: perPage.value, status: status.value === 'all' ? undefined : status.value, q: tab.value === 'projects' && search.value ? search.value : undefined },
        });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
        counts.value = data.pagination?.counts ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.oversight-title {
    max-width: 280px;
}
</style>
