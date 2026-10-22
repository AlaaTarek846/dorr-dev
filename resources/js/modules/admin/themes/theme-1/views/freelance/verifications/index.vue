<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('freelance.verifications.title') }}
                <span v-if="counts.submitted" class="badge bg-warning-transparent ms-2 fs-12 align-middle">{{ t('freelance.verifications.waiting', { n: counts.submitted }) }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.verifications.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.verifications.search')">
                    </div>
                    <Select v-model="statusFilter" :options="statusOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                    <Select v-model="countryFilter" :options="countryOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" class="wallet-filter-select" />
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-wave" :disabled="downloading" @click="downloadGuide(`${endpoint}/guide`, {}, 'freelance-verification-requests.docx')">
                        <span v-if="downloading" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-file-word-2-line me-1 align-middle"></i>{{ t('freelance.switches.guide') }}
                    </button>
                    
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.verifications.freelancer') }}</th>
                                <th>{{ t('freelance.verifications.country') }}</th>
                                <th>{{ t('freelance.verifications.submitted_at') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="['two-lines', 'text', 'text', 'badge', 'actions']" />
                            <tr v-else-if="!rows.length"><td colspan="5" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.uuid">
                                <td class="ps-4">
                                    <span class="fw-semibold d-block">{{ row.freelancer_name || '—' }}</span>
                                    <span class="text-muted fs-11" dir="ltr">{{ row.freelancer_phone }}</span>
                                </td>
                                <td>{{ countryName(row.country_id) }}</td>
                                <td>{{ formatCatalogDate(row.submitted_at, locale) }}</td>
                                <td><span class="badge" :class="statusBadge(row.status)">{{ t(`freelance.verifications.status.${row.status}`) }}</span></td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-wave" :class="row.status === 'submitted' ? 'btn-primary' : 'btn-light'" @click="open(row)">
                                        <i :class="row.status === 'submitted' ? 'ri-shield-check-line' : 'ri-eye-line'" class="me-1"></i>{{ row.status === 'submitted' ? t('freelance.verifications.review') : t('freelance.verifications.view') }}
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

        <!-- One request: the freelancer, their documents (opened through the signed-in API) and the decision. -->
        <WalletModal :show="!!current" :title="t('freelance.verifications.request')" size="xl" @close="close">
            <div v-if="current">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="avatar avatar-lg avatar-rounded bg-primary-transparent"><img v-if="current.freelancer.avatar_url" :src="current.freelancer.avatar_url" alt=""><i v-else class="ri-user-line fs-4"></i></span>
                    <div>
                        <div class="fw-semibold">{{ current.freelancer.name || '—' }}</div>
                        <div class="text-muted fs-12"><span dir="ltr">{{ current.freelancer.phone }}</span> · {{ countryName(current.freelancer.country_id) }}<template v-if="current.freelancer.residency"> · {{ t(`freelance.document_types.residency.${current.freelancer.residency}`) }}</template></div>
                    </div>
                    <span class="badge ms-auto" :class="statusBadge(current.status)">{{ t(`freelance.verifications.status.${current.status}`) }}</span>
                </div>
                <div v-if="current.reason" class="alert alert-light border fs-13">{{ t('freelance.verifications.reason') }}: {{ current.reason }}</div>
                <div v-if="current.missing_types.length" class="alert alert-warning fs-13">{{ t('freelance.verifications.missing') }}: {{ current.missing_types.map((m) => m.name).join('، ') }}</div>

                <div class="row g-3">
                    <div v-for="doc in current.documents" :key="doc.id" class="col-lg-6">
                        <div class="border rounded p-3 h-100">
                            <div class="d-flex align-items-center mb-2">
                                <span class="fw-semibold">{{ doc.type?.name }}</span>
                                <span v-if="doc.required" class="badge bg-info-transparent ms-2">{{ t('freelance.verifications.required') }}</span>
                                <span class="badge ms-auto" :class="docBadge(doc.status)">{{ t(`freelance.verifications.doc_status.${doc.status}`) }}</span>
                            </div>
                            <div class="fs-12 text-muted mb-2">
                                <span v-if="doc.number">{{ t('freelance.verifications.number') }}: <span dir="ltr">{{ doc.number }}</span></span>
                                <span v-if="doc.expires_on" class="ms-2" :class="{ 'text-danger': doc.is_expired }">{{ t('freelance.verifications.expires') }}: {{ doc.expires_on }}</span>
                            </div>
                            <div class="d-flex gap-2 mb-2">
                                <template v-for="(file, side) in doc.files" :key="side">
                                    <a v-if="file" href="#" class="border rounded d-block text-center p-1 flex-fill verification-file" @click.prevent="openFile(file)">
                                        <img v-if="previews[file.path] && file.mime?.startsWith('image/')" :src="previews[file.path]" alt="" class="img-fluid rounded">
                                        <span v-else class="d-block py-4"><i class="ri-file-pdf-2-line fs-3 d-block"></i>{{ file.name }}</span>
                                        <span class="fs-11 text-muted">{{ t(`freelance.verifications.side.${side}`) }}</span>
                                    </a>
                                </template>
                            </div>
                            <div v-if="doc.rejection_reason" class="text-danger fs-12 mb-2">{{ doc.rejection_reason }}</div>
                            <template v-if="canDecide && doc.status === 'pending'">
                                <Select v-model="choices[doc.id].status" :options="docChoiceOptions" option-label="label" option-value="value" append-to="body" class="w-100 mb-2" />
                                <input v-if="choices[doc.id].status === 'rejected'" v-model="choices[doc.id].reason" type="text" maxlength="500" class="form-control form-control-sm" :placeholder="t('freelance.verifications.doc_reason')">
                            </template>
                        </div>
                    </div>
                </div>

                <div v-if="current.history.length" class="mt-3">
                    <h6 class="fs-13 fw-semibold">{{ t('freelance.verifications.history') }}</h6>
                    <div v-for="h in current.history" :key="h.uuid" class="fs-12 text-muted">
                        {{ formatCatalogDate(h.submitted_at, locale) }} · {{ t(`freelance.verifications.status.${h.status}`) }}<template v-if="h.reason"> · {{ h.reason }}</template>
                    </div>
                </div>

                <div v-if="canDecide" class="mt-3">
                    <label class="form-label">{{ t('freelance.verifications.reason_for_freelancer') }}</label>
                    <textarea v-model="reason" rows="2" maxlength="500" class="form-control" :placeholder="t('freelance.verifications.reason_hint')"></textarea>
                </div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" @click="close">{{ t('cancel') }}</button>
                <template v-if="canDecide">
                    <button type="button" class="btn btn-danger" :disabled="deciding" @click="decide('reject')">{{ t('freelance.verifications.reject') }}</button>
                    <button type="button" class="btn btn-warning" :disabled="deciding" @click="decide('changes')">{{ t('freelance.verifications.changes') }}</button>
                    <button type="button" class="btn btn-success" :disabled="deciding" @click="decide('approve')"><span v-if="deciding" class="spinner-border spinner-border-sm me-1"></span>{{ t('freelance.verifications.approve') }}</button>
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

// Verifying freelancers by hand (FL-107 / FL-108): the oldest waiting first. The documents are private: they are fetched
// with the admin's token and shown from memory, never through a public link.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const endpoint = '/api/admin/v1/freelance-verifications';

const rows = ref([]);
const pagination = ref({});
const counts = ref({});
const loading = ref(false);
const countries = ref([]);
const search = ref('');
const statusFilter = ref('submitted');
const countryFilter = ref('all');
const page = ref(1);
const downloading = ref(false);

const statuses = ['submitted', 'changes_requested', 'rejected', 'approved'];
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0));
const statusOptions = computed(() => [
    { value: 'all', label: `${t('countries.filter_all')} (${total.value})` },
    ...statuses.map((s) => ({ value: s, label: `${t(`freelance.verifications.status.${s}`)} (${counts.value[s] ?? 0})` })),
]);
const countryOptions = computed(() => [{ value: 'all', label: t('freelance.document_types.all_countries') }, ...countries.value.map((c) => ({ value: c.id, label: c.name }))]);
const countryName = (id) => countries.value.find((c) => c.id === id)?.name ?? '—';
const statusBadge = (s) => ({ submitted: 'bg-warning-transparent', approved: 'bg-success-transparent', rejected: 'bg-danger-transparent', changes_requested: 'bg-info-transparent' }[s] ?? 'bg-light text-dark');
const docBadge = (s) => ({ pending: 'bg-warning-transparent', approved: 'bg-success-transparent', rejected: 'bg-danger-transparent', expired: 'bg-secondary-transparent', draft: 'bg-light text-dark' }[s] ?? 'bg-light text-dark');

let timer = null;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => (page.value === 1 ? load() : (page.value = 1)), 300); });
watch([statusFilter, countryFilter], () => (page.value === 1 ? load() : (page.value = 1)));
watch(page, load);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(endpoint, { params: { page: page.value, search: search.value || undefined, status: statusFilter.value === 'all' ? undefined : statusFilter.value, country_id: countryFilter.value === 'all' ? undefined : countryFilter.value } });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
        counts.value = data.pagination?.counts ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function downloadGuide(url, params, name) {
    downloading.value = true;
    try {
        const { data } = await adminAxios.get(url, { params, responseType: 'blob' });
        const href = URL.createObjectURL(data);
        const link = document.createElement('a');
        link.href = href;
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(href);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        downloading.value = false;
    }
}

