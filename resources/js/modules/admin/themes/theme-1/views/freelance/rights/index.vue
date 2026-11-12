<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.rights.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.rights.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border fs-13">{{ t('freelance.rights.hint') }}</div>

        <!-- The numbers at a glance. -->
        <div class="row g-3 mb-3">
            <div v-for="k in kpis" :key="k.key" class="col-12 col-sm-6 col-xl-3">
                <div class="card custom-card mb-0">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <span class="avatar avatar-md rounded-3" :class="k.tone"><i :class="k.icon" class="fs-18"></i></span>
                        <div>
                            <div class="fs-20 fw-semibold lh-1">{{ k.value }}</div>
                            <div class="text-muted fs-12 mt-1">{{ t(`freelance.rights.kpi.${k.key}`) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- How the agreements split over the three models. -->
        <div v-if="overview" class="card custom-card">
            <div class="card-body py-3">
                <div class="fw-semibold fs-13 mb-2">{{ t('freelance.rights.split') }}</div>
                <div v-if="!overview.agreements.with_terms" class="text-muted fs-12">{{ t('freelance.rights.split_empty') }}</div>
                <template v-else>
                    <div class="progress progress-lg mb-2" style="height: 14px">
                        <div v-for="m in models" :key="m" class="progress-bar" :class="tone[m]" role="progressbar" :style="{ width: share(m) + '%' }" :title="`${t(`freelance.rights.models.${m}`)}: ${count(m)}`"></div>
                    </div>
                    <div class="d-flex flex-wrap gap-3 fs-12">
                        <span v-for="m in models" :key="m"><i class="ri-circle-fill me-1" :class="dot[m]"></i>{{ t(`freelance.rights.models.${m}`) }} <span class="fw-semibold">{{ count(m) }}</span> <span class="text-muted">({{ share(m) }}%)</span></span>
                    </div>
                </template>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <FlStatusFilter v-model="tab" :options="tabs" />
            </div>

            <!-- Standard texts: one per model, per country; the agreement keeps the text of the day it was made. -->
            <div v-if="tab === 'texts'" class="card-body">
                <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
                    <div>
                        <label class="form-label fs-12 fw-semibold mb-1">{{ t('freelance.rights.country') }}</label>
                        <select v-model="countryId" class="form-select form-select-sm" style="min-width: 220px">
                            <option value="">{{ t('freelance.rights.all_countries') }}</option>
                            <option v-for="c in countries" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                        </select>
                    </div>
                    <FlStatusFilter v-model="model" :options="modelOptions" />
                </div>
                <div class="text-muted fs-12 mb-3">{{ t('freelance.rights.texts_hint') }}</div>

                <div v-if="textsLoading" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>{{ t('freelance.rights.loading') }}</div>
                <div v-else-if="form">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span v-if="form.custom" class="badge bg-primary-transparent">{{ t('freelance.rights.custom', { n: form.version }) }}</span>
                        <span v-else class="badge bg-secondary-transparent">{{ t(form.fallback === 'general' ? 'freelance.rights.from_general' : 'freelance.rights.from_platform') }}</span>
                        <span v-if="form.updated_at" class="text-muted fs-11">{{ when(form.updated_at) }}</span>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-6" dir="rtl">
                            <label class="form-label fs-12 fw-semibold">{{ t('freelance.rights.title_ar') }}</label>
                            <input v-model="form.title_ar" type="text" class="form-control mb-2" maxlength="120" :disabled="!canUpdate">
                            <label class="form-label fs-12 fw-semibold">{{ t('freelance.rights.body_ar') }}</label>
                            <textarea v-model="form.body_ar" class="form-control" rows="11" maxlength="6000" :disabled="!canUpdate"></textarea>
                        </div>
                        <div class="col-lg-6" dir="ltr">
                            <label class="form-label fs-12 fw-semibold">{{ t('freelance.rights.title_en') }}</label>
                            <input v-model="form.title_en" type="text" class="form-control mb-2" maxlength="120" :disabled="!canUpdate">
                            <label class="form-label fs-12 fw-semibold">{{ t('freelance.rights.body_en') }}</label>
                            <textarea v-model="form.body_en" class="form-control" rows="11" maxlength="6000" :disabled="!canUpdate"></textarea>
                        </div>
                    </div>
                    <div class="alert alert-warning fs-12 mt-3 mb-0"><i class="ri-scales-3-line me-1"></i>{{ t('freelance.rights.legal_note') }}</div>
                    <div v-if="canUpdate" class="d-flex flex-wrap gap-2 mt-3">
                        <button type="button" class="btn btn-primary" :disabled="saving || !formValid" @click="save">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                        </button>
                        <button v-if="form.custom" type="button" class="btn btn-light" :disabled="saving" @click="reset">
                            <i class="ri-restart-line me-1"></i>{{ t('freelance.rights.reset') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Reports of works someone says are theirs. -->
            <template v-else>
                <div class="card-body border-bottom py-2">
                    <FlStatusFilter v-model="status" :options="statusOptions" />
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ t('freelance.rights.subject') }}</th>
                                    <th>{{ t('freelance.rights.freelancer') }}</th>
                                    <th>{{ t('freelance.rights.reporter') }}</th>
                                    <th>{{ t('freelance.rights.reported') }}</th>
                                    <th>{{ t('freelance.rights.status') }}</th>
                                    <th class="text-end pe-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <TableSkeleton v-if="loading" :rows="5" :layout="['two-lines', 'text', 'two-lines', 'text', 'badge', 'actions']" />
                                <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('freelance.rights.empty') }}</td></tr>
                                <tr v-for="c in rows" v-else :key="c.uuid">
                                    <td class="ps-4">
                                        <span class="d-block fw-semibold cell-title text-truncate">{{ c.subject_title }}</span>
                                        <span class="text-muted fs-11">{{ t(`freelance.rights.subject_type.${c.subject_type}`) }}</span>
                                    </td>
                                    <td>{{ c.freelancer?.name || '—' }}</td>
                                    <td>
                                        <span class="d-block">{{ c.reporter.name || '—' }}</span>
                                        <span class="badge bg-light text-default fs-10">{{ t(`freelance.rights.reporter_type.${c.reporter.type}`) }}</span>
                                    </td>
                                    <td class="fs-12">{{ when(c.created_at) }}</td>
                                    <td><span class="badge" :class="badge[c.status]">{{ t(`freelance.rights.claim_status.${c.status}`) }}</span></td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-wave" :class="c.status === 'open' && canUpdate ? 'btn-primary' : 'btn-light'" @click="openClaim(c)">
                                            <i :class="c.status === 'open' && canUpdate ? 'ri-scales-3-line' : 'ri-eye-line'" class="me-1"></i>{{ c.status === 'open' && canUpdate ? t('freelance.rights.decide') : t('freelance.rights.open') }}
                                        </button>
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
            </template>
        </div>

        <!-- One report: who says what about which work, then the decision. -->
        <WalletModal :show="!!current" :title="current?.subject_title || ''" @close="current = null">
            <div v-if="current">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge" :class="badge[current.status]">{{ t(`freelance.rights.claim_status.${current.status}`) }}</span>
                    <span class="badge bg-light text-default">{{ t(`freelance.rights.subject_type.${current.subject_type}`) }}</span>
                    <span class="text-muted fs-12">{{ when(current.created_at) }}</span>
                </div>
                <ul class="list-group fs-13 mb-3">
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{ t('freelance.rights.freelancer') }}</span><span>{{ current.freelancer?.name || '—' }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{ t('freelance.rights.reporter') }}</span><span>{{ current.reporter.name || '—' }} · {{ t(`freelance.rights.reporter_type.${current.reporter.type}`) }}</span></li>
                    <li v-if="current.reporter.contact" class="list-group-item d-flex justify-content-between"><span class="text-muted">{{ t('freelance.rights.contact') }}</span><span dir="ltr">{{ current.reporter.contact }}</span></li>
                </ul>
                <h6 class="fw-semibold fs-13 mb-1">{{ t('freelance.rights.details') }}</h6>
                <div class="border rounded p-3 fs-13 mb-3" style="white-space: pre-line">{{ current.details }}</div>

                <template v-if="current.status === 'open' && canUpdate">
                    <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.rights.your_decision') }}</h6>
                    <div class="row g-2 mb-2">
                        <div v-for="d in ['uphold', 'dismiss']" :key="d" class="col-6">
                            <label class="decision border rounded-3 p-3 d-block h-100" :class="{ 'decision-on': decision === d }">
                                <input v-model="decision" type="radio" :value="d" class="form-check-input me-2">
                                <span class="fw-semibold">{{ t(`freelance.rights.decision.${d}`) }}</span>
                                <span class="d-block text-muted fs-12 mt-1">{{ t(`freelance.rights.decision_hint.${d}`) }}</span>
                            </label>
                        </div>
                    </div>
                    <textarea v-model="note" class="form-control" rows="2" maxlength="1000" :placeholder="t('freelance.rights.note_hint')"></textarea>
                </template>
                <div v-else-if="current.status !== 'open'" class="alert alert-light border fs-13 mb-0">
                    <div class="fw-semibold">{{ t(`freelance.rights.claim_status.${current.status}`) }} · {{ when(current.decided_at) }}</div>
                    <div v-if="current.decision_note" class="mt-1" style="white-space: pre-line">{{ current.decision_note }}</div>
                </div>
            </div>
            <template v-if="current?.status === 'open' && canUpdate" #footer>
                <button type="button" class="btn btn-light" @click="current = null">{{ t('cancel') }}</button>
                <button type="button" class="btn" :class="decision === 'uphold' ? 'btn-danger' : 'btn-primary'" :disabled="!decision || deciding" @click="decide">
                    <span v-if="deciding" class="spinner-border spinner-border-sm me-1"></span>{{ t('freelance.rights.confirm_decision') }}
                </button>
            </template>
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
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Ownership and licences (FL-L13): how the agreements split over the three models, the standard text of each model per country (the
// agreement keeps the text of the day it was made), and the reports of portfolio works someone says are theirs.
const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const { can } = usePermission();
const canUpdate = computed(() => can('freelance-rights.update'));
const base = '/api/admin/v1/freelance-rights';

const models = ['transfer', 'license', 'retained'];
const tone = { transfer: 'bg-primary', license: 'bg-warning', retained: 'bg-secondary' };
const dot = { transfer: 'text-primary', license: 'text-warning', retained: 'text-secondary' };
const badge = { open: 'bg-warning-transparent', upheld: 'bg-danger-transparent', dismissed: 'bg-secondary-transparent' };

const overview = ref(null);
const tab = ref('claims');

// ---------------------------------------------------------------- numbers

const count = (m) => overview.value?.agreements.by_model?.[m] ?? 0;
const share = (m) => (overview.value?.agreements.with_terms ? Math.round((count(m) / overview.value.agreements.with_terms) * 100) : 0);
const kpis = computed(() => {
    const o = overview.value;
    return [
        { key: 'agreements', icon: 'ri-file-shield-2-line', tone: 'bg-primary-transparent', value: o?.agreements.with_terms ?? 0 },
        { key: 'portfolio', icon: 'ri-gallery-line', tone: 'bg-success-transparent', value: o ? `${o.portfolio.allowed} / ${o.portfolio.wished}` : 0 },
        { key: 'disputes', icon: 'ri-scales-3-line', tone: 'bg-warning-transparent', value: o ? `${o.disputes.open} / ${o.disputes.total}` : 0 },
        { key: 'claims', icon: 'ri-flag-2-line', tone: 'bg-danger-transparent', value: o?.claims.open ?? 0 },
    ];
});
const tabs = computed(() => [
    { value: 'claims', label: t('freelance.rights.tab_claims'), count: overview.value?.claims.open ?? 0, tone: 'pending' },
    { value: 'texts', label: t('freelance.rights.tab_texts'), tone: 'all' },
]);

async function loadOverview() {
    try {
        const { data } = await adminAxios.get(`${base}/overview`);
        overview.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

const when = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—');

// ---------------------------------------------------------------- standard texts

const countries = ref([]);
const countryId = ref('');
const model = ref('transfer');
const texts = ref([]);
const form = ref(null);
const textsLoading = ref(false);
const saving = ref(false);

const modelOptions = computed(() => models.map((m) => ({ value: m, label: t(`freelance.rights.models.${m}`), tone: 'all' })));
const formValid = computed(() => !!form.value && form.value.title_ar.trim().length >= 3 && form.value.title_en.trim().length >= 3 && form.value.body_ar.trim().length >= 30 && form.value.body_en.trim().length >= 30);

function pick() {
    const row = texts.value.find((r) => r.model === model.value);
    form.value = row ? { ...row } : null;
}

async function loadTexts() {
    textsLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/terms`, { params: { country_id: countryId.value || undefined } });
        texts.value = data.data ?? [];
        if (data.countries) countries.value = data.countries;
        pick();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        textsLoading.value = false;
    }
}

async function save() {
    saving.value = true;
    try {
        const f = form.value;
        const response = await adminAxios.put(`${base}/terms`, {
            country_id: countryId.value || undefined, model: f.model, title_ar: f.title_ar.trim(), title_en: f.title_en.trim(), body_ar: f.body_ar.trim(), body_en: f.body_en.trim(),
        });
        showSuccess(extractApiMessage(response, t('toast.success')));
        await loadTexts();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

async function reset() {
    saving.value = true;
    try {
        const response = await adminAxios.delete(`${base}/terms/${form.value.id}`);
        showSuccess(extractApiMessage(response, t('toast.success')));
        await loadTexts();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

watch(model, pick);
watch(countryId, loadTexts);
watch(tab, (v) => { if (v === 'texts' && !texts.value.length) loadTexts(); });

// ---------------------------------------------------------------- reports

const rows = ref([]);
const pagination = ref({});
const counts = ref({ open: 0, upheld: 0, dismissed: 0 });
const pageNo = ref(1);
const loading = ref(false);
const status = ref('open');
const current = ref(null);
const decision = ref('');
const note = ref('');
const deciding = ref(false);

const statusOptions = computed(() => [
    { value: 'open', label: t('freelance.rights.claim_status.open'), count: counts.value.open, tone: 'pending' },
    { value: 'upheld', label: t('freelance.rights.claim_status.upheld'), count: counts.value.upheld, tone: 'deleted' },
    { value: 'dismissed', label: t('freelance.rights.claim_status.dismissed'), count: counts.value.dismissed, tone: 'inactive' },
    { value: 'all', label: t('users.filter_all'), tone: 'all' },
]);

watch(status, () => (pageNo.value === 1 ? load() : (pageNo.value = 1)));
watch(pageNo, load);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/claims`, { params: { page: pageNo.value, status: status.value === 'all' ? undefined : status.value } });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
        counts.value = data.counts ?? counts.value;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function openClaim(c) {
    current.value = c;
    decision.value = '';
    note.value = '';
}

async function decide() {
    deciding.value = true;
    try {
        const response = await adminAxios.post(`${base}/claims/${current.value.uuid}/decide`, { decision: decision.value, note: note.value.trim() || undefined });
        showSuccess(extractApiMessage(response, t('toast.success')));
        current.value = null;
        load();
        loadOverview();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        deciding.value = false;
    }
}

onMounted(() => { loadOverview(); load(); });
</script>

<style scoped>
.cell-title {
    max-width: 260px;
}

.decision {
    cursor: pointer;
}

.decision-on {
    border-color: var(--primary-color, #845adf) !important;
    background: rgba(132, 90, 223, 0.06);
}
</style>
