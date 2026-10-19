<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('discover.organizers.title') }}
                <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">
                    {{ pagination.total }}
                </span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item">{{ t('sidebar.discover') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('discover.organizers.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <div class="d-flex flex-wrap align-items-center gap-1 catalog-toolbar-filters">
                            <div class="input-group input-group-sm catalog-toolbar-search">
                                <span class="input-group-text bg-white">
                                    <i class="ri-search-line text-muted"></i>
                                </span>
                                <input
                                    v-model="search"
                                    type="search"
                                    class="form-control"
                                    :placeholder="t('discover.organizers.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('countries.clear_search')"
                                    @click="clearSearch"
                                >
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button
                                v-if="showAllFilter"
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="filterClass('all')"
                                @click="setStatusFilter('all')"
                            >
                                {{ t('discover.organizers.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="filterClass('pending')"
                                @click="setStatusFilter('pending')"
                            >
                                {{ t('discover.organizers.s_pending') }} ({{ counts.pending }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="filterClass('verified')"
                                @click="setStatusFilter('verified')"
                            >
                                {{ t('discover.organizers.s_verified') }} ({{ counts.verified }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="filterClass('rejected')"
                                @click="setStatusFilter('rejected')"
                            >
                                {{ t('discover.organizers.s_rejected') }} ({{ counts.rejected }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="filterClass('suspended')"
                                @click="setStatusFilter('suspended')"
                            >
                                {{ t('discover.organizers.s_suspended') }} ({{ counts.suspended }})
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col" class="ps-4">{{ t('discover.organizers.name') }}</th>
                                        <th scope="col">{{ t('discover.organizers.account') }}</th>
                                        <th scope="col">{{ t('discover.organizers.contact') }}</th>
                                        <th scope="col">{{ t('discover.organizers.events') }}</th>
                                        <th scope="col">{{ t('wallet.common.status') }}</th>
                                        <th scope="col">{{ t('countries.created_at') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('countries.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :layout="skeletonLayout" />

                                    <tr v-else-if="!rows.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-user-star-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('discover.organizers.empty_title') }}</p>
                                                <p class="text-muted mb-0">{{ t('discover.organizers.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr v-for="row in rows" :key="row.id" class="crm-contact">
                                            <td class="ps-4">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="avatar avatar-md avatar-rounded bg-primary-transparent">
                                                        {{ initials(row.name) }}
                                                    </span>
                                                    <div>
                                                        <button
                                                            type="button"
                                                            class="btn btn-link p-0 text-start fw-semibold text-default"
                                                            @click="openDetails(row)"
                                                        >
                                                            {{ row.name }}
                                                            <i v-if="row.status === 'verified'" class="ri-verified-badge-fill text-primary ms-1"></i>
                                                        </button>
                                                        <span class="d-block text-muted fs-11">#{{ row.id }}</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ row.account?.name || '—' }}</span>
                                                <span v-if="row.account?.phone" class="d-block text-muted fs-11" dir="ltr">{{ row.account.phone }}</span>
                                            </td>
                                            <td>
                                                <span v-if="row.email" class="d-block fs-13" dir="ltr">{{ row.email }}</span>
                                                <span v-if="row.phone" class="d-block text-muted fs-11" dir="ltr">{{ row.phone }}</span>
                                                <span v-if="! row.email && ! row.phone">—</span>
                                            </td>
                                            <td>
                                                <router-link :to="{ name: 'admin.discover.events' }" class="badge bg-info-transparent">
                                                    {{ row.events_count }}
                                                </router-link>
                                            </td>
                                            <td>
                                                <select
                                                    v-if="canUpdate"
                                                    class="form-select form-select-sm w-auto catalog-status-select"
                                                    :class="statusSelectClass(row.status)"
                                                    :value="row.status"
                                                    :disabled="isTogglingStatus(row.id)"
                                                    @change="changeOrganizerStatus(row, $event.target.value)"
                                                >
                                                    <option
                                                        v-for="(label, value) in statusOptions"
                                                        :key="value"
                                                        :value="value"
                                                    >
                                                        {{ label }}
                                                    </option>
                                                </select>
                                                <span
                                                    v-else
                                                    class="badge"
                                                    :class="statusBadge(row.status)"
                                                >
                                                    {{ t(`discover.organizers.s_${row.status}`) }}
                                                </span>
                                                <span v-if="row.review_note" class="d-block text-muted fs-11 text-truncate" style="max-width: 160px;" :title="row.review_note">
                                                    {{ row.review_note }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatCatalogDate(row.created_at, locale) }}</span>
                                                <span v-if="row.updated_at" class="d-block text-muted fs-11">
                                                    {{ t('countries.updated') }}: {{ formatCatalogDate(row.updated_at, locale) }}
                                                </span>
                                            </td>
                                            <td v-if="showActionsColumn" class="text-end pe-4">
                                                <div class="btn-list justify-content-end">
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-primary-light btn-icon"
                                                        :title="t('discover.organizers.details')"
                                                        @click="openDetails(row)"
                                                    >
                                                        <i class="ri-eye-line"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="pagination && !loading" class="card-footer border-top-0">
                        <div class="d-flex align-items-center flex-wrap gap-3">
                            <div class="d-flex align-items-center gap-2 text-muted fs-13">
                                <span>{{ entriesLabel }}</span>
                                <i :class="paginationArrowIcon"></i>
                            </div>
                            <div class="d-flex align-items-center gap-2 ms-md-auto">
                                <label class="text-muted fs-13 mb-0" for="organizers-per-page">{{ t('countries.per_page') }}</label>
                                <select id="organizers-per-page" v-model.number="perPage" class="form-select form-select-sm w-auto">
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>
                            <nav aria-label="Organizers pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('countries.previous') }}
                                        </button>
                                    </li>
                                    <li
                                        v-for="page in pageNumbers"
                                        :key="page"
                                        class="page-item"
                                        :class="{ active: page === currentPage }"
                                    >
                                        <button type="button" class="page-link" @click="changePage(page)">{{ page }}</button>
                                    </li>
                                    <li class="page-item" :class="{ disabled: !pagination.next_page_url }">
                                        <button type="button" class="page-link text-primary" @click="changePage(currentPage + 1)">
                                            {{ t('countries.next') }}
                                        </button>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ModalShow
            :show="showDetails"
            :record="selectedRecord"
            :can-update="canUpdate"
            @close="showDetails = false"
            @review="openReview"
        />

        <WalletModal :show="!!reviewRow" :title="reviewTitle" size="md" @close="closeReview">
            <p class="text-muted fs-13 mb-3">{{ reviewMessage }}</p>
            <div class="mb-0">
                <label for="organizer-review-note" class="form-label">{{ t('discover.organizers.note') }}</label>
                <div class="input-group">
                    <span class="input-group-text bg-light align-self-start">
                        <i class="ri-chat-quote-line"></i>
                    </span>
                    <textarea
                        id="organizer-review-note"
                        v-model="reviewNote"
                        rows="3"
                        maxlength="500"
                        class="form-control"
                        :placeholder="t('discover.organizers.note_placeholder')"
                    ></textarea>
                </div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="reviewing" @click="closeReview">{{ t('close') }}</button>
                <button type="button" class="btn btn-primary btn-wave" :disabled="reviewing" @click="submitReview">
                    <span v-if="reviewing" class="spinner-border spinner-border-sm me-1"></span>
                    {{ t('save_changes') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatCatalogDate } from '../../../../../../../utils/catalog';
import ModalShow from './ModalShow.vue';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const canView = computed(() => can('discover-organizers.view'));
const canUpdate = computed(() => can('discover-organizers.update'));
const showActionsColumn = computed(() => canView.value || canUpdate.value);
const tableColumnCount = computed(() => (showActionsColumn.value ? 7 : 6));
const statusOptions = computed(() => ({
    pending: t('discover.organizers.s_pending'),
    verified: t('discover.organizers.s_verified'),
    rejected: t('discover.organizers.s_rejected'),
    suspended: t('discover.organizers.s_suspended'),
}));
const skeletonLayout = computed(() => [
    'avatar-text',
    'two-lines',
    'two-lines',
    'badge',
    'text',
    'two-lines',
    ...(showActionsColumn.value ? ['actions'] : []),
]);
const togglingStatusIds = ref([]);

const rows = ref([]);
const pagination = ref(null);
const loading = ref(false);
const search = ref('');
const statusFilter = ref('all');
const showAllFilter = computed(() => statusFilter.value !== 'all');
const currentPage = ref(1);
const perPage = ref(15);
const counts = ref({ total: 0, pending: 0, verified: 0, rejected: 0, suspended: 0 });
const showDetails = ref(false);
const selectedRecord = ref(null);
const reviewRow = ref(null);
const reviewStatus = ref(null);
const reviewNote = ref('');
const reviewing = ref(false);

const filterTone = {
    all: 'all',
    pending: 'pending',
    verified: 'active',
    rejected: 'deleted',
    suspended: 'inactive',
};

const entriesLabel = computed(() => t('countries.showing_entries', {
    from: pagination.value?.from ?? 0,
    to: pagination.value?.to ?? 0,
    total: pagination.value?.total ?? 0,
}));

const paginationArrowIcon = computed(() => (
    locale.value === 'ar' ? 'ri-arrow-left-s-line fw-semibold' : 'ri-arrow-right-s-line fw-semibold'
));

const pageNumbers = computed(() => {
    const total = pagination.value?.last_page || 0;
    if (! total) {
        return [];
    }

    const current = currentPage.value;
    const pages = [];
    let start = Math.max(1, current - 2);
    const end = Math.min(total, start + 4);
    start = Math.max(1, end - 4);

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
});

const reviewTitle = computed(() => {
    const map = {
        verified: t('discover.organizers.verify'),
        rejected: t('discover.organizers.reject'),
        suspended: t('discover.organizers.suspend'),
        pending: t('discover.organizers.reopen'),
    };

    return map[reviewStatus.value] || t('discover.organizers.review');
});

const reviewMessage = computed(() => {
    const name = reviewRow.value?.name || '';
    const map = {
        verified: t('discover.organizers.confirm_verify', { name }),
        rejected: t('discover.organizers.confirm_reject', { name }),
        suspended: t('discover.organizers.confirm_suspend', { name }),
        pending: t('discover.organizers.confirm_reopen', { name }),
    };

    return map[reviewStatus.value] || '';
});

let searchTimer = null;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(1), 350);
});

watch(perPage, () => load(1));

function filterClass(key) {
    const tone = filterTone[key];

    return statusFilter.value === key ? `catalog-filter-btn--${tone}` : `catalog-filter-btn--${tone}-idle`;
}

function statusBadge(status) {
    return {
        pending: 'bg-warning-transparent',
        verified: 'bg-success-transparent',
        rejected: 'bg-danger-transparent',
        suspended: 'bg-secondary-transparent',
    }[status] || 'bg-light';
}

function statusSelectClass(status) {
    return {
        pending: 'catalog-status-select--pending',
        verified: 'catalog-status-select--verified',
        rejected: 'catalog-status-select--rejected',
        suspended: 'catalog-status-select--suspended',
    }[status] || '';
}

function isTogglingStatus(id) {
    return togglingStatusIds.value.includes(id);
}

async function changeOrganizerStatus(row, status) {
    if (! row?.id || row.status === status || isTogglingStatus(row.id)) {
        return;
    }

    const previousStatus = row.status;
    row.status = status;
    togglingStatusIds.value = [...togglingStatusIds.value, row.id];

    try {
        const response = await adminAxios.patch(`/api/admin/v1/discover-organizers/${row.id}/review`, {
            status,
            note: row.review_note || null,
        });
        showSuccess(extractApiMessage(response, t('toast.status_changed')));
        if (selectedRecord.value?.id === row.id) {
            selectedRecord.value = response.data?.data ?? selectedRecord.value;
        }
        await load();
    } catch (error) {
        row.status = previousStatus;
        showError(extractApiErrorMessage(error));
    } finally {
        togglingStatusIds.value = togglingStatusIds.value.filter((id) => id !== row.id);
    }
}

function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);

    return (parts.slice(0, 2).map((part) => part[0]).join('') || '؟').toUpperCase();
}

function setStatusFilter(value) {
    statusFilter.value = value;
    load(1);
}

function clearSearch() {
    search.value = '';
}

function changePage(page) {
    if (page < 1 || page > (pagination.value?.last_page || 1)) {
        return;
    }

    load(page);
}

async function openDetails(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/discover-organizers/${row.id}`);
        selectedRecord.value = data.data;
        showDetails.value = true;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function openReview(row, status) {
    showDetails.value = false;
    await nextTick();
    reviewRow.value = row;
    reviewStatus.value = status;
    reviewNote.value = row.review_note || '';
}

function closeReview() {
    reviewRow.value = null;
    reviewStatus.value = null;
    reviewNote.value = '';
}

async function submitReview() {
    if (! reviewRow.value || ! reviewStatus.value || reviewing.value) {
        return;
    }

    reviewing.value = true;

    try {
        const response = await adminAxios.patch(`/api/admin/v1/discover-organizers/${reviewRow.value.id}/review`, {
            status: reviewStatus.value,
            note: reviewNote.value.trim() || null,
        });
        showSuccess(extractApiMessage(response, t('toast.updated')));
        if (selectedRecord.value?.id === reviewRow.value.id) {
            selectedRecord.value = response.data?.data ?? selectedRecord.value;
        }
        closeReview();
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        reviewing.value = false;
    }
}

async function load(page = currentPage.value) {
    loading.value = true;

    try {
        const params = {
            page,
            per_page: perPage.value,
            status_counts: 1,
        };
        if (statusFilter.value !== 'all') {
            params.status = statusFilter.value;
        }
        if (search.value.trim()) {
            params.search = search.value.trim();
        }

        const { data } = await adminAxios.get('/api/admin/v1/discover-organizers', { params });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? null;
        currentPage.value = pagination.value?.current_page ?? page;
        counts.value = data.status_counts ?? counts.value;
        if (pagination.value?.last_page && currentPage.value > pagination.value.last_page) {
            await load(pagination.value.last_page);
        }
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());
</script>
