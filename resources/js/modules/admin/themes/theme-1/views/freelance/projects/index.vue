<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('freelance.projects.title') }}
                <span v-if="counts.pending_review" class="badge bg-warning-transparent ms-2 fs-12 align-middle">{{ t('freelance.projects.waiting', { n: counts.pending_review }) }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.projects.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.projects.search')">
                    </div>
                    <Select v-model="statusFilter" :options="statusOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                    <Select v-model="countryFilter" :options="countryOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" class="wallet-filter-select" />
                    <Select v-model="specialtyFilter" :options="specialtyOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" class="wallet-filter-select" />
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.projects.project') }}</th>
                                <th>{{ t('freelance.projects.client') }}</th>
                                <th>{{ t('freelance.projects.budget') }}</th>
                                <th>{{ t('freelance.verifications.submitted_at') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="['two-lines', 'two-lines', 'text', 'text', 'badge', 'actions']" />
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.uuid">
                                <td class="ps-4">
                                    <span class="fw-semibold d-block text-truncate project-title">{{ row.title || '—' }}</span>
                                    <span class="text-muted fs-11">{{ row.specialty || '—' }} · {{ countryName(row.country_id) }}</span>
                                </td>
                                <td>
                                    <span class="d-block"><UserName :user="{ name: row.client_name, is_verified: row.client_verified }">{{ row.client_name || '—' }}</UserName></span>
                                    <span class="text-muted fs-11" dir="ltr">{{ row.client_phone }}</span>
                                </td>
                                <td dir="ltr">{{ budgetText(row.budget) }}</td>
                                <td>{{ formatCatalogDate(row.submitted_at, locale) }}</td>
                                <td><span class="badge" :class="statusBadge(row.status)">{{ t(`freelance.projects.status.${row.status}`) }}</span></td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-wave" :class="row.status === 'pending_review' ? 'btn-primary' : 'btn-light'" @click="open(row)">
                                        <i :class="row.status === 'pending_review' ? 'ri-survey-line' : 'ri-eye-line'" class="me-1"></i>{{ row.status === 'pending_review' ? t('freelance.verifications.review') : t('freelance.verifications.view') }}
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
                        <li class="page-item" :class="{ disabled: page <= 1 }"><button type="button" class="page-link" @click="page -= 1">{{ t('countries.previous') }}</button></li>
                        <li class="page-item active"><span class="page-link">{{ page }}</span></li>
                        <li class="page-item" :class="{ disabled: page >= pagination.last_page }"><button type="button" class="page-link text-primary" @click="page += 1">{{ t('countries.next') }}</button></li>
                    </ul>
                </nav>
            </div>
        </div>

        <!-- One project: what the client wrote, its files (opened through the signed-in API), a fix, and the decision. -->
        <WalletModal :show="!!current" :title="t('freelance.projects.project')" size="xl" @close="close">
            <div v-if="current">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div>
                        <div class="fw-semibold"><UserName :user="current.client">{{ current.client?.name || '—' }}</UserName></div>
                        <div class="text-muted fs-12"><span dir="ltr">{{ current.client?.phone }}</span> · {{ countryName(current.country_id) }}</div>
                    </div>
                    <span class="badge ms-auto" :class="statusBadge(current.status)">{{ t(`freelance.projects.status.${current.status}`) }}</span>
                </div>
                <div v-if="current.status_reason" class="alert alert-light border fs-13">{{ t('freelance.verifications.reason') }}: {{ current.status_reason }}</div>
                <div v-if="current.missing.length" class="alert alert-warning fs-13">{{ t('freelance.projects.missing') }}: {{ current.missing.map((m) => t(`freelance.projects.fields.${m}`)).join('، ') }}</div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">{{ t('freelance.projects.title_field') }}</label>
                        <input v-model="form.title" type="text" maxlength="120" class="form-control" :disabled="!canEdit">
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t('freelance.projects.description') }}</label>
                        <textarea v-model="form.description" rows="5" maxlength="5000" class="form-control" :disabled="!canEdit"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.projects.specialty') }}</label>
                        <Select v-model="form.specialty_id" :options="specialtyChoices" option-label="label" option-value="value" filter append-to="body" class="w-100" :disabled="!canEdit" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.projects.skills') }}</label>
                        <div class="form-control-plaintext">{{ current.skills.map((s) => s.name).join('، ') || '—' }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ t('freelance.projects.budget_type') }}</label>
                        <Select v-model="form.budget_type" :options="budgetTypeOptions" option-label="label" option-value="value" append-to="body" class="w-100" :disabled="!canEdit" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ t('freelance.projects.budget_from', { currency: current.budget.currency }) }}</label>
                        <input v-model="form.min" type="text" inputmode="decimal" dir="ltr" class="form-control" :disabled="!canEdit">
                    </div>
                    <div v-if="form.budget_type === 'range'" class="col-md-3">
                        <label class="form-label">{{ t('freelance.projects.budget_to', { currency: current.budget.currency }) }}</label>
                        <input v-model="form.max" type="text" inputmode="decimal" dir="ltr" class="form-control" :disabled="!canEdit">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ t('freelance.projects.duration') }}</label>
                        <input v-model="form.duration_days" type="number" min="1" max="365" class="form-control" :disabled="!canEdit">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ t('freelance.projects.visibility') }}</label>
                        <Select v-model="form.visibility" :options="visibilityOptions" option-label="label" option-value="value" append-to="body" class="w-100" :disabled="!canEdit" />
                    </div>
                </div>

                <div v-if="['published', 'awarded'].includes(current.status)" class="mt-3 border rounded p-3 d-flex align-items-center gap-3 flex-wrap">
                    <div class="flex-fill">
                        <div class="fw-semibold fs-13">{{ t('freelance.projects.offers') }}</div>
                        <div class="fs-12" :class="current.status === 'awarded' ? 'text-primary' : (current.offers.accepting ? 'text-success' : 'text-warning')">
                            <template v-if="current.offers.accepting">{{ t('freelance.projects.offers_open', { date: (current.offers.deadline_at || '').slice(0, 10) }) }}</template>
                            <template v-else>{{ t(`freelance.projects.offers_closed_${current.offers.close_reason || 'deadline'}`) }}</template>
                            · {{ t('freelance.projects.reopened', { n: current.offers.reopen_count }) }} · {{ t('freelance.projects.offers_count', { n: current.offers.count }) }}
                        </div>
                    </div>
                    <template v-if="current.status === 'published' && can('freelance-projects.update')">
                        <button v-if="current.offers.accepting" type="button" class="btn btn-sm btn-outline-warning" :disabled="working" @click="offers('close')">{{ t('freelance.projects.close_offers') }}</button>
                        <button v-else type="button" class="btn btn-sm btn-outline-success" :disabled="working" @click="offers('reopen')">{{ t('freelance.projects.reopen_offers') }}</button>
                    </template>
                </div>

                <div v-if="current.attachments.length" class="mt-3">
                    <h6 class="fs-13 fw-semibold">{{ t('freelance.projects.files') }}</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <a v-for="file in current.attachments" :key="file.id" href="#" class="border rounded px-3 py-2 fs-12" @click.prevent="openFile(file)"><i class="ri-attachment-2 me-1"></i>{{ file.name }}</a>
                    </div>
                </div>

                <div v-if="current.timeline.length" class="mt-3">
                    <h6 class="fs-13 fw-semibold">{{ t('freelance.verifications.history') }}</h6>
                    <div v-for="(e, i) in current.timeline" :key="i" class="fs-12 text-muted">
                        {{ formatCatalogDate(e.at, locale) }} · {{ t(`freelance.projects.event.${e.type}`, e.type) }}<template v-if="e.reason"> · {{ e.reason }}</template>
                    </div>
                </div>

                <div v-if="canDecide" class="mt-3">
                    <label class="form-label">{{ t('freelance.projects.reason_for_client') }}</label>
                    <textarea v-model="reason" rows="2" maxlength="500" class="form-control" :placeholder="t('freelance.projects.reason_hint')"></textarea>
                </div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" @click="close">{{ t('cancel') }}</button>
                <button v-if="canEdit" type="button" class="btn btn-outline-primary" :disabled="working" @click="saveFix">{{ t('freelance.projects.save_fix') }}</button>
                <template v-if="canDecide">
                    <button type="button" class="btn btn-danger" :disabled="working" @click="decide('reject')">{{ t('freelance.verifications.reject') }}</button>
                    <button type="button" class="btn btn-warning" :disabled="working" @click="decide('request-changes')">{{ t('freelance.verifications.changes') }}</button>
                    <button type="button" class="btn btn-success" :disabled="working" @click="decide('publish')"><span v-if="working" class="spinner-border spinner-border-sm me-1"></span>{{ t('freelance.projects.publish') }}</button>
                </template>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from 'primevue/select';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatCatalogDate } from '../../../../../../../utils/catalog';
