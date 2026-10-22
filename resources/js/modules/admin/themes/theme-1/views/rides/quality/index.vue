<template>
    <div>
        <WalletPageHeader :title="t('rides.quality.title')" :section="t('sidebar.rides')" />

        <div class="card custom-card mb-3">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm">
                    <button v-for="d in [7, 30, 90]" :key="d" type="button" class="btn" :class="days === d ? 'btn-primary' : 'btn-outline-primary'" @click="days = d">
                        {{ t('rides.quality.days', { n: d }) }}
                    </button>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary ms-auto" :disabled="explaining || !report" @click="explain">
                    <span v-if="explaining" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-sparkling-2-line me-1"></i>{{ t('rides.quality.explain') }}
                </button>
            </div>
            <div v-if="loading" class="card-body text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>
            <div v-else-if="report" class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3"><div class="p-3 rounded bg-light"><div class="fs-12 text-muted">{{ t('rides.quality.completed') }}</div><div class="fs-20 fw-bold">{{ report.completed }}</div></div></div>
                    <div class="col-6 col-lg-3"><div class="p-3 rounded bg-light"><div class="fs-12 text-muted">{{ t('rides.quality.late_share') }}</div><div class="fs-20 fw-bold" :class="(report.arrivals.late_share ?? 0) > 0.2 ? 'text-danger' : ''">{{ pct(report.arrivals.late_share) }}</div><div class="fs-11 text-muted">{{ t('rides.quality.late_of', { late: report.arrivals.late, of: report.arrivals.count }) }}</div></div></div>
                    <div class="col-6 col-lg-3"><div class="p-3 rounded bg-light"><div class="fs-12 text-muted">{{ t('rides.quality.avg_delay') }}</div><div class="fs-20 fw-bold">{{ Math.round((report.arrivals.avg_delay_s || 0) / 60) }} {{ t('rides.cases.min') }}</div></div></div>
                    <div class="col-6 col-lg-3"><div class="p-3 rounded bg-light"><div class="fs-12 text-muted">{{ t('rides.quality.rating') }}</div><div class="fs-20 fw-bold">{{ report.ratings.avg ?? '—' }} <span class="fs-13 text-warning">★</span></div><div class="fs-11 text-muted">{{ t('rides.quality.low_of', { low: report.ratings.low, of: report.ratings.count }) }}</div></div></div>
                </div>

                <div v-if="reading" class="alert alert-primary fs-13">
                    <div class="fw-bold mb-1"><i class="ri-sparkling-2-line me-1"></i>{{ t('rides.quality.reading') }}</div>
                    <p class="mb-2">{{ reading.summary }}</p>
                    <div class="row">
                        <div class="col-md-6"><div class="fw-semibold">{{ t('rides.quality.causes') }}</div><ul class="mb-0 ps-3"><li v-for="(c, i) in reading.causes" :key="i">{{ c }}</li></ul></div>
                        <div class="col-md-6"><div class="fw-semibold">{{ t('rides.quality.actions') }}</div><ul class="mb-0 ps-3"><li v-for="(a, i) in reading.actions" :key="i">{{ a }}</li></ul></div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold">{{ t('rides.quality.low_tags') }}</h6>
                        <div v-if="!report.ratings.tags.length" class="text-muted fs-13">—</div>
                        <div v-for="tag in report.ratings.tags" :key="tag.tag" class="d-flex align-items-center gap-2 mb-1 fs-13">
                            <span class="flex-grow-1">{{ tag.tag }}</span><span class="badge bg-warning-transparent">{{ tag.count }}</span>
                        </div>
                        <h6 class="fw-semibold mt-3">{{ t('rides.quality.cancels') }}</h6>
                        <div v-if="!report.cancels.length" class="text-muted fs-13">—</div>
                        <div v-for="(c, i) in report.cancels" :key="i" class="d-flex align-items-center gap-2 mb-1 fs-13">
                            <span class="badge bg-light text-dark">{{ c.actor || '—' }}</span><span class="flex-grow-1">{{ c.reason || t('rides.quality.no_reason') }}</span><span class="badge bg-secondary-transparent">{{ c.count }}</span>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="fw-semibold">{{ t('rides.quality.cities') }}</h6>
                        <table class="table table-sm fs-13 mb-3">
                            <thead><tr><th>{{ t('rides.quality.city') }}</th><th>{{ t('rides.quality.arrivals') }}</th><th>{{ t('rides.quality.late_share') }}</th><th>★</th></tr></thead>
                            <tbody><tr v-for="c in report.cities" :key="c.city_id ?? 'none'"><td>{{ c.name || '—' }}</td><td>{{ c.arrivals }}</td><td>{{ pct(c.late_share) }}</td><td>{{ c.avg_rating ?? '—' }}</td></tr></tbody>
                        </table>
                        <h6 class="fw-semibold">{{ t('rides.quality.drivers') }}</h6>
                        <table class="table table-sm fs-13 mb-0">
                            <thead><tr><th>{{ t('rides.quality.driver') }}</th><th>{{ t('rides.quality.late_share') }}</th><th>{{ t('rides.quality.avg_delay') }}</th><th>{{ t('rides.quality.low_ratings') }}</th></tr></thead>
                            <tbody>
                                <tr v-for="d in report.drivers" :key="d.driver_id">
                                    <td><router-link :to="{ name: 'admin.rides.drivers.show', params: { id: d.driver_id } }">{{ d.name || d.driver_id }}</router-link></td>
                                    <td>{{ pct(d.late_share) }} <span class="text-muted fs-11">({{ d.late }}/{{ d.arrivals }})</span></td>
                                    <td>{{ Math.round(d.avg_delay_s / 60) }} {{ t('rides.cases.min') }}</td>
                                    <td>{{ d.low_ratings }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI-06: rides with fraud signs, strongest first. -->
        <div class="card custom-card">
            <div class="card-header py-3"><div class="card-title"><i class="ri-spy-line me-1"></i>{{ t('rides.quality.risk_title') }}</div></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle fs-13">
                        <thead><tr><th class="ps-4">{{ t('rides.rides.ride') }}</th><th>{{ t('rides.quality.score') }}</th><th>{{ t('rides.quality.signs') }}</th><th>{{ t('rides.quality.driver') }}</th><th>{{ t('rides.quality.rider') }}</th></tr></thead>
                        <tbody>
                            <tr v-if="!risky.length"><td colspan="5" class="text-center text-muted py-4"><i class="ri-shield-check-line fs-24 d-block mb-1 text-success"></i>{{ t('rides.quality.risk_empty') }}</td></tr>
                            <tr v-for="r in risky" :key="r.id">
                                <td class="ps-4"><router-link :to="{ name: 'admin.rides.rides.show', params: { id: r.id } }">{{ r.number }}</router-link><div class="fs-11 text-muted">{{ formatDate(r.completed_at) }}</div></td>
                                <td><span class="badge" :class="r.risk_score >= 60 ? 'bg-danger' : 'bg-warning'">{{ r.risk_score }}</span></td>
                                <td class="text-wrap"><span v-for="f in r.flags" :key="f.code" class="badge bg-danger-transparent me-1 mb-1">{{ f.label }}</span></td>
                                <td>{{ r.driver?.name || '—' }}</td>
                                <td>{{ r.rider?.name || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';

// Block 06 — why captains arrive late and riders rate low (AI-19), DORR AI's reading of it on request, and the
// rides with fraud signs (AI-06). Loads on open and when the period changes.
const { t, locale } = useI18n();
const { showError } = useToast();
const days = ref(30);
const report = ref(null);
const reading = ref(null);
const risky = ref([]);
const loading = ref(false);
const explaining = ref(false);
const pct = (v) => (v == null ? '—' : `${Math.round(v * 100)}%`);
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) : '');

async function load() {
    loading.value = true;
    reading.value = null;
    try {
        const [q, r] = await Promise.all([
            adminAxios.get('/api/admin/v1/rides-quality', { params: { days: days.value } }),
            adminAxios.get('/api/admin/v1/rides-risk', { params: { days: days.value } }),
        ]);
        report.value = q.data.data;
        risky.value = r.data.data.items;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

async function explain() {
    explaining.value = true;
    try {
        const { data } = await adminAxios.post('/api/admin/v1/rides-quality/explain', { days: days.value });
        reading.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        explaining.value = false;
    }
}

watch(days, load);
onMounted(load);
</script>
