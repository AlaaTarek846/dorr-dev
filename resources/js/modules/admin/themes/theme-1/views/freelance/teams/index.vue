<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.teams.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.teams.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.teams.search')">
                    </div>
                    <Select v-model="statusFilter" :options="statusOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                    <Select v-model="countryFilter" :options="countryOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" class="wallet-filter-select" />
                    <Select v-model="verificationFilter" :options="verificationOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.teams.team') }}</th>
                                <th>{{ t('freelance.teams.country') }}</th>
                                <th>{{ t('freelance.teams.members') }}</th>
                                <th>{{ t('freelance.teams.running') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="5" :layout="['two-lines', 'text', 'text', 'text', 'badge', 'actions']" />
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.uuid">
                                <td class="ps-4">
                                    <span class="d-block fw-semibold">{{ row.name }}</span>
                                    <span class="text-muted fs-11">{{ row.created_by || '—' }}</span>
                                </td>
                                <td class="fs-12">{{ row.country }}</td>
                                <td>{{ row.members_count }}</td>
                                <td>{{ row.projects_running }}</td>
                                <td>
                                    <span class="badge" :class="statusBadge(row.status)">{{ t(`freelance.teams.status.${row.status}`) }}</span>
                                    <span v-if="row.verified" class="badge bg-info-transparent ms-1"><i class="ri-verified-badge-fill me-1"></i>{{ t('freelance.teams.verification.verified') }}</span>
                                    <span v-if="row.verification_pending" class="badge bg-warning-transparent ms-1">{{ t('freelance.teams.verification.waiting') }}</span>
                                </td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-light btn-wave" @click="open(row)"><i class="ri-eye-line me-1"></i>{{ t('freelance.teams.open') }}</button>
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

        <!-- One team: its people, projects and what each member carries, and what staff may do about it. -->
        <WalletModal :show="!!current" :title="current?.name || t('freelance.teams.team')" size="xl" @close="close">
            <div v-if="detailLoading" class="text-center text-muted py-5">{{ t('flags.loading') }}</div>
            <div v-else-if="current">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge" :class="statusBadge(current.status)">{{ t(`freelance.teams.status.${current.status}`) }}</span>
                    <span class="text-muted fs-12">{{ current.country }}</span>
                    <span v-if="current.teams_enabled === false" class="badge bg-warning-transparent">{{ t('freelance.teams.switch_off') }}</span>
                    <span v-if="current.status_reason" class="text-danger fs-12">{{ current.status_reason }}</span>
                    <span class="text-muted fs-12">{{ t('freelance.teams.limit', { n: current.members_max ?? '∞' }) }}</span>
                </div>

                <!-- Its plan (FS-211): the room it has now against what it holds, and the Owner who pays. -->
                <div v-if="current.plan" class="border rounded-3 p-3 mb-3">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <h6 class="fw-semibold mb-0">{{ t('freelance.teams.plan') }}</h6>
                        <span class="badge" :class="current.plan.usage.plan.free ? 'bg-secondary-transparent' : 'bg-primary-transparent'">{{ current.plan.usage.plan.free ? t('freelance.teams.plan_free') : (current.plan.usage.plan.name || current.plan.usage.plan.slug) }}</span>
                        <template v-if="current.plan.subscription">
                            <span class="badge" :class="{ trialing: 'bg-info-transparent', active: 'bg-success-transparent', past_due: 'bg-warning-transparent', expired: 'bg-secondary-transparent' }[current.plan.subscription.status]">{{ t(`freelance.plans.status.${current.plan.subscription.status}`) }}</span>
                            <span class="text-muted fs-12">
                                {{ t('freelance.plans.paid_by') }}: {{ current.plan.subscription.paid_by?.name || '—' }} · {{ t('freelance.plans.renews') }}: {{ formatCatalogDate(current.plan.subscription.period_end, locale) }}
                            </span>
                        </template>
                    </div>
                    <div class="row g-3 fs-13">
                        <div v-for="m in planMeters" :key="m.key" class="col-md-4">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">{{ m.label }}</span>
                                <span class="fw-semibold" dir="ltr">{{ m.used }} / {{ m.max ?? t('freelance.teams.plan_no_cap') }}</span>
                            </div>
                            <div v-if="m.max" class="progress" style="height: 6px" role="progressbar" :aria-valuenow="m.used" aria-valuemin="0" :aria-valuemax="m.max">
                                <div class="progress-bar" :class="m.used >= m.max ? 'bg-danger' : 'bg-primary'" :style="{ width: Math.min(100, Math.round((m.used / m.max) * 100)) + '%' }"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <h6 class="fw-semibold">{{ t('freelance.teams.members') }}</h6>
                <div class="table-responsive">
                    <table class="table table-sm text-nowrap align-middle mb-3">
                        <thead>
                            <tr>
                                <th>{{ t('freelance.teams.person') }}</th>
                                <th>{{ t('freelance.teams.role') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th>{{ t('freelance.teams.parts') }}</th>
                                <th>{{ t('freelance.teams.tasks') }}</th>
                                <th v-if="canUpdate"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in current.members" :key="m.freelancer_id">
                                <td>{{ m.name }} <span class="text-muted fs-11" dir="ltr">{{ m.phone }}</span></td>
                                <td>{{ t(`freelance.teams.roles.${m.role}`) }}</td>
                                <td>
                                    <span class="badge" :class="m.status === 'active' ? 'bg-success-transparent' : 'bg-light text-default'">{{ t(`freelance.teams.member_status.${m.status}`) }}</span>
                                    <span v-if="m.removed_by_staff" class="text-muted fs-11 ms-1">{{ t('freelance.teams.by_staff') }}</span>
                                    <span v-if="m.left_reason" class="text-muted fs-11 ms-1">{{ m.left_reason }}</span>
                                </td>
                                <td>{{ load(m)?.parts_open ?? '—' }}</td>
                                <td>
                                    <template v-if="load(m)">{{ load(m).tasks_open }}<span v-if="load(m).tasks_overdue" class="text-danger"> ({{ load(m).tasks_overdue }} {{ t('freelance.teams.overdue') }})</span></template>
                                    <template v-else>—</template>
                                </td>
                                <td v-if="canUpdate" class="text-end">
                                    <button v-if="m.status === 'active' && removeFor !== m.uuid" type="button" class="btn btn-link btn-sm text-danger p-0" @click="startRemove(m)">{{ t('freelance.teams.remove') }}</button>
                                    <div v-if="removeFor === m.uuid" class="d-flex gap-1">
                                        <input v-model="removeReason" type="text" maxlength="100" class="form-control form-control-sm" :placeholder="t('freelance.teams.remove_reason')">
                                        <button type="button" class="btn btn-sm btn-danger" :disabled="working" @click="removeMember(m)">{{ t('freelance.teams.remove') }}</button>
                                        <button type="button" class="btn btn-sm btn-light" @click="removeFor = null">{{ t('cancel') }}</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold">{{ t('freelance.teams.projects') }} <span class="text-muted fs-12">({{ current.stats.agreements_active }} / {{ current.stats.agreements_completed }})</span></h6>
                        <p v-if="!current.projects.length" class="text-muted fs-13">—</p>
                        <p v-for="p in current.projects" :key="p.uuid" class="fs-12 mb-1">{{ p.project.title }} · <span dir="ltr">{{ money(p.price_minor) }} {{ p.currency }}</span> · {{ p.signed_by }} <span class="badge bg-light text-default">{{ p.status }}</span></p>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="fw-semibold">{{ t('freelance.teams.offers') }} <span class="text-muted fs-12">({{ current.stats.offers }})</span></h6>
                        <p v-if="!current.offers.length" class="text-muted fs-13">—</p>
                        <p v-for="o in current.offers" :key="o.uuid" class="fs-12 mb-1">{{ o.project.title }} · <span dir="ltr">{{ money(o.price_minor) }} {{ o.currency }}</span> <span class="badge bg-light text-default">{{ o.status }}</span></p>
                    </div>
                </div>

                <!-- FS-202b: the team's verification (optional, D4): the papers of each request, approve or reject the open one, or take the badge back. -->
                <div class="border-top mt-4 pt-3">
                    <h6 class="fw-semibold">
                        {{ t('freelance.teams.verification.title') }}
                        <span v-if="current.verification.verified_at" class="badge bg-info-transparent ms-1"><i class="ri-verified-badge-fill me-1"></i>{{ t('freelance.teams.verification.verified') }}</span>
                    </h6>
                    <p v-if="!current.verification.requests.length" class="text-muted fs-13">{{ t('freelance.teams.verification.none') }}</p>
                    <div v-for="r in current.verification.requests" :key="r.uuid" class="border rounded p-2 mb-2">
                        <div class="d-flex flex-wrap align-items-center gap-2 fs-12">
                            <span class="badge" :class="requestBadge(r.status)">{{ t(`freelance.teams.verification.status.${r.status}`) }}</span>
                            <span class="text-muted" dir="ltr">{{ (r.submitted_at || '').slice(0, 16).replace('T', ' ') }}</span>
                            <span v-if="r.reason" class="text-danger">{{ r.reason }}</span>
                        </div>
                        <ul class="list-unstyled fs-12 mb-0 mt-1">
                            <li v-for="d in r.documents" :key="d.uuid">
                                <i class="ri-file-text-line me-1"></i>{{ d.title }}
                                <button v-if="d.file" type="button" class="btn btn-link btn-sm p-0 ms-1" @click="openPaper(d.file)">{{ t('freelance.teams.verification.open_file') }}</button>
                            </li>
                        </ul>
                        <div v-if="canUpdate && r.status === 'submitted'" class="d-flex flex-wrap gap-2 align-items-start mt-2">
                            <input v-model="decisionReason" type="text" maxlength="500" class="form-control form-control-sm w-auto flex-grow-1" :placeholder="t('freelance.teams.verification.reject_reason')">
                            <button type="button" class="btn btn-sm btn-success" :disabled="working" @click="decide('approve')">{{ t('freelance.teams.verification.approve') }}</button>
                            <button type="button" class="btn btn-sm btn-danger" :disabled="working || !decisionReason.trim()" @click="decide('reject')">{{ t('freelance.teams.verification.reject') }}</button>
                        </div>
                    </div>
                    <div v-if="canUpdate && current.verification.verified_at" class="d-flex flex-wrap gap-2 align-items-start mt-2">
                        <input v-model="decisionReason" type="text" maxlength="500" class="form-control form-control-sm w-auto flex-grow-1" :placeholder="t('freelance.teams.verification.revoke_reason')">
                        <button type="button" class="btn btn-sm btn-outline-danger" :disabled="working || !decisionReason.trim()" @click="revoke">{{ t('freelance.teams.verification.revoke') }}</button>
                    </div>
                </div>

                <h6 class="fw-semibold mt-4">{{ t('freelance.teams.audit') }}</h6>
                <p v-if="!current.audit.length" class="text-muted fs-13">{{ t('freelance.teams.no_audit') }}</p>
                <ul v-else class="list-unstyled fs-12 mb-0">
                    <li v-for="(l, n) in current.audit" :key="n" class="mb-1">
                        <span class="text-muted" dir="ltr">{{ (l.at || '').slice(0, 16).replace('T', ' ') }}</span> · {{ l.by }} ·
                        <strong>{{ l.after?.status ?? l.action }}</strong><span v-if="l.after?.reason"> — {{ l.after.reason }}</span>
                    </li>
                </ul>

                <!-- Suspend (a reason is needed and the Owners are told) or bring the team back. -->
                <div v-if="canUpdate && current.status !== 'archived'" class="border-top mt-4 pt-3">
                    <h6 class="fw-semibold">{{ t('freelance.teams.change_status') }}</h6>
                    <div class="d-flex flex-wrap gap-2 align-items-start">
                        <input v-if="current.status === 'active'" v-model="reason" type="text" maxlength="255" class="form-control w-auto flex-grow-1" :placeholder="t('freelance.teams.reason')">
                        <button v-if="current.status === 'active'" type="button" class="btn btn-danger" :disabled="working || !reason.trim()" @click="changeStatus('suspended')">{{ t('freelance.teams.suspend') }}</button>
                        <button v-else type="button" class="btn btn-primary" :disabled="working" @click="changeStatus('active')">{{ t('freelance.teams.reactivate') }}</button>
                    </div>
                </div>
            </div>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from 'primevue/select';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

// The freelancers' teams (FS-208): find a team, open it to see its people, projects and what each member carries, suspend it with a reason (the
// Owners are told) or bring it back, and take a member out. The per-country switch and the limits are on the switches and policies screens.
const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const { can } = usePermission();
const endpoint = '/api/admin/v1/freelance-teams';
const canUpdate = computed(() => can('freelance-teams.update'));

const rows = ref([]);
const pagination = ref({});
const counts = ref({});
const verificationPending = ref(0);
const loading = ref(false);
const countries = ref([]);
const search = ref('');
const statusFilter = ref('all');
const countryFilter = ref('all');
const verificationFilter = ref('all');
const page = ref(1);

const statuses = ['active', 'suspended', 'archived'];
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0));
const statusOptions = computed(() => [
    { value: 'all', label: `${t('countries.filter_all')} (${total.value})` },
    ...statuses.map((s) => ({ value: s, label: `${t(`freelance.teams.status.${s}`)} (${counts.value[s] ?? 0})` })),
]);
const verificationOptions = computed(() => [
    { value: 'all', label: t('freelance.teams.verification.filter_all') },
    { value: 'pending', label: `${t('freelance.teams.verification.waiting')} (${verificationPending.value})` },
    { value: 'verified', label: t('freelance.teams.verification.verified') },
]);
const countryOptions = computed(() => [{ value: 'all', label: t('freelance.document_types.all_countries') }, ...countries.value.map((c) => ({ value: c.id, label: c.name }))]);
const money = (minor) => (minor == null ? '' : (minor / 100).toFixed(2));
const requestBadge = (s) => ({ submitted: 'bg-warning-transparent', approved: 'bg-success-transparent', rejected: 'bg-danger-transparent' }[s] ?? 'bg-light text-default');
const statusBadge = (s) => ({ active: 'bg-success-transparent', suspended: 'bg-warning-transparent', archived: 'bg-light text-default' }[s] ?? 'bg-light text-default');

let timer = null;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => (page.value === 1 ? loadList() : (page.value = 1)), 300); });
watch([statusFilter, countryFilter, verificationFilter], () => (page.value === 1 ? loadList() : (page.value = 1)));
watch(page, loadList);

