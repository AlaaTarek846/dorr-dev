<template>
    <div>
        <WalletPageHeader :title="t('freelance.late.title')" :section="t('sidebar.freelance')" :total="data ? data.total : null" />

        <div class="alert alert-light border fs-13 d-flex align-items-center gap-2">
            <span class="flex-grow-1">{{ t('freelance.late.hint') }}</span>
            <button type="button" class="btn btn-sm btn-light btn-wave" :disabled="loading" @click="load">
                <i class="ri-refresh-line me-1"></i>{{ t('freelance.reports.show') }}
            </button>
        </div>

        <div class="row g-3 mb-3">
            <div v-for="g in groups" :key="g.key" class="col-xl-2 col-md-4 col-6">
                <FlStatTile :icon="g.icon" :tone="(counts[g.key] ?? 0) ? g.tone : 'secondary'" :label="t(`freelance.late.groups.${g.key}`)" :value="loading ? '…' : (counts[g.key] ?? 0)" />
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-3 py-3">
                <FlStatusFilter v-model="active" :options="filterOptions" />
                <span v-if="data" class="text-muted fs-12 ms-auto">{{ t('freelance.late.checked', { date: formatCatalogDate(data.checked_at, locale) }) }}</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.oversight.project') }}</th>
                                <th>{{ t('freelance.late.what') }}</th>
                                <th>{{ t('freelance.late.since') }}</th>
                                <th class="pe-4">{{ t('freelance.late.late_by') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :layout="['two-lines', 'text', 'text', 'badge']" />
                            <tr v-else-if="!lines.length">
                                <td colspan="4" class="border-0">
                                    <FlEmpty icon="ri-checkbox-circle-line" :title="t('freelance.late.empty_title')" :text="t('freelance.late.empty')" />
                                </td>
                            </tr>
                            <tr v-for="(l, i) in lines" v-else :key="i">
                                <td class="ps-4">
                                    <span class="fw-semibold d-block text-truncate late-title">{{ l.title || '—' }}</span>
                                    <span v-if="l.part" class="text-muted fs-11">{{ l.part }}</span>
                                </td>
                                <td><span class="badge" :class="groupOf(l.key).badge"><i :class="groupOf(l.key).icon" class="me-1"></i>{{ t(`freelance.late.groups.${l.key}`) }}</span></td>
                                <td class="fs-12">{{ formatCatalogDate(l.since, locale) }}</td>
                                <td class="pe-4">
                                    <span v-if="l.days != null" class="badge bg-danger-transparent">{{ t('freelance.late.days', { n: l.days }) }}</span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import FlEmpty from '../../../../../../../components/freelance/FlEmpty.vue';
import FlStatTile from '../../../../../../../components/freelance/FlStatTile.vue';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

// What is late (FL-802): parts past their days, deliveries waiting for the client, agreements nobody answered, disputes past their
// response time and projects waiting for review. Read only; a daily alert tells the staff who watch the projects.
const { t, locale } = useI18n();
const { showError } = useToast();

const groups = [
    { key: 'parts_late', icon: 'ri-timer-flash-line', tone: 'danger', badge: 'bg-danger-transparent' },
    { key: 'deliveries_waiting', icon: 'ri-inbox-unarchive-line', tone: 'warning', badge: 'bg-warning-transparent' },
    { key: 'agreements_unanswered', icon: 'ri-file-warning-line', tone: 'warning', badge: 'bg-warning-transparent' },
    { key: 'disputes_overdue', icon: 'ri-scales-3-line', tone: 'danger', badge: 'bg-danger-transparent' },
    { key: 'projects_waiting_review', icon: 'ri-survey-line', tone: 'info', badge: 'bg-info-transparent' },
];
const groupOf = (key) => groups.find((g) => g.key === key);

const data = ref(null);
const loading = ref(false);
const active = ref('all');

// One list for all five kinds, the longest wait first.
const normalized = computed(() => groups.flatMap((g) => (data.value?.groups?.[g.key] ?? []).map((r) => ({
    key: g.key,
    title: r.title,
    part: r.part ?? null,
    since: r.due_at ?? r.delivered_at ?? r.expired_at ?? r.respond_by ?? r.submitted_at,
    days: r.days_late ?? r.days_waiting ?? daysSince(r.expired_at ?? r.respond_by),
}))));
const daysSince = (value) => (value ? Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 86400000)) : null);
const counts = computed(() => Object.fromEntries(groups.map((g) => [g.key, (data.value?.groups?.[g.key] ?? []).length])));
const lines = computed(() => normalized.value
    .filter((l) => active.value === 'all' || l.key === active.value)
    .sort((a, b) => (b.days ?? -1) - (a.days ?? -1)));
const filterOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), count: data.value?.total ?? 0, tone: 'all' },
    ...groups.map((g) => ({ value: g.key, label: t(`freelance.late.groups.${g.key}`), count: counts.value[g.key], tone: counts.value[g.key] ? 'deleted' : 'inactive' })),
]);

async function load() {
    loading.value = true;
    try {
        const response = await adminAxios.get('/api/admin/v1/freelance-oversight/late');
        data.value = response.data.data ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.late-title {
    max-width: 300px;
}
</style>
