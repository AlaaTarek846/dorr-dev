<template>
    <div>
        <WalletPageHeader :title="t('freelance.overview.title')" :section="t('sidebar.freelance')" />

        <div class="alert alert-light border fs-13 d-flex align-items-center gap-2">
            <span class="flex-grow-1">{{ t('freelance.overview.intro') }}</span>
            <button type="button" class="btn btn-sm btn-light btn-wave" :disabled="loading" @click="load">
                <i class="ri-refresh-line me-1"></i>{{ t('freelance.reports.show') }}
            </button>
        </div>

        <!-- What needs a person now: each tile opens the page where it is dealt with. -->
        <div class="fs-13 fw-semibold text-muted mb-2">{{ t('freelance.overview.needs_you') }}</div>
        <div class="row g-3 mb-4">
            <div v-for="tile in attention" :key="tile.key" class="col-xl-3 col-md-4 col-6">
                <router-link :to="{ name: tile.route }" class="text-reset d-block h-100 overview-tile">
                    <FlStatTile :icon="tile.icon" :tone="tile.value > 0 ? tile.tone : 'secondary'" :label="t(`freelance.overview.tiles.${tile.key}`)" :value="loading ? '…' : tile.value" />
                </router-link>
            </div>
            <div v-if="!attention.length" class="col-12">
                <FlEmpty icon="ri-lock-line" :title="t('freelance.overview.no_access_title')" :text="t('freelance.overview.no_access')" />
            </div>
        </div>

        <div v-if="work.length" class="fs-13 fw-semibold text-muted mb-2">{{ t('freelance.overview.work_now') }}</div>
        <div v-if="work.length" class="row g-3 mb-4">
            <div v-for="tile in work" :key="tile.key" class="col-xl-3 col-md-4 col-6">
                <router-link :to="{ name: tile.route }" class="text-reset d-block h-100 overview-tile">
                    <FlStatTile :icon="tile.icon" :tone="tile.tone" :label="t(`freelance.overview.tiles.${tile.key}`)" :value="loading ? '…' : tile.value" />
                </router-link>
            </div>
        </div>

        <!-- Everything in the service, by area. -->
        <div v-for="area in areas" :key="area.key" class="card custom-card">
            <div class="card-header"><div class="card-title"><i :class="area.icon" class="me-1"></i>{{ t(`freelance.overview.areas.${area.key}`) }}</div></div>
            <div class="card-body">
                <div class="row g-2">
                    <div v-for="link in area.links" :key="link.route" class="col-xl-3 col-md-4 col-6">
                        <router-link :to="{ name: link.route }" class="btn btn-light w-100 text-start d-flex align-items-center gap-2 overview-link">
                            <i :class="link.icon" class="fs-16 text-primary"></i>
                            <span class="text-truncate">{{ t(link.label) }}</span>
                        </router-link>
                    </div>
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
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// The Freelance start page: what needs a person now (reviews, late work, disputes, tickets, reports, risk), the work in progress, and a
// door to every page of the service. Each number is read only when the staff member may open its page.
const { t } = useI18n();
const { can } = usePermission();
const { showError } = useToast();
const base = '/api/admin/v1';

const loading = ref(false);
const numbers = ref({});

// key → how to read it. `get` fetches, `pick` takes the number out of the answer.
const SOURCES = [
    { key: 'pending_review', perm: 'freelance-projects.view', url: `${base}/freelance-oversight/projects`, pick: (d) => d.pagination?.counts?.pending_review ?? 0 },
    { key: 'in_progress', perm: 'freelance-projects.view', url: `${base}/freelance-oversight/projects`, pick: (d) => d.pagination?.counts?.in_progress ?? 0 },
    { key: 'agreements_active', perm: 'freelance-projects.view', url: `${base}/freelance-oversight/agreements`, pick: (d) => d.pagination?.counts?.active ?? 0 },
    { key: 'late', perm: 'freelance-projects.view', url: `${base}/freelance-oversight/late`, pick: (d) => d.data?.total ?? 0 },
    { key: 'disputes', perm: 'freelance-disputes.view', url: `${base}/freelance-disputes`, pick: (d) => (d.pagination?.counts?.open ?? 0) + (d.pagination?.counts?.appealed ?? 0) },
    { key: 'tickets', perm: 'freelance-support.view', url: `${base}/freelance-support/tickets`, pick: (d) => (d.pagination?.counts?.opened ?? 0) + (d.pagination?.counts?.reopened ?? 0) },
    { key: 'reports', perm: 'freelance-risk.view', url: `${base}/freelance-risk/overview`, pick: (d) => d.data?.open_reports ?? 0 },
    { key: 'risk', perm: 'freelance-risk.view', url: `${base}/freelance-risk/overview`, pick: (d) => (d.data?.freelancers?.high ?? 0) + (d.data?.clients?.high ?? 0) },
];