async function loadList() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(endpoint, {
            params: {
                page: page.value, search: search.value || undefined,
                status: statusFilter.value === 'all' ? undefined : statusFilter.value,
                country_id: countryFilter.value === 'all' ? undefined : countryFilter.value,
                verification: verificationFilter.value === 'all' ? undefined : verificationFilter.value,
            },
        });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
        counts.value = data.pagination?.counts ?? {};
        verificationPending.value = data.pagination?.verification_pending ?? 0;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

// ---- one team
const current = ref(null);
const detailLoading = ref(false);
const reason = ref('');
const working = ref(false);
const removeFor = ref(null);
const removeReason = ref('');
const decisionReason = ref('');

// What the team holds against the room its plan gives (FS-211): people, projects running at once, offers this month.
const planMeters = computed(() => {
    const u = current.value?.plan?.usage;
    if (!u) return [];
    return [
        { key: 'members', label: t('freelance.teams.plan_people'), used: u.members.used, max: u.members.max },
        { key: 'projects', label: t('freelance.teams.plan_projects'), used: u.projects.used, max: u.projects.max },
        { key: 'offers', label: t('freelance.teams.plan_offers'), used: u.offers.used, max: u.offers.limit },
    ];
});

// What a member carries now (parts and tasks on the team's running projects) - only the people still in the team have a row.
const load = (member) => current.value?.workload?.find((w) => w.freelancer.uuid === member.uuid) ?? null;