// ---- one request
const current = ref(null);
const reason = ref('');
const choices = reactive({});
const previews = reactive({});
const deciding = ref(false);
const canDecide = computed(() => current.value?.status === 'submitted' && can('freelance-verifications.update'));
const docChoiceOptions = computed(() => [
    { value: 'follow', label: t('freelance.verifications.follow_decision') },
    { value: 'approved', label: t('freelance.verifications.doc_status.approved') },
    { value: 'rejected', label: t('freelance.verifications.doc_status.rejected') },
]);

async function open(row) {
    try {
        const { data } = await adminAxios.get(`${endpoint}/${row.uuid}`);
        show(data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function show(request) {
    current.value = request;
    reason.value = '';
    Object.keys(choices).forEach((k) => delete choices[k]);
    request.documents.forEach((d) => { choices[d.id] = { status: 'follow', reason: '' }; });
    request.documents.forEach((d) => Object.values(d.files).filter(Boolean).forEach(loadPreview));
}

async function loadPreview(file) {
    if (previews[file.path] || !file.mime?.startsWith('image/')) return;
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/${file.path}`, { responseType: 'blob' });
        previews[file.path] = URL.createObjectURL(data);
    } catch {
        // The card still offers to open it.
    }
}

async function openFile(file) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/${file.path}`, { responseType: 'blob' });
        window.open(URL.createObjectURL(data), '_blank', 'noopener');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function close() {
    Object.values(previews).forEach((url) => URL.revokeObjectURL(url));
    Object.keys(previews).forEach((k) => delete previews[k]);
    current.value = null;
}

async function decide(decision) {
    if (decision !== 'approve' && !reason.value.trim()) {
        showError(t('freelance.verifications.reason_required'));
        return;
    }
    const documents = Object.fromEntries(Object.entries(choices).filter(([, c]) => c.status !== 'follow').map(([id, c]) => [id, { status: c.status, reason: c.reason || null }]));
    deciding.value = true;
    try {
        const response = await adminAxios.post(`${endpoint}/${current.value.uuid}/decide`, { decision, reason: reason.value || null, documents });
        showSuccess(extractApiMessage(response, t('toast.success')));
        show(response.data.data);
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        deciding.value = false;
    }
}

onMounted(async () => {
    load();
    try {
        const { data } = await adminAxios.get(`${endpoint}/options`);
        countries.value = data.data?.countries ?? [];
    } catch {
        // Without the list the country column shows a dash.
    }
});
</script>

<style scoped>
.verification-file img {
    max-height: 180px;
    object-fit: contain;
}
</style>