const ATTENTION = [
    { key: 'pending_review', route: 'admin.freelance.projects', icon: 'ri-survey-line', tone: 'info', perm: 'freelance-projects.view' },
    { key: 'late', route: 'admin.freelance.late', icon: 'ri-timer-flash-line', tone: 'danger', perm: 'freelance-projects.view' },
    { key: 'disputes', route: 'admin.freelance.disputes', icon: 'ri-scales-3-line', tone: 'danger', perm: 'freelance-disputes.view' },
    { key: 'tickets', route: 'admin.freelance.support', icon: 'ri-customer-service-2-line', tone: 'warning', perm: 'freelance-support.view' },
    { key: 'reports', route: 'admin.freelance.messageReports', icon: 'ri-flag-line', tone: 'warning', perm: 'freelance-risk.view' },
    { key: 'risk', route: 'admin.freelance.risk', icon: 'ri-alarm-warning-line', tone: 'danger', perm: 'freelance-risk.view' },
];
const WORK = [
    { key: 'in_progress', route: 'admin.freelance.oversight', icon: 'ri-loader-4-line', tone: 'primary', perm: 'freelance-projects.view' },
    { key: 'agreements_active', route: 'admin.freelance.oversight', icon: 'ri-file-list-3-line', tone: 'success', perm: 'freelance-projects.view' },
];
const withValue = (list) => list.filter((x) => can(x.perm)).map((x) => ({ ...x, value: numbers.value[x.key] ?? 0 }));
const attention = computed(() => withValue(ATTENTION));
const work = computed(() => withValue(WORK));

const AREAS = [
    { key: 'work', icon: 'ri-briefcase-4-line', links: [
        { route: 'admin.freelance.oversight', perm: 'freelance-projects.view', icon: 'ri-eye-line', label: 'freelance.oversight.title' },
        { route: 'admin.freelance.projects', perm: 'freelance-projects.view', icon: 'ri-survey-line', label: 'freelance.projects.title' },
        { route: 'admin.freelance.late', perm: 'freelance-projects.view', icon: 'ri-timer-flash-line', label: 'freelance.late.title' },
        { route: 'admin.freelance.disputes', perm: 'freelance-disputes.view', icon: 'ri-scales-3-line', label: 'freelance.disputes.title' },
        { route: 'admin.freelance.reviews', perm: 'freelance-reviews.view', icon: 'ri-star-smile-line', label: 'freelance.reviews.title' },
    ] },
    { key: 'people', icon: 'ri-group-line', links: [
        { route: 'admin.freelance.freelancers', perm: 'freelancers.view', icon: 'ri-user-star-line', label: 'freelance.freelancers.title' },
        { route: 'admin.freelance.verifications', perm: 'freelance-verifications.view', icon: 'ri-shield-user-line', label: 'freelance.verifications.title' },
        { route: 'admin.freelance.risk', perm: 'freelance-risk.view', icon: 'ri-alarm-warning-line', label: 'freelance.risk.title' },
        { route: 'admin.freelance.sanctions', perm: 'freelance-sanctions.view', icon: 'ri-shield-user-line', label: 'freelance.sanctions.title' },
        { route: 'admin.freelance.messageReports', perm: 'freelance-message-reports.view', icon: 'ri-flag-line', label: 'freelance.messageReports.title' },
        { route: 'admin.freelance.support', perm: 'freelance-support.view', icon: 'ri-customer-service-2-line', label: 'freelance.support.title' },
    ] },
    { key: 'reports', icon: 'ri-bar-chart-box-line', links: [
        { route: 'admin.freelance.reports', perm: 'freelance-reports.view', icon: 'ri-bar-chart-box-line', label: 'freelance.reports.title' },
        { route: 'admin.freelance.insights', perm: 'freelance-reports.view', icon: 'ri-line-chart-line', label: 'freelance.insights.title' },
        { route: 'admin.freelance.audit', perm: 'freelance-audit.view', icon: 'ri-history-line', label: 'freelance.audit.title' },
    ] },
    { key: 'setup', icon: 'ri-settings-3-line', links: [
        { route: 'admin.freelance.switches', perm: 'freelance-feature-rules.view', icon: 'ri-toggle-line', label: 'freelance.switches.title' },
        { route: 'admin.freelance.countries', perm: 'freelance-projects.view', icon: 'ri-earth-line', label: 'freelance.countries.title' },
        { route: 'admin.freelance.policies', perm: 'freelance-policies.view', icon: 'ri-price-tag-3-line', label: 'freelance.policies.title' },
        { route: 'admin.freelance.document-types', perm: 'freelance-document-types.view', icon: 'ri-id-card-line', label: 'freelance.document_types.title' },
        { route: 'admin.freelance.skills', perm: 'freelance-catalogs.view', icon: 'ri-medal-line', label: 'freelance.skills.title' },
        { route: 'admin.freelance.specialties', perm: 'freelance-catalogs.view', icon: 'ri-node-tree', label: 'freelance.specialties.title' },
    ] },
];
const areas = computed(() => AREAS.map((a) => ({ ...a, links: a.links.filter((l) => can(l.perm)) })).filter((a) => a.links.length));

async function load() {
    loading.value = true;
    // One request per address, shared by the numbers that come from it; a page the person may not open is never asked for.
    const allowed = SOURCES.filter((s) => can(s.perm));
    const urls = [...new Set(allowed.map((s) => s.url))];
    const answers = await Promise.allSettled(urls.map((u) => adminAxios.get(u, { params: { per_page: 1 } })));
    const byUrl = Object.fromEntries(urls.map((u, i) => [u, answers[i]]));
    const next = {};
    for (const s of allowed) {
        const r = byUrl[s.url];
        if (r.status === 'fulfilled') next[s.key] = s.pick(r.value.data);
    }
    numbers.value = next;
    if (answers.some((a) => a.status === 'rejected')) showError(extractApiErrorMessage(answers.find((a) => a.status === 'rejected').reason, t('toast.error')));
    loading.value = false;
}

onMounted(load);
</script>

<style scoped>
.overview-tile {
    text-decoration: none;
    transition: transform 0.15s ease;
}

.overview-tile:hover {
    transform: translateY(-2px);
}

.overview-link {
    min-height: 2.6rem;
}
</style>
