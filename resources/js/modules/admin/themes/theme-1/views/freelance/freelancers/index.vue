<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.freelancers.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.freelancers.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.freelancers.search')">
                    </div>
                    <Select v-model="statusFilter" :options="statusOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                    <Select v-model="countryFilter" :options="countryOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" class="wallet-filter-select" />
                    <Select v-model="specialtyFilter" :options="specialtyOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" class="wallet-filter-select" />
                    <Select v-model="riskFilter" :options="riskOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.freelancers.freelancer') }}</th>
                                <th>{{ t('freelance.freelancers.country') }}</th>
                                <th>{{ t('freelance.freelancers.specialties') }}</th>
                                <th>{{ t('freelance.freelancers.offers') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th>{{ t('freelance.sanctions.quality') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="5" :layout="['two-lines', 'two-lines', 'text', 'text', 'badge', 'badge', 'actions']" />
                            <tr v-else-if="!rows.length"><td colspan="7" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.id">
                                <td class="ps-4">
                                    <span class="d-block fw-semibold">{{ row.name || '—' }}</span>
                                    <span class="text-muted fs-11" dir="ltr">{{ row.phone }}</span>
                                </td>
                                <td class="fs-12">{{ row.country }}</td>
                                <td class="fs-12">{{ row.specialties.join('، ') || '—' }}</td>
                                <td>{{ row.offers_count }}</td>
                                <td><span class="badge" :class="statusBadge(row.status)">{{ t(`freelance.freelancers.status.${row.status}`) }}</span></td>
                                <td>
                                    <span v-if="row.risk_level" class="badge" :class="riskBadge(row.risk_level)">{{ t(`freelance.sanctions.risk.${row.risk_level}`) }}</span>
                                    <span class="text-muted fs-11 ms-1">{{ row.quality_score ?? '—' }}</span>
                                </td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-light btn-wave" @click="open(row)"><i class="ri-eye-line me-1"></i>{{ t('freelance.freelancers.open') }}</button>
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

        <!-- One freelancer: everything staff need to decide about the account. -->
        <WalletModal :show="!!current" :title="current?.name || t('freelance.freelancers.freelancer')" size="xl" @close="close">
            <div v-if="detailLoading" class="text-center text-muted py-5">{{ t('flags.loading') }}</div>
            <div v-else-if="current">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge" :class="statusBadge(current.status)">{{ t(`freelance.freelancers.status.${current.status}`) }}</span>
                    <span class="text-muted fs-12" dir="ltr">{{ current.phone }}<span v-if="current.email"> · {{ current.email }}</span></span>
                    <span class="text-muted fs-12">{{ current.country }}</span>
                    <span v-if="current.status_reason" class="text-danger fs-12">{{ current.status_reason }}</span>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold">{{ t('freelance.freelancers.profile') }}</h6>
                        <p class="mb-1 fw-semibold">{{ current.profile.headline || '—' }}</p>
                        <p class="text-muted fs-13 text-wrap">{{ current.profile.bio || '—' }}</p>
                        <p class="fs-12 mb-1">{{ t('freelance.freelancers.specialties') }}: <span v-for="sp in current.profile.specialties" :key="sp.id" class="badge bg-light text-default me-1">{{ sp.name }}<i v-if="sp.primary" class="ri-star-fill ms-1"></i></span></p>
                        <p class="fs-12 mb-1">{{ t('freelance.freelancers.skills') }}: {{ current.profile.skills.join('، ') || '—' }}</p>
                        <p class="fs-12 mb-0">{{ t('freelance.freelancers.years') }}: {{ current.profile.years_of_experience ?? '—' }}</p>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="fw-semibold">{{ t('freelance.freelancers.verification') }}</h6>
                        <template v-if="current.verification">
                            <span class="badge bg-info-transparent">{{ current.verification.status }}</span>
                            <span class="text-muted fs-12 ms-2">{{ t('freelance.freelancers.documents', { n: current.verification.documents_count }) }}</span>
                            <p v-if="current.verification.reason" class="text-muted fs-12 mb-1">{{ current.verification.reason }}</p>
                        </template>
                        <p v-else class="text-muted fs-13 mb-1">{{ t('freelance.freelancers.no_verification') }}</p>
                        <router-link :to="{ name: 'admin.freelance.verifications' }" class="fs-12">{{ t('freelance.freelancers.open_verifications') }}</router-link>

                        <h6 class="fw-semibold mt-3">{{ t('freelance.freelancers.wallet') }}</h6>
                        <p v-if="!current.wallets.length" class="text-muted fs-13 mb-0">—</p>
                        <p v-for="w in current.wallets" :key="w.country" class="fs-13 mb-1" dir="ltr">
                            {{ w.country }} · {{ money(w.withdrawable_minor) }} {{ w.currency }}
                            <span class="text-muted">(+{{ money(w.spend_only_minor) }} / {{ t('freelance.freelancers.held') }} {{ money(w.held_minor) }})</span>
                        </p>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-lg-4">
                        <h6 class="fw-semibold">{{ t('freelance.freelancers.portfolio') }}</h6>
                        <p v-if="!current.portfolio.length" class="text-muted fs-13">—</p>
                        <div v-for="i in current.portfolio" :key="i.id" class="fs-13 mb-2">
                            <span>{{ i.title }}</span> <span v-if="i.hidden" class="badge bg-danger-transparent fs-10" :title="i.hidden_reason">{{ t('freelance.freelancers.hidden') }}</span>
                            <template v-if="canUpdate">
                                <button v-if="i.hidden" type="button" class="btn btn-link btn-sm p-0 ms-2" :disabled="working" @click="unhide(i)">{{ t('freelance.freelancers.unhide') }}</button>
                                <button v-else-if="hideFor !== i.id" type="button" class="btn btn-link btn-sm text-danger p-0 ms-2" @click="startHide(i)">{{ t('freelance.freelancers.hide') }}</button>
                            </template>
                            <div v-if="hideFor === i.id" class="d-flex gap-1 mt-1">
                                <input v-model="hideReason" type="text" maxlength="255" class="form-control form-control-sm" :placeholder="t('freelance.freelancers.hide_reason')">
                                <button type="button" class="btn btn-sm btn-danger" :disabled="working || !hideReason.trim()" @click="hide(i)">{{ t('freelance.freelancers.hide') }}</button>
                                <button type="button" class="btn btn-sm btn-light" @click="hideFor = null">{{ t('cancel') }}</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <h6 class="fw-semibold">{{ t('freelance.freelancers.offers') }} <span class="text-muted fs-12">({{ current.stats.offers }})</span></h6>
                        <p v-if="!current.offers.length" class="text-muted fs-13">—</p>
                        <p v-for="o in current.offers" :key="o.uuid" class="fs-12 mb-1">{{ o.project.title }} · <span dir="ltr">{{ money(o.price_minor) }} {{ o.currency }}</span> <span class="badge bg-light text-default">{{ o.status }}</span></p>
                    </div>
                    <div class="col-lg-4">
                        <h6 class="fw-semibold">{{ t('freelance.freelancers.projects') }} <span class="text-muted fs-12">({{ current.stats.agreements_active }} / {{ current.stats.agreements_completed }})</span></h6>
                        <p v-if="!current.projects.length" class="text-muted fs-13">—</p>
                        <p v-for="p in current.projects" :key="p.uuid" class="fs-12 mb-1">{{ p.project.title }} · <span dir="ltr">{{ money(p.price_minor) }} {{ p.currency }}</span> <span class="badge bg-light text-default">{{ p.status }}</span></p>
                    </div>
                </div>

                <!-- FL-707 / FL-708: quality, risk and sanctions of this freelancer. -->
                <div v-if="can('freelance-sanctions.view')" class="border-top mt-4 pt-3">
                    <h6 class="fw-semibold">{{ t('freelance.sanctions.title') }}</h6>
                    <PersonPanel side="freelancer" :id="current.id" @changed="load" />
                </div>

                <h6 class="fw-semibold mt-4">{{ t('freelance.freelancers.audit') }}</h6>
                <p v-if="!current.audit.length" class="text-muted fs-13">{{ t('freelance.freelancers.no_audit') }}</p>
                <ul v-else class="list-unstyled fs-12 mb-0">
                    <li v-for="(l, n) in current.audit" :key="n" class="mb-1">
                        <span class="text-muted" dir="ltr">{{ (l.at || '').slice(0, 16).replace('T', ' ') }}</span> · {{ l.by }} ·
                        <strong>{{ l.after?.status ?? l.action }}</strong><span v-if="l.after?.reason"> — {{ l.after.reason }}</span>
                    </li>
                </ul>

                <!-- Suspend, block or restore; a reason is needed for the first two and is kept with who did it. -->
                <div v-if="canUpdate" class="border-top mt-4 pt-3">
                    <h6 class="fw-semibold">{{ t('freelance.freelancers.change_status') }}</h6>
                    <div class="d-flex flex-wrap gap-2 align-items-start">
                        <Select v-model="newStatus" :options="settable" option-label="label" option-value="value" class="wallet-filter-select" append-to="body" />
                        <input v-model="reason" type="text" maxlength="255" class="form-control w-auto flex-grow-1" :placeholder="t('freelance.freelancers.reason')">
                        <button type="button" class="btn btn-primary" :disabled="working || !newStatus || (needsReason && !reason.trim())" @click="changeStatus">{{ t('freelance.freelancers.save') }}</button>
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
import PersonPanel from '../sanctions/PersonPanel.vue';

// The freelancers (FL-109): find them, open one to see everything about the account, and suspend / block / restore it with a reason.
// Approving or rejecting the documents is the verification desk's job (FL-108).
const { t } = useI18n();
const { showSuccess, showError } = useToast();
const { can } = usePermission();
const endpoint = '/api/admin/v1/freelancers';
const canUpdate = computed(() => can('freelancers.update'));

const rows = ref([]);
const pagination = ref({});
const counts = ref({});
const loading = ref(false);
const countries = ref([]);
const specialties = ref([]);
const search = ref('');
const statusFilter = ref('all');
const countryFilter = ref('all');
const specialtyFilter = ref('all');
const riskFilter = ref('all');
const riskOptions = computed(() => [{ value: 'all', label: t('freelance.sanctions.all_risks') }, ...['high', 'medium', 'low'].map((r) => ({ value: r, label: t(`freelance.sanctions.risk.${r}`) }))]);
const riskBadge = (level) => ({ high: 'bg-danger-transparent', medium: 'bg-warning-transparent', low: 'bg-success-transparent' }[level] ?? 'bg-light text-default');
const page = ref(1);

const statuses = ['registered', 'profile_ready', 'verification_submitted', 'verified', 'verification_rejected', 'changes_requested', 'suspended', 'blocked'];
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0));
const statusOptions = computed(() => [
    { value: 'all', label: `${t('countries.filter_all')} (${total.value})` },
    ...statuses.map((s) => ({ value: s, label: `${t(`freelance.freelancers.status.${s}`)} (${counts.value[s] ?? 0})` })),
]);
const countryOptions = computed(() => [{ value: 'all', label: t('freelance.document_types.all_countries') }, ...countries.value.map((c) => ({ value: c.id, label: c.name }))]);
const specialtyOptions = computed(() => [{ value: 'all', label: t('freelance.projects.all_specialties') }, ...specialties.value.map((s) => ({ value: s.id, label: s.name }))]);
const money = (minor) => (minor == null ? '' : (minor / 100).toFixed(2));
const statusBadge = (s) => ({
    verified: 'bg-success-transparent', suspended: 'bg-warning-transparent', blocked: 'bg-danger-transparent', verification_submitted: 'bg-info-transparent',
    verification_rejected: 'bg-danger-transparent', changes_requested: 'bg-warning-transparent',
}[s] ?? 'bg-light text-default');

let timer = null;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => (page.value === 1 ? load() : (page.value = 1)), 300); });
watch([statusFilter, countryFilter, specialtyFilter, riskFilter], () => (page.value === 1 ? load() : (page.value = 1)));
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
                risk: riskFilter.value === 'all' ? undefined : riskFilter.value,
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

