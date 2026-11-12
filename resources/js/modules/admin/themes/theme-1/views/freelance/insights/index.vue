<template>
    <div>
        <WalletPageHeader :title="t('freelance.insights.title')" :section="t('sidebar.freelance')" />

        <div class="alert alert-light border fs-13">{{ t('freelance.insights.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <FlStatusFilter v-model="tab" :options="tabs" />
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <input v-model="from" type="date" class="form-control form-control-sm w-auto" :aria-label="t('freelance.reports.from')">
                    <input v-model="to" type="date" class="form-control form-control-sm w-auto" :aria-label="t('freelance.reports.to')">
                    <button type="button" class="btn btn-sm btn-primary btn-wave" :disabled="loading" @click="load">
                        <i class="ri-refresh-line me-1"></i>{{ t('freelance.reports.show') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- FL-805: how the work is going. -->
        <template v-if="tab === 'operations'">
            <div class="row g-3 mb-3">
                <div v-for="tile in tiles" :key="tile.key" class="col-xl-2 col-md-4 col-6">
                    <FlStatTile :icon="tile.icon" :tone="tile.tone" :label="t(`freelance.insights.tiles.${tile.key}`)" :value="loading || !ops ? '…' : tile.value" :hint="tile.hint" />
                </div>
            </div>
            <div class="row g-3">
                <div v-for="b in breakdowns" :key="b.key" class="col-xl-4 col-md-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header"><div class="card-title">{{ t(`freelance.insights.breakdowns.${b.key}`) }}</div></div>
                        <div class="card-body">
                            <div v-if="loading" class="placeholder-glow"><span class="placeholder col-12 mb-2 d-block"></span><span class="placeholder col-8 d-block"></span></div>
                            <FlEmpty v-else-if="!entries(b.data).length" :title="t('freelance.insights.none')" icon="ri-bar-chart-2-line" />
                            <div v-for="[k, n] in entries(b.data)" v-else :key="k" class="mb-2">
                                <div class="d-flex justify-content-between fs-12"><span>{{ label(b.labels, k) }}</span><span class="fw-semibold" dir="ltr">{{ n }}</span></div>
                                <div class="progress progress-xs"><div class="progress-bar" :class="b.bar" :style="{ width: share(b.data, n) + '%' }"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- FL-808: why people leave. Information only. -->
        <template v-else>
            <div class="row g-3 mb-3">
                <div v-for="side in ['client', 'freelancer']" :key="side" class="col-md-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header">
                            <div class="card-title"><i :class="side === 'client' ? 'ri-user-line' : 'ri-user-star-line'" class="me-1"></i>{{ t(`freelance.insights.cancel_by.${side}`) }}</div>
                        </div>
                        <div class="card-body">
                            <FlEmpty v-if="!loading && !entries(imp?.cancellations?.[`by_${side}`]).length" :title="t('freelance.insights.none')" icon="ri-emotion-happy-line" />
                            <div v-for="[k, n] in entries(imp?.cancellations?.[`by_${side}`])" :key="k" class="mb-2">
                                <div class="d-flex justify-content-between fs-12"><span>{{ t(`freelance.reports.cancel.codes.${k}`, k) }}</span><span class="fw-semibold" dir="ltr">{{ n }}</span></div>
                                <div class="progress progress-xs"><div class="progress-bar bg-danger" :style="{ width: share(imp.cancellations[`by_${side}`], n) + '%' }"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div v-for="side in ['client', 'freelancer']" :key="side" class="col-md-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header d-flex align-items-center">
                            <div class="card-title">{{ t(`freelance.insights.unhappy.${side}`) }}</div>
                            <span v-if="imp" class="badge bg-warning-transparent ms-auto">{{ imp.dissatisfaction[side].not_satisfied }} / {{ imp.dissatisfaction[side].answers }}</span>
                        </div>
                        <div class="card-body">
                            <FlEmpty v-if="!loading && !entries(imp?.dissatisfaction?.[side]?.reasons).length" :title="t('freelance.insights.none')" icon="ri-emotion-happy-line" />
                            <div v-for="[k, n] in entries(imp?.dissatisfaction?.[side]?.reasons)" :key="k" class="mb-2">
                                <div class="d-flex justify-content-between fs-12"><span>{{ t(`freelance.reviews.survey_reasons.${k}`, k) }}</span><span class="fw-semibold" dir="ltr">{{ n }}</span></div>
                                <div class="progress progress-xs"><div class="progress-bar bg-warning" :style="{ width: share(imp.dissatisfaction[side].reasons, n) + '%' }"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-3">
                <div v-for="g in turnedDown" :key="g.key" class="col-xl-4 col-md-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header"><div class="card-title">{{ t(`freelance.insights.turned_down.${g.key}`) }}</div></div>
                        <div class="card-body">
                            <FlEmpty v-if="!loading && !entries(g.data).length" :title="t('freelance.insights.none')" icon="ri-emotion-happy-line" />
                            <div v-for="[k, n] in entries(g.data)" :key="k" class="d-flex justify-content-between fs-13 py-1 border-bottom">
                                <span>{{ t(`freelance.oversight.status.${k}`, k) }}</span><span class="fw-semibold" dir="ltr">{{ n }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import FlEmpty from '../../../../../../../components/freelance/FlEmpty.vue';
import FlStatTile from '../../../../../../../components/freelance/FlStatTile.vue';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';

// Operations (FL-805) and improvement (FL-808) for a period, from what is already kept. The money is on the Reports page. Information only.
const { t } = useI18n();
const { showError } = useToast();
const endpoint = '/api/admin/v1/freelance-reports';

const today = new Date();
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const from = ref(iso(new Date(today.getFullYear(), today.getMonth(), 1)));
const to = ref(iso(today));
const tab = ref('operations');
const ops = ref(null);
const imp = ref(null);
const loading = ref(false);

const tabs = computed(() => ['operations', 'improvement'].map((k) => ({ value: k, label: t(`freelance.insights.tabs.${k}`), tone: 'all' })));
const entries = (obj) => Object.entries(obj ?? {}).sort((a, b) => b[1] - a[1]);
const share = (obj, n) => {
    const max = Math.max(...Object.values(obj ?? {}), 1);
    return Math.round((n * 100) / max);
};
const label = (labels, k) => (labels === 'status' ? t(`freelance.oversight.status.${k}`, k) : labels === 'milestone' ? t(`freelance.insights.milestone.${k}`, k) : t(`freelance.disputeCategory.${k || 'none'}`, k));
const hours = (h) => (h == null ? '—' : h >= 48 ? t('freelance.late.days', { n: Math.round(h / 24) }) : t('freelance.insights.hours', { n: h }));

const tiles = computed(() => [
    { key: 'projects', icon: 'ri-survey-line', tone: 'primary', value: ops.value?.projects_created },
    { key: 'started', icon: 'ri-play-circle-line', tone: 'info', value: ops.value?.agreements_started },
    { key: 'completed', icon: 'ri-checkbox-circle-line', tone: 'success', value: ops.value?.agreements_completed },
    { key: 'disputes', icon: 'ri-scales-3-line', tone: 'danger', value: ops.value?.disputes_opened },
    { key: 'first_offer', icon: 'ri-timer-line', tone: 'warning', value: hours(ops.value?.avg_hours_to_first_offer) },
    { key: 'hire', icon: 'ri-user-follow-line', tone: 'secondary', value: hours(ops.value?.avg_hours_to_hire) },
]);
const breakdowns = computed(() => [
    { key: 'projects', data: ops.value?.projects_by_status, labels: 'status', bar: 'bg-primary' },
    { key: 'offers', data: ops.value?.offers_by_status, labels: 'status', bar: 'bg-info' },
    { key: 'agreements', data: ops.value?.agreements_by_status, labels: 'status', bar: 'bg-success' },
    { key: 'milestones', data: ops.value?.milestones_by_status, labels: 'milestone', bar: 'bg-secondary' },
    { key: 'disputes', data: ops.value?.disputes_by_category, labels: 'category', bar: 'bg-danger' },
]);
const turnedDown = computed(() => [
    { key: 'offers', data: imp.value?.offers },
    { key: 'invitations', data: imp.value?.invitations },
    { key: 'projects', data: imp.value?.projects_ended_without_work },
]);

watch(tab, load);

async function load() {
    loading.value = true;
    try {
        const params = { from: from.value || undefined, to: to.value || undefined };
        if (tab.value === 'operations') ops.value = (await adminAxios.get(`${endpoint}/operations`, { params })).data.data ?? null;
        else imp.value = (await adminAxios.get(`${endpoint}/improvement`, { params })).data.data ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>
