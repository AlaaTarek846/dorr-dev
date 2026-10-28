<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_world.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_world.subtitle') }}</span>
            </div>
            <nav>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ t('ai_world.title') }}</li>
                </ol>
            </nav>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li v-for="tab in tabs" :key="tab" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: current === tab }" @click="open(tab)">
                    {{ t('ai_world.tabs.' + tab) }}
                    <span v-if="tab === 'reports' && stats && stats.open_reports" class="badge bg-danger ms-1">{{ stats.open_reports }}</span>
                </button>
            </li>
        </ul>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm me-2"></span>{{ t('ai_world.loading') }}</div>

        <!-- Overview -->
        <div v-else-if="current === 'overview'" class="row g-3">
            <div v-for="(count, status) in (stats?.projects_by_status || {})" :key="status" class="col-6 col-md-3">
                <div class="card custom-card"><div class="card-body">
                    <p class="text-muted mb-1">{{ t('ai_world.status.' + status) }}</p>
                    <p class="fs-24 fw-semibold mb-0">{{ count }}</p>
                </div></div>
            </div>
            <div v-for="m in (stats?.money || [])" :key="m.currency" class="col-md-6">
                <div class="card custom-card"><div class="card-body">
                    <p class="fw-semibold mb-2">{{ m.currency || '-' }}</p>
                    <div class="d-flex justify-content-between"><span>{{ t('ai_world.charged') }}</span><strong>{{ money(m.charged) }}</strong></div>
                    <div class="d-flex justify-content-between"><span>{{ t('ai_world.refunded') }}</span><strong>{{ money(m.refunded) }}</strong></div>
                </div></div>
            </div>
            <div v-if="! Object.keys(stats?.projects_by_status || {}).length" class="col-12 text-muted">{{ t('ai_world.empty') }}</div>
        </div>

        <!-- Settings -->
        <div v-else-if="current === 'settings' && settings" class="card custom-card"><div class="card-body row g-3">
            <div class="col-12 form-check form-switch ms-2">
                <input id="w-enabled" v-model="settings.is_enabled" class="form-check-input" type="checkbox">
                <label for="w-enabled" class="form-check-label">{{ t('ai_world.enabled') }}</label>
            </div>
            <div class="col-md-4"><label class="form-label">{{ t('ai_world.max_scenes') }}</label><input v-model.number="settings.max_scenes_per_project" type="number" min="1" max="20" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">{{ t('ai_world.max_retries') }}</label><input v-model.number="settings.max_retries_per_scene" type="number" min="0" max="5" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">{{ t('ai_world.retention_days') }}</label><input v-model.number="settings.retention_days" type="number" min="1" max="365" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">{{ t('ai_world.identity_threshold') }}</label><input v-model.number="settings.identity_threshold" type="number" min="0" max="100" class="form-control"><small class="text-muted">{{ t('ai_world.identity_hint') }}</small></div>
            <div class="col-12"><button class="btn btn-primary" :disabled="saving" @click="saveSettings">{{ t('ai_world.save') }}</button></div>
        </div></div>

        <!-- Country modes -->
        <div v-else-if="current === 'countries'" class="card custom-card"><div class="card-body">
            <div class="table-responsive"><table class="table text-nowrap align-middle">
                <thead><tr><th>{{ t('ai_world.country') }}</th><th v-for="m in modeKeys" :key="m">{{ t('ai_world.modes.' + m) }}</th></tr></thead>
                <tbody><tr v-for="c in countries" :key="c.country_id">
                    <td>{{ c.code }} <small class="text-muted">{{ c.currency }}</small></td>
                    <td v-for="m in modeKeys" :key="m"><input v-model="c.modes[m]" type="checkbox" class="form-check-input"></td>
                </tr></tbody>
            </table></div>
            <button class="btn btn-primary" :disabled="saving" @click="saveCountries">{{ t('ai_world.save') }}</button>
        </div></div>

        <!-- Prices -->
        <div v-else-if="current === 'prices'" class="card custom-card"><div class="card-body">
            <p class="text-muted fs-13">{{ t('ai_world.prices_hint') }}</p>
            <div class="table-responsive"><table class="table text-nowrap align-middle">
                <thead><tr><th>{{ t('ai_world.country') }}</th><th>{{ t('ai_world.quality') }}</th><th>{{ t('ai_world.price_per_second') }}</th><th>{{ t('ai_world.active') }}</th></tr></thead>
                <tbody><tr v-for="(p, i) in priceRows" :key="i">
                    <td>{{ countryCode(p.country_id) }}</td><td>{{ p.quality }}</td>
                    <td style="max-width:160px"><input v-model.number="p.price_per_second_minor" type="number" min="0" class="form-control form-control-sm"></td>
                    <td><input v-model="p.is_active" type="checkbox" class="form-check-input"></td>
                </tr></tbody>
            </table></div>
            <button class="btn btn-primary" :disabled="saving" @click="savePrices">{{ t('ai_world.save') }}</button>
        </div></div>

        <!-- Projects -->
        <div v-else-if="current === 'projects'" class="card custom-card"><div class="card-body">
            <select v-model="statusFilter" class="form-select w-auto mb-3" @change="loadProjects">
                <option value="">{{ t('ai_world.all_statuses') }}</option>
                <option v-for="s in statusKeys" :key="s" :value="s">{{ t('ai_world.status.' + s) }}</option>
            </select>
            <div class="table-responsive"><table class="table text-nowrap align-middle">
                <thead><tr><th>#</th><th>{{ t('ai_world.owner') }}</th><th>{{ t('ai_world.status_col') }}</th><th>{{ t('ai_world.seconds') }}</th><th>{{ t('ai_world.charged') }}</th><th>{{ t('ai_world.refunded') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="p in projects" :key="p.id">
                        <td>{{ p.id }}</td><td>{{ p.owner_id ?? '-' }}</td><td>{{ t('ai_world.status.' + p.status) }}</td><td>{{ p.total_seconds }}</td>
                        <td>{{ money(p.charged_minor) }} {{ p.currency }}</td><td>{{ money(p.refunded_minor) }}</td>
                        <td><button class="btn btn-sm btn-light" @click="openProject(p.id)">{{ t('ai_world.details') }}</button></td>
                    </tr>
                    <tr v-if="! projects.length"><td colspan="7" class="text-muted">{{ t('ai_world.empty') }}</td></tr>
                </tbody>
            </table></div>
            <div v-if="detail" class="border rounded p-3 mt-3">
                <p class="fw-semibold">#{{ detail.project.id }} - {{ t('ai_world.ledger') }}</p>
                <table class="table table-sm"><tbody>
                    <tr v-for="l in detail.ledger" :key="l.id"><td>{{ l.type }}</td><td>{{ money(l.amount_minor) }} {{ l.currency }}</td><td class="text-muted">{{ l.note }}</td></tr>
                    <tr v-if="! detail.ledger.length"><td class="text-muted">{{ t('ai_world.empty') }}</td></tr>
                </tbody></table>
                <p class="fw-semibold">{{ t('ai_world.attempts') }}</p>
                <table class="table table-sm"><tbody>
                    <tr v-for="v in detail.versions" :key="v.id"><td>{{ v.scene_id }}/{{ v.number }}</td><td>{{ v.status }}</td><td class="text-muted">{{ v.error_code }}</td></tr>
                </tbody></table>
            </div>
        </div></div>

        <!-- Reports -->
        <div v-else-if="current === 'reports'" class="card custom-card"><div class="card-body">
            <div class="table-responsive"><table class="table text-nowrap align-middle">
                <thead><tr><th>#</th><th>{{ t('ai_world.report_type') }}</th><th>{{ t('ai_world.project') }}</th><th>{{ t('ai_world.notes') }}</th><th>{{ t('ai_world.status_col') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="r in reports" :key="r.id">
                        <td>{{ r.id }}</td><td>{{ t('ai_world.report_types.' + r.type) }}</td><td>{{ r.project_id ?? '-' }}</td>
                        <td style="white-space:normal;max-width:320px">{{ r.notes }}</td>
                        <td>
                            <select :value="r.status" class="form-select form-select-sm" @change="setReport(r, $event.target.value)">
                                <option v-for="s in reportStatuses" :key="s" :value="s">{{ t('ai_world.report_status.' + s) }}</option>
                            </select>
                        </td><td></td>
                    </tr>
                    <tr v-if="! reports.length"><td colspan="6" class="text-muted">{{ t('ai_world.empty') }}</td></tr>
                </tbody>
            </table></div>
        </div></div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

