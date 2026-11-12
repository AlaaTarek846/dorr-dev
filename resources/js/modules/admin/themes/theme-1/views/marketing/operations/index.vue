<template>
    <div>
        <WalletPageHeader :title="t('adminMarketing.operations.title')" :section="t('adminMarketing.sidebar')" :total="pagination.total ?? null" />

        <div class="alert alert-light border fs-13">{{ t('adminMarketing.operations.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <FlStatusFilter v-model="tab" :options="tabs" />
                <div v-if="tab === 'audit'" class="input-group input-group-sm catalog-toolbar-search">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model.trim="project" type="search" class="form-control" dir="ltr" :placeholder="t('adminMarketing.operations.project_uuid')">
                    <button v-if="project" type="button" class="btn btn-light border" :title="t('users.clear_search')" @click="project = ''">
                        <i class="ri-close-line"></i>
                    </button>
                </div>
            </div>
            <div v-if="tab !== 'audit'" class="card-header border-top d-flex align-items-center flex-wrap gap-2 py-2">
                <FlStatusFilter v-model="status" :options="statusOptions" />
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr v-if="tab === 'subscriptions'">
                                <th class="ps-4">{{ t('adminMarketing.operations.owner') }}</th>
                                <th>{{ t('adminMarketing.operations.package') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th>{{ t('adminMarketing.operations.price') }}</th>
                                <th>{{ t('adminMarketing.operations.starts') }}</th>
                                <th class="pe-4">{{ t('adminMarketing.operations.ends') }}</th>
                            </tr>
                            <tr v-else-if="tab === 'projects'">
                                <th class="ps-4">{{ t('adminMarketing.operations.project') }}</th>
                                <th>{{ t('adminMarketing.operations.owner') }}</th>
                                <th>{{ t('adminMarketing.common.country') }}</th>
                                <th>{{ t('adminMarketing.operations.counts') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="pe-4">{{ t('adminMarketing.operations.created') }}</th>
                            </tr>
                            <tr v-else-if="tab === 'jobs'">
                                <th class="ps-4">{{ t('adminMarketing.operations.job') }}</th>
                                <th>{{ t('adminMarketing.operations.project') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th>{{ t('adminMarketing.operations.error') }}</th>
                                <th>{{ t('adminMarketing.operations.retries') }}</th>
                                <th>{{ t('adminMarketing.operations.created') }}</th>
                                <th class="pe-4">{{ t('adminMarketing.operations.finished') }}</th>
                            </tr>
                            <tr v-else>
                                <th class="ps-4">{{ t('adminMarketing.operations.action') }}</th>
                                <th>{{ t('adminMarketing.operations.actor') }}</th>
                                <th>{{ t('adminMarketing.operations.resource') }}</th>
                                <th>{{ t('adminMarketing.operations.project') }}</th>
                                <th>{{ t('adminMarketing.operations.request') }}</th>
                                <th class="pe-4">{{ t('adminMarketing.operations.when') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="['two-lines', 'text', 'badge', 'text', 'text', 'text']" />
                            <tr v-else-if="!rows.length">
                                <td :colspan="tab === 'jobs' ? 7 : 6" class="border-0">
                                    <FlEmpty icon="ri-pulse-line" :title="t('adminMarketing.operations.empty_title')" :text="t('adminMarketing.operations.empty')" />
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id">
                                    <template v-if="tab === 'subscriptions'">
                                        <td class="ps-4">
                                            <span class="fw-semibold d-block">{{ row.owner?.name || `#${row.owner?.id}` }}</span>
                                            <span class="text-muted fs-11"><span dir="ltr">{{ row.owner?.phone }}</span> · {{ ownerType(row.owner?.type) }}</span>
                                        </td>
                                        <td><code class="fs-12">{{ row.package || '—' }}</code></td>
                                        <td><span class="badge" :class="badge(row.status)">{{ statusLabel(row.status) }}</span></td>
                                        <td dir="ltr">{{ money(row.price_minor) }} {{ row.currency_code }}</td>
                                        <td class="fs-12">{{ formatCatalogDate(row.starts_at, locale) }}</td>
                                        <td class="pe-4 fs-12">{{ formatCatalogDate(row.ends_at, locale) }}</td>
                                    </template>
                                    <template v-else-if="tab === 'projects'">
                                        <td class="ps-4">
                                            <span class="fw-semibold d-block text-truncate ops-title">{{ row.name || '—' }}</span>
                                            <span class="text-muted fs-11">{{ row.industry || '—' }}</span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ row.owner?.name || `#${row.owner?.id}` }}</span>
                                            <span class="text-muted fs-11">{{ ownerType(row.owner?.type) }}</span>
                                        </td>
                                        <td>{{ countryLabel(row.country_id) }}</td>
                                        <td class="fs-12">
                                            <span class="badge bg-light text-default me-1" :title="t('adminMarketing.operations.members')"><i class="ri-team-line me-1"></i>{{ row.members }}</span>
                                            <span class="badge bg-light text-default me-1" :title="t('adminMarketing.operations.research_runs')"><i class="ri-search-eye-line me-1"></i>{{ row.research_runs }}</span>
                                            <span class="badge bg-light text-default" :title="t('adminMarketing.operations.plans')"><i class="ri-file-list-3-line me-1"></i>{{ row.plans }}</span>
                                        </td>
                                        <td><span class="badge" :class="badge(row.status)">{{ statusLabel(row.status) }}</span></td>
                                        <td class="pe-4 fs-12">
                                            {{ formatCatalogDate(row.created_at, locale) }}
                                            <button type="button" class="btn btn-sm btn-light btn-icon ms-2" :title="t('adminMarketing.operations.open_audit')" @click="openAudit(row.id)"><i class="ri-history-line"></i></button>
                                        </td>
                                    </template>
                                    <template v-else-if="tab === 'jobs'">
                                        <td class="ps-4">
                                            <span class="fw-semibold d-block">{{ jobType(row.type) }}</span>
                                            <span class="text-muted fs-11" dir="ltr">{{ row.id }}</span>
                                        </td>
                                        <td>
                                            <button v-if="row.project" type="button" class="btn btn-link btn-sm p-0 fs-11" dir="ltr" :title="t('adminMarketing.operations.open_audit')" @click="openAudit(row.project)">{{ short(row.project) }}</button>
                                            <span v-else class="text-muted">—</span>
                                        </td>
                                        <td><span class="badge" :class="badge(row.status)">{{ statusLabel(row.status) }}</span></td>
                                        <td><code v-if="row.error_code" class="fs-12 text-danger">{{ row.error_code }}</code><span v-else class="text-muted">—</span></td>
                                        <td><span class="badge" :class="row.retry_count ? 'bg-warning-transparent' : 'bg-light text-default'">{{ row.retry_count ?? 0 }}</span></td>
                                        <td class="fs-12">{{ formatCatalogDate(row.created_at, locale) }}</td>
                                        <td class="pe-4 fs-12">{{ row.finished_at ? formatCatalogDate(row.finished_at, locale) : '—' }}</td>
                                    </template>
                                    <template v-else>
                                        <td class="ps-4"><code class="fs-12">{{ row.action }}</code></td>
                                        <td>
                                            <span class="d-block">{{ ownerType(row.actor_type) }}</span>
                                            <span v-if="row.actor_id" class="text-muted fs-11">#{{ row.actor_id }}</span>
                                        </td>
                                        <td class="fs-12">
                                            <template v-if="row.resource_type">{{ row.resource_type }} <span class="text-muted" dir="ltr">{{ short(row.resource_id) }}</span></template>
                                            <span v-else class="text-muted">—</span>
                                        </td>
                                        <td class="fs-12">{{ row.project_id ? `#${row.project_id}` : '—' }}</td>
                                        <td class="fs-11 text-muted" dir="ltr">{{ short(row.request_id) || '—' }}</td>
                                        <td class="pe-4 fs-12">{{ formatCatalogDate(row.created_at, locale) }}</td>
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

// AI Marketing Director operations (MK-A15, U14 least privilege): subscriptions, projects, jobs and the audit trail as numbers
// and states only — never the content of a customer's plans or research (POL-13). Read only.
const { t, te, locale } = useI18n();
const { showError } = useToast();
const endpoint = '/api/admin/v1/marketing';

// The filter values each list takes (`active` for subscriptions, `status` for projects and jobs).
const STATUSES = {
    subscriptions: ['active'],
    projects: ['active', 'archived'],
    jobs: ['QUEUED', 'RUNNING', 'COMPLETED', 'PARTIAL', 'FAILED', 'CANCELLED'],
};
const TONE = {
    active: 'active', COMPLETED: 'active',
    QUEUED: 'pending', RUNNING: 'pending', PARTIAL: 'pending',
    FAILED: 'deleted', cancelled: 'deleted', CANCELLED: 'deleted', expired: 'deleted',
    archived: 'inactive',
};
const BADGE = { active: 'bg-success-transparent', pending: 'bg-warning-transparent', deleted: 'bg-danger-transparent', inactive: 'bg-secondary-transparent', all: 'bg-primary-transparent' };

const tab = ref('subscriptions');
const status = ref('all');
const project = ref('');
const page = ref(1);
const perPage = ref(15);
const rows = ref([]);
const pagination = ref({});
const loading = ref(false);
const countries = ref([]);

const tabs = computed(() => ['subscriptions', 'projects', 'jobs', 'audit'].map((k) => ({ value: k, label: t(`adminMarketing.operations.tabs.${k}`), tone: 'all' })));
const statusOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), tone: 'all' },
    ...(STATUSES[tab.value] ?? []).map((s) => ({ value: s, label: tab.value === 'subscriptions' ? t('adminMarketing.operations.current_only') : statusLabel(s), tone: TONE[s] ?? 'inactive' })),
]);
const statusLabel = (s) => (s && te(`adminMarketing.status.${s}`) ? t(`adminMarketing.status.${s}`) : s || '—');
const jobType = (s) => (s && te(`adminMarketing.job_types.${s}`) ? t(`adminMarketing.job_types.${s}`) : s || '—');
const badge = (s) => BADGE[TONE[s] ?? 'inactive'];
const money = (minor) => (minor == null ? '—' : (Number(minor) / 100).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
// A morph type may be an alias ("user") or a class name: show its last part.
const ownerType = (type) => {
    const key = String(type ?? '').split('\\').pop().toLowerCase();
    return key && te(`adminMarketing.owner_types.${key}`) ? t(`adminMarketing.owner_types.${key}`) : key || '—';
};
const short = (id) => (id ? String(id).slice(0, 8) : '');
const countryLabel = (id) => {
    const c = countries.value.find((x) => x.id === id);
    return c ? `${c.name} (${c.code})` : id ? `#${id}` : '—';
};

function openAudit(uuid) {
    project.value = uuid;
    tab.value = 'audit';
}

let timer;
watch(tab, () => {
    status.value = 'all';
    perPage.value = tab.value === 'audit' ? 30 : 15;
    page.value === 1 ? load() : (page.value = 1);
});
watch([status, perPage], () => (page.value === 1 ? load() : (page.value = 1)));
watch(project, () => {
    if (tab.value !== 'audit') return;
    clearTimeout(timer);
    timer = setTimeout(() => (page.value === 1 ? load() : (page.value = 1)), 350);
});
watch(page, load);

function params() {
    const base = { page: page.value, per_page: perPage.value };
    if (tab.value === 'subscriptions') return { ...base, active: status.value === 'active' ? 1 : undefined };
    if (tab.value === 'audit') return { ...base, project: project.value || undefined };
    return { ...base, status: status.value === 'all' ? undefined : status.value };
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(`${endpoint}/${tab.value}`, { params: params() });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
    } catch (error) {
        rows.value = [];
        pagination.value = {};
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

// Country names for the projects list; the list still works with ids when the catalog is not allowed.
async function loadCountries() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/countries/dropdown');
        countries.value = data.data ?? [];
    } catch {
        // ids are shown instead
    }
}

onMounted(() => {
    load();
    loadCountries();
});
</script>

<style scoped>
.ops-title {
    max-width: 260px;
}
</style>