async function open(row) {
    current.value = { name: row.name, status: row.status };
    detailLoading.value = true;
    reason.value = '';
    decisionReason.value = '';
    removeFor.value = null;
    await reloadCurrent(row.uuid);
}

async function reloadCurrent(uuid) {
    try {
        const { data } = await adminAxios.get(`${endpoint}/${uuid}`);
        current.value = data.data;
    } catch (error) {
        current.value = null;
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        detailLoading.value = false;
    }
}

function close() {
    removeFor.value = null;
    current.value = null;
}

// A team's paper is private: fetched with the admin's token and opened from memory (opening it is audited on the server).
async function openPaper(file) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/${file.path}`, { responseType: 'blob' });
        window.open(URL.createObjectURL(data), '_blank', 'noopener');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function decide(decision) {
    working.value = true;
    try {
        await adminAxios.post(`${endpoint}/${current.value.uuid}/verification/decide`, { decision, reason: decisionReason.value.trim() || undefined });
        showSuccess(t('freelance.teams.saved'));
        decisionReason.value = '';
        await reloadCurrent(current.value.uuid);
        loadList();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

async function revoke() {
    working.value = true;
    try {
        await adminAxios.post(`${endpoint}/${current.value.uuid}/verification/revoke`, { reason: decisionReason.value.trim() });
        showSuccess(t('freelance.teams.saved'));
        decisionReason.value = '';
        await reloadCurrent(current.value.uuid);
        loadList();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

function startRemove(member) {
    removeFor.value = member.uuid;
    removeReason.value = '';
}

async function changeStatus(status) {
    working.value = true;
    try {
        await adminAxios.post(`${endpoint}/${current.value.uuid}/status`, { status, reason: reason.value.trim() || undefined });
        showSuccess(t('freelance.teams.saved'));
        reason.value = '';
        await reloadCurrent(current.value.uuid);
        loadList();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

async function removeMember(member) {
    working.value = true;
    try {
        await adminAxios.post(`${endpoint}/${current.value.uuid}/members/${member.uuid}/remove`, { reason: removeReason.value.trim() || undefined });
        removeFor.value = null;
        showSuccess(t('freelance.teams.saved'));
        await reloadCurrent(current.value.uuid);
        loadList();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await adminAxios.get(`${endpoint}/options`);
        countries.value = data.data?.countries ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
    loadList();
});
</script>