import UserName from '../../../../../../../components/ui/UserName.vue';

// Reviewing the clients' projects (FL-203): the oldest waiting first. A project can be fixed while it waits, then published, sent
// back for changes, or rejected (a reason is always given; a rejected project can still be edited and sent again by its client).
// The files are private: fetched with the admin's token, never through a public link.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const endpoint = '/api/admin/v1/freelance-projects';

const rows = ref([]);
const pagination = ref({});
const counts = ref({});
const loading = ref(false);
const countries = ref([]);
const specialties = ref([]);
const search = ref('');
const statusFilter = ref('pending_review');
const countryFilter = ref('all');
const specialtyFilter = ref('all');
const page = ref(1);

const statuses = ['pending_review', 'changes_requested', 'rejected', 'published', 'awarded', 'in_progress', 'closed', 'cancelled'];
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0));
const statusOptions = computed(() => [
    { value: 'all', label: `${t('countries.filter_all')} (${total.value})` },
    ...statuses.map((s) => ({ value: s, label: `${t(`freelance.projects.status.${s}`)} (${counts.value[s] ?? 0})` })),
]);
const countryOptions = computed(() => [{ value: 'all', label: t('freelance.document_types.all_countries') }, ...countries.value.map((c) => ({ value: c.id, label: c.name }))]);
const specialtyOptions = computed(() => [{ value: 'all', label: t('freelance.projects.all_specialties') }, ...specialties.value.map((s) => ({ value: s.id, label: s.name }))]);
const specialtyChoices = computed(() => specialties.value.map((s) => ({ value: s.id, label: s.name })));
const budgetTypeOptions = computed(() => [{ value: 'fixed', label: t('freelance.projects.fixed') }, { value: 'range', label: t('freelance.projects.range') }]);
const visibilityOptions = computed(() => [{ value: 'public', label: t('freelance.projects.public') }, { value: 'private', label: t('freelance.projects.private') }]);
const countryName = (id) => countries.value.find((c) => c.id === id)?.name ?? '—';
const statusBadge = (s) => ({ pending_review: 'bg-warning-transparent', published: 'bg-success-transparent', awarded: 'bg-primary-transparent', in_progress: 'bg-info-transparent', rejected: 'bg-danger-transparent', changes_requested: 'bg-info-transparent', cancelled: 'bg-secondary-transparent' }[s] ?? 'bg-light text-dark');
const money = (minor) => (minor == null ? '' : (minor / 100).toFixed(2));
const budgetText = (b) => (b?.min_minor == null ? '—' : `${money(b.min_minor)}${b.max_minor != null ? ` – ${money(b.max_minor)}` : ''} ${b.currency}`);
const toMinor = (text) => {
    const clean = String(text ?? '').trim().replace(',', '.');
    return /^\d{1,9}(\.\d{1,2})?$/.test(clean) ? Math.round(parseFloat(clean) * 100) : null;
};