const { t } = useI18n();
const { showSuccess, showError } = useToast();
const base = '/api/admin/v1/ai-world';

const tabs = ['overview', 'settings', 'countries', 'prices', 'projects', 'reports'];
const modeKeys = ['single', 'multi', 'ar_portal', 'tour3d'];
const statusKeys = ['awaiting_confirmation', 'generating', 'review', 'stitching', 'completed', 'failed', 'cancelled'];
const reportStatuses = ['open', 'reviewing', 'resolved', 'dismissed'];

const current = ref('overview');
const loading = ref(true);
const saving = ref(false);
const stats = ref(null);
const settings = ref(null);
const countries = ref([]);
const priceRows = ref([]);
const projects = ref([]);
const reports = ref([]);
const detail = ref(null);
const statusFilter = ref('');

const money = (minor) => ((Number(minor) || 0) / 100).toFixed(2);
const countryCode = (id) => countries.value.find((c) => c.country_id === id)?.code ?? id;

async function run(call) {
    try { return await call(); } catch (error) { showError(extractApiErrorMessage(error, t('toast.error'))); return null; }
}

async function open(tab) {
    current.value = tab;
    loading.value = true;
    detail.value = null;
    if (tab === 'overview') stats.value = (await run(() => adminAxios.get(`${base}/stats`)))?.data.data ?? stats.value;
    if (tab === 'settings') settings.value = (await run(() => adminAxios.get(`${base}/settings`)))?.data.data ?? settings.value;
    if (tab === 'countries' || tab === 'prices') {
        countries.value = (await run(() => adminAxios.get(`${base}/country-modes`)))?.data.data ?? countries.value;
    }
    if (tab === 'prices') {
        const rows = (await run(() => adminAxios.get(`${base}/prices`)))?.data.data ?? [];
        const have = new Map(rows.map((r) => [`${r.country_id}:${r.quality}`, r]));
        // One row per country and quality, so a missing price can be filled in from here.
        priceRows.value = countries.value.flatMap((c) => ['standard', 'premium'].map((quality) => have.get(`${c.country_id}:${quality}`) ?? { country_id: c.country_id, quality, price_per_second_minor: 0, is_active: false }));
    }
    if (tab === 'projects') await loadProjects();
    if (tab === 'reports') reports.value = (await run(() => adminAxios.get(`${base}/reports`)))?.data.data ?? [];
    loading.value = false;
}

