<template>
    <div>
        <WalletPageHeader :title="t('freelance.risk.title')" :section="t('sidebar.freelance')" :total="pagination.total ?? null" />

        <div class="alert alert-light border fs-13">{{ t('freelance.risk.hint') }}</div>

        <div class="row g-3 mb-3">
            <div class="col-xl-2 col-md-4 col-6"><FlStatTile icon="ri-user-star-line" tone="danger" :label="t('freelance.risk.freelancers_high')" :value="overview ? (overview.freelancers.high ?? 0) : '…'" /></div>
            <div class="col-xl-2 col-md-4 col-6"><FlStatTile icon="ri-user-line" tone="danger" :label="t('freelance.risk.clients_high')" :value="overview ? (overview.clients.high ?? 0) : '…'" /></div>
            <div class="col-xl-2 col-md-4 col-6"><FlStatTile icon="ri-alert-line" tone="warning" :label="t('freelance.risk.medium')" :value="overview ? (overview.freelancers.medium ?? 0) + (overview.clients.medium ?? 0) : '…'" /></div>
            <div class="col-xl-3 col-md-6 col-6"><FlStatTile icon="ri-flag-line" tone="info" :label="t('freelance.risk.open_reports')" :value="overview ? overview.open_reports : '…'" :hint="reportsHint" /></div>
            <div class="col-xl-3 col-md-6 col-6"><FlStatTile icon="ri-shield-user-line" tone="primary" :label="t('freelance.risk.active_sanctions')" :value="overview ? overview.active_sanctions : '…'" /></div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <FlStatusFilter v-model="side" :options="sideOptions" />
                    <FlStatusFilter v-model="level" :options="levelOptions" />
                </div>
                <div class="btn-list">
                    <router-link v-if="can('freelance-message-reports.view')" :to="{ name: 'admin.freelance.messageReports' }" class="btn btn-sm btn-outline-primary btn-wave">
                        <i class="ri-flag-line me-1"></i>{{ t('freelance.messageReports.title') }}
                    </router-link>
                    <router-link v-if="can('freelance-sanctions.view')" :to="{ name: 'admin.freelance.sanctions' }" class="btn btn-sm btn-outline-secondary btn-wave">
                        <i class="ri-shield-user-line me-1"></i>{{ t('freelance.sanctions.title') }}
                    </router-link>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.risk.account') }}</th>
                                <th>{{ t('freelance.risk.level') }}</th>
                                <th>{{ t('freelance.risk.score') }}</th>
                                <th>{{ t('freelance.risk.why') }}</th>
                                <th class="pe-4">{{ t('freelance.risk.now') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="['avatar-text', 'badge', 'text', 'two-lines', 'two-lines']" />
                            <tr v-else-if="!rows.length">
                                <td colspan="5" class="border-0">
                                    <FlEmpty icon="ri-shield-check-line" :title="t('freelance.risk.empty_title')" :text="t('freelance.risk.empty')" />
                                </td>
                            </tr>
                            <tr v-for="row in rows" v-else :key="`${row.side}-${row.id}`">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-sm avatar-rounded" :class="row.side === 'freelancer' ? 'bg-success-transparent' : 'bg-primary-transparent'">
                                            <i :class="row.side === 'freelancer' ? 'ri-user-star-line text-success' : 'ri-user-line text-primary'"></i>
                                        </span>
                                        <div>
                                            <span class="fw-semibold d-block">{{ row.name }}</span>
                                            <span class="text-muted fs-11">{{ t(`freelance.risk.side.${row.side}`) }} · #{{ row.id }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge" :class="row.risk_level === 'high' ? 'bg-danger-transparent' : 'bg-warning-transparent'">{{ t(`freelance.risk.levels.${row.risk_level}`) }}</span></td>
                                <td dir="ltr">{{ row.quality_score ?? '—' }}</td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1 reasons">
                                        <span v-for="r in row.reasons" :key="r.code" class="badge fs-10" :class="r.level === 'high' ? 'bg-danger-transparent' : r.level === 'medium' ? 'bg-warning-transparent' : 'bg-light text-muted'">
                                            {{ t(`freelance.risk.reasons.${r.code}`, r.code) }}<template v-if="r.count != null"> · {{ r.count }}</template>
                                        </span>
                                        <span v-if="!row.reasons.length" class="text-muted">—</span>
                                    </div>
                                </td>
                                <td class="pe-4 fs-12">
                                    <span class="d-block">{{ t('freelance.risk.sanctions_now', { n: row.active_sanctions }) }}</span>
                                    <span class="d-block" :class="row.open_reports_against ? 'text-danger' : 'text-muted'">{{ t('freelance.risk.reports_now', { n: row.open_reports_against }) }}</span>
                                </td>
                            </tr>
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
import FlStatTile from '../../../../../../../components/freelance/FlStatTile.vue';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// The risk desk (FL-807): the accounts the quality rules put at medium or high risk, with the reasons, and what is open against them.
// It only shows; a warning, limit or suspension is issued on the Sanctions page, a report is decided on Message reports.
const { t } = useI18n();
const { can } = usePermission();
const { showError } = useToast();
const endpoint = '/api/admin/v1/freelance-risk';

const side = ref('all');
const level = ref('all');
const page = ref(1);
const perPage = ref(15);
const rows = ref([]);
const pagination = ref({});
const overview = ref(null);
const loading = ref(false);

const sideOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), tone: 'all' },
    { value: 'freelancer', label: t('freelance.risk.side.freelancer'), tone: 'active' },
    { value: 'client', label: t('freelance.risk.side.client'), tone: 'pending' },
]);
const levelOptions = computed(() => [
    { value: 'all', label: t('freelance.risk.any_level'), tone: 'all' },
    { value: 'high', label: t('freelance.risk.levels.high'), tone: 'deleted' },
    { value: 'medium', label: t('freelance.risk.levels.medium'), tone: 'pending' },
]);
const reportsHint = computed(() => Object.entries(overview.value?.open_reports_by_reason ?? {}).map(([k, n]) => `${t(`freelance.messageReports.reasons.${k}`, k)} ${n}`).join(' · '));

watch([side, level, perPage], () => (page.value === 1 ? load() : (page.value = 1)));
watch(page, load);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(`${endpoint}/accounts`, { params: { page: page.value, per_page: perPage.value, side: side.value === 'all' ? undefined : side.value, level: level.value === 'all' ? undefined : level.value } });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function loadOverview() {
    try {
        overview.value = (await adminAxios.get(`${endpoint}/overview`)).data.data ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

onMounted(() => {
    load();
    loadOverview();
});
</script>

<style scoped>
.reasons {
    max-width: 380px;
    white-space: normal;
}
</style>