let timer = null;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => (page.value === 1 ? load() : (page.value = 1)), 300); });
watch([statusFilter, countryFilter, specialtyFilter], () => (page.value === 1 ? load() : (page.value = 1)));
watch(page, load);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(endpoint, {
            params: {
                page: page.value, search: search.value || undefined,
                status: statusFilter.value === 'all' ? undefined : statusFilter.value,
                country_id: countryFilter.value === 'all' ? undefined : countryFilter.value,
                specialty_id: specialtyFilter.value === 'all' ? undefined : specialtyFilter.value,
            },
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

// ---- one project
const current = ref(null);
const reason = ref('');
const working = ref(false);
const form = reactive({ title: '', description: '', specialty_id: null, budget_type: 'fixed', min: '', max: '', duration_days: null, visibility: 'public' });
const canDecide = computed(() => !!current.value?.can_decide && can('freelance-projects.update'));
const canEdit = canDecide;

async function open(row) {
    try {
        const { data } = await adminAxios.get(`${endpoint}/${row.uuid}`);
        show(data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function show(project) {
    current.value = project;
    reason.value = '';
    Object.assign(form, {
        title: project.title ?? '', description: project.description ?? '', specialty_id: project.specialty_id,
        budget_type: project.budget.type, min: money(project.budget.min_minor), max: money(project.budget.max_minor),
        duration_days: project.duration_days, visibility: project.visibility,
    });
}

function close() {
    current.value = null;
}

async function openFile(file) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/${file.path}`, { responseType: 'blob' });
        window.open(URL.createObjectURL(data), '_blank', 'noopener');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

/** Only what is different from the stored project is sent. */
function changes() {
    const p = current.value;
    const out = {};
    if (form.title !== (p.title ?? '')) out.title = form.title || null;
    if (form.description !== (p.description ?? '')) out.description = form.description || null;
    if (form.specialty_id !== p.specialty_id) out.specialty_id = form.specialty_id;
    if (form.budget_type !== p.budget.type) out.budget_type = form.budget_type;
    if (toMinor(form.min) !== p.budget.min_minor) out.budget_min_minor = toMinor(form.min);
    if (form.budget_type === 'range' && toMinor(form.max) !== p.budget.max_minor) out.budget_max_minor = toMinor(form.max);
    if ((form.duration_days || null) !== p.duration_days) out.duration_days = form.duration_days || null;
    if (form.visibility !== p.visibility) out.visibility = form.visibility;
    return out;
}

async function saveFix() {
    const body = changes();
    if (!Object.keys(body).length) return;
    working.value = true;
    try {
        const response = await adminAxios.put(`${endpoint}/${current.value.uuid}`, body);
        showSuccess(extractApiMessage(response, t('toast.success')));
        show(response.data.data);
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

async function offers(action) {
    working.value = true;
    try {
        const response = await adminAxios.post(`${endpoint}/${current.value.uuid}/offers/${action}`);
        showSuccess(extractApiMessage(response, t('toast.success')));
        show(response.data.data);
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

async function decide(decision) {
    if (decision !== 'publish' && !reason.value.trim()) {
        showError(t('freelance.verifications.reason_required'));
        return;
    }
    working.value = true;
    try {
        // A fix typed but not saved goes in first, so what is published is what is on screen.
        if (Object.keys(changes()).length) await adminAxios.put(`${endpoint}/${current.value.uuid}`, changes());
        const response = await adminAxios.post(`${endpoint}/${current.value.uuid}/${decision}`, decision === 'publish' ? {} : { reason: reason.value });
        showSuccess(extractApiMessage(response, t('toast.success')));
        show(response.data.data);
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

onMounted(async () => {
    load();
    try {
        const { data } = await adminAxios.get(`${endpoint}/options`);
        countries.value = data.data?.countries ?? [];
        specialties.value = data.data?.specialties ?? [];
    } catch {
        // Without the lists the filters show only "all".
    }
});
</script>

<style scoped>
.project-title {
    max-width: 280px;
}
</style>