// ---- one freelancer
const current = ref(null);
const detailLoading = ref(false);
const newStatus = ref('');
const reason = ref('');
const working = ref(false);
const settable = computed(() => ['verified', 'profile_ready', 'suspended', 'blocked'].map((s) => ({ value: s, label: t(`freelance.freelancers.status.${s}`) })));
const needsReason = computed(() => ['suspended', 'blocked'].includes(newStatus.value));

async function open(row) {
    current.value = { name: row.name, status: row.status };
    detailLoading.value = true;
    newStatus.value = '';
    reason.value = '';
    await reloadCurrent(row.id);
}

async function reloadCurrent(id) {
    try {
        const { data } = await adminAxios.get(`${endpoint}/${id}`);
        current.value = data.data;
    } catch (error) {
        current.value = null;
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        detailLoading.value = false;
    }
}

// A work that breaks the rules leaves every other view and its owner is told why; staff may bring it back.
const hideFor = ref(null);
const hideReason = ref('');

function startHide(item) {
    hideFor.value = item.id;
    hideReason.value = '';
}

async function portfolioAction(item, action, body = {}) {
    working.value = true;
    try {
        await adminAxios.post(`${endpoint}/${current.value.id}/portfolio/${item.id}/${action}`, body);
        hideFor.value = null;
        showSuccess(t('freelance.freelancers.saved'));
        await reloadCurrent(current.value.id);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

const hide = (item) => portfolioAction(item, 'hide', { reason: hideReason.value.trim() });
const unhide = (item) => portfolioAction(item, 'unhide');

function close() {
    hideFor.value = null;
    current.value = null;
}

async function changeStatus() {
    working.value = true;
    try {
        await adminAxios.post(`${endpoint}/${current.value.id}/status`, { status: newStatus.value, reason: reason.value.trim() || undefined });
        showSuccess(t('freelance.freelancers.saved'));
        newStatus.value = '';
        reason.value = '';
        await reloadCurrent(current.value.id);
        load();
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
        specialties.value = data.data?.specialties ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
    load();
});
</script>
