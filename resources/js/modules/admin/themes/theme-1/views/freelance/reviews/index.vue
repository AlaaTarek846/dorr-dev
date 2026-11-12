<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.reviews.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.reviews.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <ul class="nav nav-pills mb-3 gap-1">
            <li v-for="tab in ['objections', 'satisfaction']" :key="tab" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: view === tab }" @click="view = tab">{{ t(`freelance.reviews.tabs.${tab}`) }}</button>
            </li>
        </ul>

        <!-- Objections to ratings (FL-706): keep it, hide its comment, or take the rating out of the numbers. Information only: no account is touched. -->
        <div v-if="view === 'objections'" class="card custom-card">
            <div class="card-header d-flex flex-wrap gap-2 py-3">
                <button v-for="s in statuses" :key="s" type="button" class="btn btn-sm btn-wave" :class="status === s ? 'btn-primary' : 'btn-light'" @click="setStatus(s)">
                    {{ t(`freelance.reviews.status.${s}`) }} <span class="badge bg-white text-default ms-1">{{ counts[s] ?? 0 }}</span>
                </button>
            </div>
            <div class="alert alert-light border fs-12 m-3 mb-0">{{ t('freelance.reviews.hint') }}</div>
            <div class="table-responsive">
                <table class="table text-nowrap mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-4">{{ t('freelance.reviews.rating') }}</th>
                            <th>{{ t('freelance.reviews.objection') }}</th>
                            <th>{{ t('freelance.reviews.people') }}</th>
                            <th class="pe-4">{{ t('freelance.reviews.decision') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <TableSkeleton v-if="loading" :rows="3" :layout="['two-lines', 'two-lines', 'two-lines', 'text']" />
                        <tr v-else-if="!rows.length"><td colspan="4" class="text-center text-muted py-5">{{ t('freelance.reviews.empty') }}</td></tr>
                        <tr v-for="r in rows" v-else :key="r.uuid">
                            <td class="ps-4 text-wrap" style="max-width: 300px">
                                <span class="d-block fw-semibold">{{ r.project }} · {{ r.rating.stars }} ★</span>
                                <span class="d-block fs-12" :class="{ 'text-decoration-line-through text-muted': r.rating.comment_hidden }">{{ r.rating.comment || '—' }}</span>
                                <span class="text-muted fs-11" dir="ltr">{{ Object.entries(r.rating.scores).map(([k, v]) => `${k} ${v}`).join(' · ') }}</span>
                            </td>
                            <td class="text-wrap" style="max-width: 280px">
                                <span class="badge bg-warning-transparent">{{ t(`freelance.reviews.reasons.${r.reason}`) }}</span>
                                <span class="d-block fs-12 mt-1">{{ r.note }}</span>
                            </td>
                            <td class="fs-12">
                                <span class="d-block">{{ t('freelance.reviews.rater') }}: {{ r.rater }} ({{ t(`freelance.reviews.side.${r.rater_side}`) }})</span>
                                <span class="d-block">{{ t('freelance.reviews.about') }}: {{ r.about }}</span>
                            </td>
                            <td class="pe-4">
                                <button v-if="r.status === 'open' && canDecide" type="button" class="btn btn-sm btn-primary btn-wave" @click="open(r)">{{ t('freelance.reviews.review') }}</button>
                                <template v-else-if="r.status !== 'open'">
                                    <span class="badge bg-light text-default">{{ t(`freelance.reviews.status.${r.status}`) }}</span>
                                    <span v-if="r.decision_note" class="d-block text-muted fs-11 text-wrap">{{ r.decision_note }}</span>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Satisfaction after a project (FL-702). -->
        <div v-else class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <input v-model="from" type="date" class="form-control form-control-sm w-auto" :aria-label="t('freelance.reports.from')" />
                <input v-model="to" type="date" class="form-control form-control-sm w-auto" :aria-label="t('freelance.reports.to')" />
                <button type="button" class="btn btn-sm btn-primary btn-wave" :disabled="loadingSurvey" @click="loadSurvey">
                    <i class="ri-refresh-line me-1"></i>{{ t('freelance.reports.show') }}
                </button>
            </div>
            <div v-if="survey" class="card-body">
                <div class="d-flex flex-wrap gap-4 mb-3">
                    <div><div class="text-muted fs-12">{{ t('freelance.reviews.answers') }}</div><div class="fs-20 fw-semibold">{{ survey.total }}</div></div>
                    <div><div class="text-muted fs-12">{{ t('freelance.reviews.average') }}</div><div class="fs-20 fw-semibold">{{ survey.average ?? '—' }}</div></div>
                    <div><div class="text-muted fs-12">{{ t('freelance.reviews.not_satisfied') }}</div><div class="fs-20 fw-semibold">{{ survey.not_satisfied }}</div></div>
                    <div v-for="side in ['client', 'freelancer']" :key="side">
                        <div class="text-muted fs-12">{{ t(`freelance.reviews.side.${side}`) }}</div>
                        <div class="fs-14 fw-semibold">{{ survey.by_side[side].average ?? '—' }} <span class="text-muted fs-12">({{ survey.by_side[side].count }})</span></div>
                    </div>
                </div>
                <div class="d-flex gap-2 mb-4 align-items-end">
                    <div v-for="d in survey.distribution" :key="d.score" class="text-center">
                        <div class="bg-primary-transparent rounded" :style="{ height: `${barHeight(d.count)}px`, width: '34px' }"></div>
                        <div class="fs-12">{{ d.score }}★</div>
                        <div class="text-muted fs-11">{{ d.count }}</div>
                    </div>
                </div>

                <h6 class="fw-semibold">{{ t('freelance.reviews.why') }}</h6>
                <table class="table table-sm mb-4" style="max-width: 460px">
                    <thead><tr><th>{{ t('freelance.reviews.reason') }}</th><th>{{ t('freelance.reviews.side.client') }}</th><th>{{ t('freelance.reviews.side.freelancer') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="r in survey.reasons" :key="r.key"><td>{{ t(`freelance.reviews.survey_reasons.${r.key}`) }}</td><td>{{ r.client }}</td><td>{{ r.freelancer }}</td></tr>
                    </tbody>
                </table>

                <h6 class="fw-semibold">{{ t('freelance.reviews.latest') }}</h6>
                <p v-if="!survey.latest.length" class="text-muted fs-13">{{ t('freelance.reviews.empty') }}</p>
                <div v-for="(l, n) in survey.latest" :key="n" class="border-bottom py-2 fs-13">
                    <span class="fw-semibold">{{ l.project }}</span> · {{ t(`freelance.reviews.side.${l.side}`) }} · {{ l.score }}★
                    <span class="text-muted fs-11" dir="ltr">{{ (l.at || '').slice(0, 10) }}</span>
                    <div class="fs-12">{{ l.reasons.map((x) => t(`freelance.reviews.survey_reasons.${x}`)).join('، ') }}</div>
                    <div v-if="l.comment" class="text-muted fs-12">{{ l.comment }}</div>
                </div>
            </div>
        </div>

        <div v-if="current" class="modal fade show d-block" tabindex="-1" style="background: rgba(0, 0, 0, 0.45)" @click.self="current = null">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">{{ t('freelance.reviews.review') }}</h6>
                        <button type="button" class="btn-close" :aria-label="t('close')" @click="current = null"></button>
                    </div>
                    <div class="modal-body">
                        <p class="border rounded p-2 fs-13 text-wrap mb-2">{{ current.rating.comment || '—' }}</p>
                        <p class="fs-12 text-wrap"><strong>{{ t(`freelance.reviews.reasons.${current.reason}`) }}:</strong> {{ current.note }}</p>
                        <div class="d-grid gap-2 mb-3">
                            <button v-for="d in decisions" :key="d.key" type="button" class="btn btn-sm text-start" :class="decision === d.key ? d.active : 'btn-light'" @click="decision = d.key">
                                <strong>{{ t(`freelance.reviews.decisions.${d.key}`) }}</strong>
                                <span class="d-block fs-11 opacity-75 text-wrap">{{ t(`freelance.reviews.decisions.${d.key}_hint`) }}</span>
                            </button>
                        </div>
                        <textarea v-model="note" class="form-control" rows="2" maxlength="500" :placeholder="t('freelance.reviews.note')"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="current = null">{{ t('cancel') }}</button>
                        <button type="button" class="btn btn-primary" :disabled="!decision || saving" @click="save">{{ t('freelance.reviews.save') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Ratings and satisfaction (FL-706 / FL-702): objections to ratings for a person to decide, and how satisfied people are after a project and why not.
const { t } = useI18n();
const { showSuccess, showError } = useToast();
const { can } = usePermission();
const endpoint = '/api/admin/v1/freelance-reviews';
const canDecide = computed(() => can('freelance-reviews.update'));
const statuses = ['open', 'rejected', 'comment_hidden', 'rating_removed'];
const decisions = [
    { key: 'reject', active: 'btn-success' },
    { key: 'hide_comment', active: 'btn-warning' },
    { key: 'remove_rating', active: 'btn-danger' },
];

const view = ref('objections');
const status = ref('open');
const rows = ref([]);
const counts = ref({});
const loading = ref(false);
const current = ref(null);
const decision = ref('');
const note = ref('');
const saving = ref(false);

const today = new Date();
const iso = (d) => d.toISOString().slice(0, 10);
const from = ref(iso(new Date(today.getFullYear(), today.getMonth(), 1)));
const to = ref(iso(today));
const survey = ref(null);
const loadingSurvey = ref(false);
const barHeight = (n) => {
    const max = Math.max(1, ...(survey.value?.distribution ?? []).map((d) => d.count));
    return 6 + Math.round((n / max) * 70);
};

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(`${endpoint}/objections`, { params: { status: status.value } });
        rows.value = data.data ?? [];
        counts.value = data.pagination?.counts ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function loadSurvey() {
    loadingSurvey.value = true;
    try {
        const { data } = await adminAxios.get(`${endpoint}/satisfaction`, { params: { from: from.value || undefined, to: to.value || undefined } });
        survey.value = data.data ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loadingSurvey.value = false;
    }
}

function setStatus(s) {
    status.value = s;
    load();
}

function open(r) {
    current.value = r;
    decision.value = '';
    note.value = '';
}

async function save() {
    saving.value = true;
    try {
        await adminAxios.post(`${endpoint}/objections/${current.value.uuid}/decide`, { decision: decision.value, note: note.value || undefined });
        current.value = null;
        showSuccess(t('freelance.reviews.saved'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

watch(view, (v) => { if (v === 'satisfaction' && !survey.value) loadSurvey(); });
onMounted(load);
</script>