async function loadProjects() {
    const res = await run(() => adminAxios.get(`${base}/projects`, { params: statusFilter.value ? { status: statusFilter.value } : {} }));
    projects.value = res?.data.data ?? [];
}

async function openProject(id) {
    detail.value = (await run(() => adminAxios.get(`${base}/projects/${id}`)))?.data.data ?? null;
}

async function save(call) {
    saving.value = true;
    const res = await run(call);
    saving.value = false;
    if (res) showSuccess(extractApiMessage(res, t('toast.success')));
    return res;
}

const saveSettings = () => save(() => adminAxios.put(`${base}/settings`, {
    is_enabled: settings.value.is_enabled,
    max_scenes_per_project: settings.value.max_scenes_per_project,
    max_retries_per_scene: settings.value.max_retries_per_scene,
    identity_threshold: settings.value.identity_threshold,
    retention_days: settings.value.retention_days,
}));

const saveCountries = () => save(() => adminAxios.put(`${base}/country-modes`, {
    countries: countries.value.map((c) => ({ country_id: c.country_id, modes: c.modes })),
}));

const savePrices = () => save(() => adminAxios.put(`${base}/prices`, {
    prices: priceRows.value.filter((p) => p.price_per_second_minor > 0 || p.is_active).map((p) => ({ country_id: p.country_id, quality: p.quality, price_per_second_minor: p.price_per_second_minor, is_active: !! p.is_active })),
}));

async function setReport(report, status) {
    const res = await save(() => adminAxios.put(`${base}/reports/${report.id}`, { status }));
    if (res) report.status = status;
}

onMounted(() => open('overview'));
</script>
